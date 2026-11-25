<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A request authenticated by a stateless credential (an API signature, a bearer token, a plugin's `user/auth/request`
 * hook) holds its user in memory for that request: it never writes the session, never ends or replaces the session
 * the request carried, never makes the browser's own later requests skip the form token, and is never an
 * administrator. `/_test/whoami` (Tests\Fixtures\WhoAmIController) says who Symfony Security thinks is making the request.
 */
final class StatelessAuthenticationTest extends PrivateSiteTestCase
{
    use LogsIn;

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_AUTH_HOOK' => '1'];
    }

    protected function siteIni(): array
    {
        // the session files of this site are in a directory of the test's own: the shared one is written by every process of the machine
        return ['session.save_path' => $this->sessionDirectory()];
    }

    /**
     * @return array<string, mixed>
     */
    private function whoAmI(array $headers = []): array
    {
        $response = $this->site()->request('GET', '_test/whoami', $headers);
        $this->assertSame(200, $response->status, $this->serverLog());

        return $response->json();
    }

    private function twoUsers(): array
    {
        $adminCookie = $this->registerUser('root');
        $this->addUser('ada');

        return [$adminCookie, $this->apiKeyOf('ada')];
    }

    public function testASignedRequestIsTheSignedUserAndStatelessAndNeverAnAdministrator(): void
    {
        $this->registerUser('root');
        $this->addUser('ada');
        $this->addUser('boss', 'correct-horse-battery-9', null, true);

        $member = $this->whoAmI(self::signedHeadersFor('ada', $this->apiKeyOf('ada'), '/_test/whoami'));
        $admin = $this->whoAmI(self::signedHeadersFor('boss', $this->apiKeyOf('boss'), '/_test/whoami'));

        $this->assertSame(['ROLE_USER'], $member['roles']);
        $this->assertSame('ada', $member['handle']);
        $this->assertTrue($member['stateless']);
        $this->assertSame(['ROLE_USER'], $admin['roles'], 'an administrator authenticated by a signature is not an administrator');
        $this->assertSame('boss', $admin['handle']);
        $this->assertTrue($admin['stateless']);
        $this->assertSame(403, $this->site()->request('GET', 'admin/', self::signedHeadersFor('boss', $this->apiKeyOf('boss'), '/admin/'))->status);
    }

    public function testABrowserSessionIsTheSessionUserAndNotStateless(): void
    {
        $cookie = $this->registerUser('root');

        $me = $this->whoAmI(['Cookie' => $cookie]);

        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $me['roles']);
        $this->assertFalse($me['stateless']);
        $this->assertSame(['uuid' => null, 'roles' => [], 'handle' => null, 'stateless' => false], $this->whoAmI());
    }

    public function testASignedRequestThatCarriesABrowsersSessionActsAsTheSignedUserAndLeavesThatSessionIntact(): void
    {
        [$adminCookie, $key] = $this->twoUsers();

        $signed = $this->whoAmI(self::signedHeadersFor('ada', $key, '/_test/whoami') + ['Cookie' => $adminCookie]);
        $this->assertSame('ada', $signed['handle'], 'the request is the signed user, not the session\'s');
        $this->assertSame(['ROLE_USER'], $signed['roles']);

        $after = $this->whoAmI(['Cookie' => $adminCookie]);
        $this->assertSame('root', $after['handle'], 'the browser session is still the administrator\'s');
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $after['roles']);
        $this->assertSame(200, $this->site()->request('GET', 'admin/', ['Cookie' => $adminCookie])->status);
    }

    public function testASignedRequestWithoutACookieLeavesNoLoginBehind(): void
    {
        [, $key] = $this->twoUsers();

        $response = $this->site()->request('GET', 'currentUser?_t=json', self::signedHeadersFor('ada', $key, '/currentUser?_t=json'));

        $this->assertSame(200, $response->status);
        $name = (string) \Idno\Core\Idno::site()->config()->sessionname;
        preg_match_all('/' . preg_quote($name, '/') . '=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m);
        foreach ($m[1] as $value) {
            $this->assertNull($this->whoAmI(['Cookie' => $name . '=' . $value])['uuid'], 'a session cookie the request set is nobody');
        }
    }

    public function testASignedRequestDoesNotMakeALaterBrowserRequestSkipTheFormToken(): void
    {
        [$adminCookie, $key] = $this->twoUsers();
        $this->site()->request('GET', 'currentUser?_t=json', self::signedHeadersFor('ada', $key, '/currentUser?_t=json') + ['Cookie' => $adminCookie]);

        $marker = 'no token ' . bin2hex(random_bytes(4));
        $this->site()->request('POST', 'status/edit/', ['Cookie' => $adminCookie], ['body' => $marker, 'access' => 'PUBLIC']);

        $this->assertNull($this->runInSite('$s = \IdnoPlugins\Status\Status::getOne([\'body\' => ' . $this->literal($marker) . ']); return $s ? $s->getUUID() : null;'), 'a browser post with no token publishes nothing');
    }

    public function testAWrongSignatureIsAnonymous(): void
    {
        [, $key] = $this->twoUsers();
        $headers = self::signedHeadersFor('ada', $key, '/_test/whoami');
        $headers['X-IDNO-SIGNATURE'] = base64_encode('wrong');

        $this->assertNull($this->whoAmI($headers)['uuid']);
        $this->assertFalse($this->whoAmI($headers)['stateless']);
    }

    public function testAWrongOrMissingBearerTokenIsAnonymousEverywhereButTheMicropubEndpoint(): void
    {
        $this->registerUser('root');

        $this->assertNull($this->whoAmI(['Authorization' => 'Bearer not-a-token'])['uuid']);
        $this->assertSame(401, $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer not-a-token'], ['h' => 'entry', 'content' => 'x'])->status);
    }

    public function testABearerTokenAuthenticatesTheMicropubEndpointOnlyAndAnAdminsKeyIsNoAdminThere(): void
    {
        $cookie = $this->registerUser('root');
        $key = $this->apiKeyOf('root');

        // The first administrator's API key is a bearer token for the Micropub endpoint.
        $marker = 'by bearer ' . bin2hex(random_bytes(4));
        $post = $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer ' . $key], ['h' => 'entry', 'content' => $marker]);
        $this->assertContains($post->status, [200, 201, 202], $post->body . $this->serverLog());
        $this->assertNotNull($this->runInSite('$s = \IdnoPlugins\Status\Status::getOne([\'body\' => ' . $this->literal($marker) . ']); return $s ? $s->getUUID() : null;'));

        // Nowhere else does the header mean anything to Symfony Security or to the legacy session.
        $this->assertNull($this->whoAmI(['Authorization' => 'Bearer ' . $key])['uuid']);
        $this->assertSame(403, $this->site()->request('GET', 'admin/', ['Authorization' => 'Bearer ' . $key])->status);
        $this->assertSame('root', $this->whoAmI(['Cookie' => $cookie, 'Authorization' => 'Bearer ' . $key])['handle'], 'a header on a page that does not take it changes nobody');
    }

    public function testMicropubWithBearerWorksAndABrowserSessionIsRefused(): void
    {
        $cookie = $this->registerUser('root');
        $key = $this->apiKeyOf('root');

        $refused = $this->site()->request('POST', 'micropub/endpoint', ['Cookie' => $cookie], ['h' => 'entry', 'content' => 'by session']);
        $this->assertSame(401, $refused->status, 'a browser session alone is no Micropub credential');

        $accepted = $this->site()->request('POST', 'micropub/endpoint', ['Cookie' => $cookie, 'Authorization' => 'Bearer ' . $key], ['h' => 'entry', 'content' => 'by bearer ' . bin2hex(random_bytes(3))]);
        $this->assertContains($accepted->status, [200, 201, 202], $accepted->body);
        $this->assertSame('root', $this->whoAmI(['Cookie' => $cookie])['handle'], 'the browser session is intact after the bearer request that carried it');
    }

    public function testAHttpBasicAuthorizationHeaderDoesNotChangeWhoABrowserIs(): void
    {
        $cookie = $this->registerUser('root');
        $this->addUser('ada');
        $basic = 'Basic ' . base64_encode('ada:correct-horse-battery-9');

        $this->assertSame('root', $this->whoAmI(['Cookie' => $cookie, 'Authorization' => $basic])['handle']);
        $this->assertNull($this->whoAmI(['Authorization' => $basic])['uuid'], 'and authenticates nobody');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hookModes(): array
    {
        return ['returning the User' => ['return'], 'calling authenticateStatelessly()' => ['call'], 'the way a plugin did it before (setIsAPIRequest, refreshSessionUser)' => ['legacy']];
    }

    /**
     * @dataProvider hookModes
     */
    public function testPluginAuthHookStillAuthenticates(string $mode): void
    {
        [$adminCookie] = $this->twoUsers();

        $viaHook = $this->whoAmI(['X-Test-Plugin-Auth' => $mode . ':ada']);
        $this->assertSame('ada', $viaHook['handle'], $mode);
        $this->assertTrue($viaHook['stateless']);
        $this->assertSame(['ROLE_USER'], $viaHook['roles']);

        $withSession = $this->whoAmI(['X-Test-Plugin-Auth' => $mode . ':ada', 'Cookie' => $adminCookie]);
        $this->assertSame('ada', $withSession['handle'], 'the hook\'s user wins over the carried session');
        $this->assertSame('root', $this->whoAmI(['Cookie' => $adminCookie])['handle'], 'and the carried session is intact');
        $this->assertNull($this->whoAmI()['uuid'], 'nobody else is ada');
    }

    public function testARequestFlaggedAsApiActsAsNobodyWhateverCookieItCarries(): void
    {
        [$adminCookie] = $this->twoUsers();

        $flagged = $this->whoAmI(['X-Test-Plugin-Auth' => 'flag:', 'Cookie' => $adminCookie]);

        $this->assertNull($flagged['uuid'], 'a request that skips the form token is never the cookie\'s user');
        $this->assertSame([], $flagged['roles']);
        $this->assertSame('root', $this->whoAmI(['Cookie' => $adminCookie])['handle'], 'the cookie\'s session is intact');
    }

    public function testAPluginThatLoggedAUserOnAndThenFlaggedTheRequestAuthenticatesItWithoutPlantingALogin(): void
    {
        [$adminCookie] = $this->twoUsers();

        $without = $this->whoAmI(['X-Test-Plugin-Auth' => 'logon-flag:ada']);
        $this->assertSame('ada', $without['handle']);
        $this->assertTrue($without['stateless']);

        $response = $this->site()->request('GET', '_test/whoami', ['X-Test-Plugin-Auth' => 'logon-flag:ada', 'Cookie' => $adminCookie]);
        $with = $response->json();
        $this->assertSame('ada', $with['handle'], 'authenticated for the request');
        $this->assertTrue($with['stateless']);
        $this->assertSame(['ROLE_USER'], $with['roles']);
        // Logging a user on regenerates the session's id before the plugin flags the request, so the carried session
        // goes on under the id the response sets: it holds its own user, not ada.
        $this->assertSame('root', $this->whoAmI(['Cookie' => self::sessionCookieOrNull($response) ?? $adminCookie])['handle'], 'the session still holds its own user, not ada');
        $this->assertNull($this->whoAmI()['uuid'], 'and nobody is ada');
    }

    public function testAHookAnswerThatIsNoUserLeavesTheSessionsUserAndTheRequestNotStateless(): void
    {
        [$adminCookie] = $this->twoUsers();

        $me = $this->whoAmI(['X-Test-Plugin-Auth' => 'nonuser:x', 'Cookie' => $adminCookie]);

        $this->assertSame('root', $me['handle']);
        $this->assertFalse($me['stateless']);
    }

    public function testAnAdminsCookieWithAWrongSignatureStaysTheSessionAndAPostStillNeedsTheFormToken(): void
    {
        [$adminCookie, $key] = $this->twoUsers();
        $headers = self::signedHeadersFor('ada', $key, '/status/edit/');
        $headers['X-IDNO-SIGNATURE'] = base64_encode('wrong');

        $me = $this->whoAmI($headers + ['Cookie' => $adminCookie]);
        $this->assertSame('root', $me['handle'], 'the session\'s user');
        $this->assertFalse($me['stateless']);

        $marker = 'wrong signature ' . bin2hex(random_bytes(4));
        $this->site()->request('POST', 'status/edit/', $headers + ['Cookie' => $adminCookie], ['body' => $marker, 'access' => 'PUBLIC']);
        $this->assertNull($this->runInSite('$s = \IdnoPlugins\Status\Status::getOne([\'body\' => ' . $this->literal($marker) . ']); return $s ? $s->getUUID() : null;'), 'refused for its form token');
    }

    public function testATokenForABlockedMemberAtMicropubNeitherActsAsTheCookiesUserNorPostsAnything(): void
    {
        $this->registerUser('root');
        $this->addUser('ada');
        $adaCookie = $this->loginCookie('ada', 'correct-horse-battery-9');
        $key = $this->apiKeyOf('root');
        // The first administrator's API key is a bearer token; that member's address is then blocked.
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->blocked_emails = [\'root@example.com\']; $c->save(); return true;');

        $marker = 'blocked bearer ' . bin2hex(random_bytes(4));
        $response = $this->site()->request('POST', 'micropub/endpoint', ['Cookie' => $adaCookie, 'Authorization' => 'Bearer ' . $key], ['h' => 'entry', 'content' => $marker]);

        $this->assertSame(401, $response->status, $response->body);
        $this->assertNull($this->runInSite('$s = \IdnoPlugins\Status\Status::getOne([\'body\' => ' . $this->literal($marker) . ']); return $s ? $s->getUUID() : null;'), 'nothing was posted as ada');
    }

    public function testAnAnonymousBrowserSessionWithAPendingMessageSurvivesASignedRequestThatCarriesIt(): void
    {
        $this->registerUser('root');
        $this->addUser('ada');
        $key = $this->apiKeyOf('ada');
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => 'not the password']);

        $this->site()->request('GET', '_test/whoami', self::signedHeadersFor('ada', $key, '/_test/whoami') + ['Cookie' => $cookie]);

        $page = $this->site()->request('GET', 'session/login', ['Cookie' => $cookie]);
        $this->assertStringContainsString("That username or password isn't right", \Tests\Acceptance\Browser::text($page->body), 'the message waiting in the visitor\'s session was not destroyed with it');
    }

    public function testASignedRequestWithoutACookieDestroysTheSessionItStarted(): void
    {
        $this->registerUser('root');
        $this->addUser('ada');
        $key = $this->apiKeyOf('ada');
        // where the site keeps its sessions depends on the database: PHP files on SQLite (the test's own directory, see siteIni()), the rows of
        // `idno_sessions` on MySQL and MariaDB (a private database): either way, the session this request STARTED must not be left behind
        $sessions = $this->sessionIds();
        $this->assertNotSame([], $sessions, 'the site writes its sessions here (registering the users made some)');

        $this->site()->request('GET', '_test/whoami', self::signedHeadersFor('ada', $key, '/_test/whoami'));

        $this->assertSame($sessions, $this->sessionIds(), 'no session is left behind by an API client, and none of the others is removed');
    }

    /**
     * The ids of the site's sessions, sorted: the names of the session files of its own directory, or the `session_id` of each row of its
     * `idno_sessions` table (the site's handler there is PdoSessionHandler, whose lock is a database one: RepositoryDataConcierge::handleSession()).
     *
     * @return list<string>
     */
    private function sessionIds(): array
    {
        if (ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all()) === 'sqlite') {
            $files = array_map('basename', glob($this->sessionDirectory() . '/sess_*') ?: []);
            sort($files);

            return $files;
        }
        $ids = array_map('strval', $this->siteConnection()->fetchFirstColumn('select session_id from idno_sessions'));
        sort($ids);

        return $ids;
    }
}
