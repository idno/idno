<?php

declare(strict_types=1);

namespace Tests\Characterisation;

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
}
