<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Common\Entity;
use Idno\Core\Bonita\Main;
use Idno\Core\Idno;
use Idno\Entities\User;
use Tests\Acceptance\Browser;
use Tests\Support\LogsIn;

/**
 * The form token (`__bTs` the time, `__bTk` the token, `__bTa` the action the form named), over HTTP, on the test
 * rig's site: what a state-changing POST must carry, what the site accepts as a token and what it refuses. The token
 * is the session's own (`__bTk`, kept in the session, see Tests\Kernel\CsrfAdapterTest); the time (`__bTs`) and the
 * action (`__bTa`) a form prints are no part of the check.
 */
final class CsrfTest extends CharacterisationTestCase
{
    use LogsIn;

    private ?User $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new User();
        $user->handle = 'csrf' . substr(md5(random_bytes(8)), 0, 8);
        $user->email = $user->handle . '@example.com';
        $user->setTitle('Csrf Test User');
        $user->setPassword(bin2hex(random_bytes(12)));
        $this->registerFixture($user);
        $user->save(true);
        $user->getAPIkey();
        $this->member = $user;
    }

    public function testPostWithoutTokenChangesNothingAndSaysInvalidToken(): void
    {
        $cookie = $this->logIn($this->member());

        $response = $this->site()->request('POST', 'account/settings', ['Cookie' => $cookie], ['name' => 'Changed Name', 'email' => $this->member()->email]);

        $this->assertSame(302, $response->status);
        $this->assertSame('Csrf Test User', $this->titleOfMember());
        $page = $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie]);
        $this->assertStringContainsString('Invalid token.', Browser::text($page->body));
    }

    public function testPostWithTheFormsTokenChangesTheSetting(): void
    {
        $cookie = $this->logIn($this->member());

        $response = $this->postSettings($cookie, $this->settingsToken($cookie), 'Changed By Form');

        $this->assertSame(302, $response->status);
        $this->assertSame('Changed By Form', $this->titleOfMember());
    }

    public function testPostWithAnotherFormsTokenIsAccepted(): void
    {
        $cookie = $this->logIn($this->member());
        $body = 'csrf-other-form-' . bin2hex(random_bytes(6));

        // The token names the action of its own form (`__bTa`), and the site checks it against that name, not against
        // the page that receives the post: a token made for one form is good for any page.
        $response = $this->site()->request('POST', 'status/edit', ['Cookie' => $cookie], $this->settingsToken($cookie) + ['body' => $body, 'access' => 'PUBLIC']);
        $this->forgetStatus($body);

        $this->assertSame(302, $response->status);
        $this->assertTrue($this->statusExists($body), 'the status was published with the settings form\'s token');
    }

    public function testTheActionTheTokenNamesIsIgnored(): void
    {
        $cookie = $this->logIn($this->member());
        $token = $this->settingsToken($cookie);
        $token['__bTa'] = $this->site()->baseUrl() . 'status/edit';

        $this->postSettings($cookie, $token, 'Changed With A Moved Action');

        $this->assertSame('Changed With A Moved Action', $this->titleOfMember(), 'the token is the session\'s: the action a form names is printed and no part of the check');
    }

    public function testTokenIsValidForTheSessionsLifetime(): void
    {
        $cookie = $this->logIn($this->member());
        $expiry = (int) Idno::site()->config()->form_token_expiry;
        $this->assertSame(3600, $expiry, 'the setting is still there, and harmless: it is read and ignored');
        $good = $this->settingsToken($cookie);

        // The time a form carries is printed and ignored: a token is good for as long as the session is, however old
        // or far ahead the time it names.
        foreach ([time() - $expiry - 10, time() - 10 * $expiry, time() + $expiry + 60] as $i => $time) {
            $this->postSettings($cookie, ['__bTs' => (string) $time] + $good, 'Changed At ' . $i);
            $this->assertSame('Changed At ' . $i, $this->titleOfMember(), 'a token naming the time ' . $time);
        }
    }

    public function testAFormOpenedBeforeTheUpgradeFailsOnceAndChangesNothing(): void
    {
        $cookie = $this->logIn($this->member());
        $action = $this->settingsToken($cookie)['__bTa'];

        // What a form drawn before the upgrade carries: the keyed hash of the action, the time and the session's id.
        $old = $this->oldTokenFor($cookie, $action, time());
        $this->postSettings($cookie, $old, 'Changed By An Old Form');

        $this->assertSame('Csrf Test User', $this->titleOfMember());
        $page = $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie]);
        $this->assertStringContainsString('Invalid token.', Browser::text($page->body), 'the member is told once');
        // The form the page now shows carries the new token.
        $this->postSettings($cookie, self::formToken($page->body, 'account/settings/'), 'Changed By The New Form');
        $this->assertSame('Changed By The New Form', $this->titleOfMember());
    }

    public function testTheTokenChangesWhenTheSessionIdDoes(): void
    {
        $form = $this->site()->request('GET', 'session/login');
        $visitorCookie = self::sessionCookie($form);
        $visitorToken = self::formToken($form->body, 'session/login');
        $password = bin2hex(random_bytes(12));
        $this->member()->setPassword($password);
        $this->member()->save(true);

        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $visitorCookie], $visitorToken + ['email' => $this->member()->handle, 'password' => $password]);
        $this->assertSame(302, $login->status);
        $cookie = self::sessionCookie($login);
        $this->assertNotSame($visitorCookie, $cookie, 'logging in gives the session a new id');

        $memberToken = $this->settingsToken($cookie);
        $this->assertNotSame($visitorToken['__bTk'], $memberToken['__bTk'], 'and the member a new token');
        $this->postSettings($cookie, $visitorToken, 'Changed With The Old Token');
        $this->assertSame('Csrf Test User', $this->titleOfMember(), 'the token from before the login is refused');
        $this->postSettings($cookie, $memberToken, 'Changed With The New Token');
        $this->assertSame('Changed With The New Token', $this->titleOfMember());

        $out = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->site()->baseUrl()], $memberToken);
        $this->assertSame(302, $out->status);
        $after = $this->site()->request('GET', 'session/login', ['Cookie' => $cookie]);
        $this->assertNotSame($memberToken['__bTk'], self::formToken($after->body, 'session/login')['__bTk'], 'the token does not survive logging out');
    }

    public function testTokenFromAnotherSessionIsRefused(): void
    {
        $first = $this->logIn($this->member());
        $second = $this->logIn($this->member());
        $this->assertNotSame($first, $second);

        $this->postSettings($second, $this->settingsToken($first), 'Changed With Another Sessions Token');

        $this->assertSame('Csrf Test User', $this->titleOfMember());
    }

    public function testATokenOfAVisitorIsRefusedOnceTheyAreLoggedIn(): void
    {
        $visitor = $this->site()->request('GET', 'session/login');
        $token = self::formToken($visitor->body, 'session/login');
        $member = $this->logIn($this->member());

        $this->postSettings($member, $token, 'Changed With A Visitors Token');

        $this->assertSame('Csrf Test User', $this->titleOfMember());
    }

    public function testArrayTokenFieldsAreRefusedWithoutAnError(): void
    {
        $cookie = $this->logIn($this->member());
        $good = $this->settingsToken($cookie);

        $arrayKey = $this->postSettings($cookie, ['__bTk' => ['x']] + $good, 'Changed With An Array Key');
        $this->assertSame(302, $arrayKey->status, 'an array for the token is an invalid token, not a server error');
        $this->assertSame('Csrf Test User', $this->titleOfMember());

        // The time and the action are no part of the check, so oddities in them do not matter.
        foreach (['__bTs' => ['1'], '__bTa' => ['x']] as $field => $odd) {
            $response = $this->postSettings($cookie, [$field => $odd] + $good, 'Changed With An Array ' . $field);
            $this->assertSame(302, $response->status);
            $this->assertSame('Changed With An Array ' . $field, $this->titleOfMember());
        }
        $word = $this->postSettings($cookie, ['__bTs' => 'abc'] + $good, 'Changed With A Word For A Time');
        $this->assertSame(302, $word->status);
        $this->assertSame('Changed With A Word For A Time', $this->titleOfMember());
    }

    public function testOnlyAMissingTokenIsRefused(): void
    {
        $cookie = $this->logIn($this->member());
        $good = $this->settingsToken($cookie);

        $fields = $good;
        unset($fields['__bTk']);
        $this->postSettings($cookie, $fields, 'Changed Without The Token');
        $this->assertSame('Csrf Test User', $this->titleOfMember(), 'without __bTk');

        // The time and the action are printed with the token and ignored when it is checked.
        foreach (['__bTs', '__bTa'] as $drop) {
            $fields = $good;
            unset($fields[$drop]);
            $this->postSettings($cookie, $fields, 'Changed Without ' . $drop);
            $this->assertSame('Changed Without ' . $drop, $this->titleOfMember(), 'without ' . $drop);
        }
    }

    public function testCsrfTokenEndpointNeedsXhrAndReturnsAWorkingToken(): void
    {
        $cookie = $this->logIn($this->member());
        $url = $this->site()->baseUrl() . 'account/settings';
        $path = 'service/security/csrftoken/?url=' . rawurlencode($url);

        $plain = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
        $xhr = $this->site()->request('GET', $path, ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(403, $plain->status, 'only an XHR request is given a token');
        $this->assertSame(200, $xhr->status);
        $this->assertStringContainsString('application/json', (string) $xhr->header('Content-Type'));
        $this->assertSame('no-store', $xhr->header('Cache-Control'), 'the token is never cached');
        $json = $xhr->json();
        $this->assertSame(['time', 'token'], array_keys($json));
        $this->postSettings($cookie, ['__bTs' => (string) $json['time'], '__bTk' => $json['token'], '__bTa' => $url], 'Changed By Script');
        $this->assertSame('Changed By Script', $this->titleOfMember(), 'the token a script is given works for the session that asked');
    }

    public function testTheTokenEndpointNeedsNoUrl(): void
    {
        $cookie = $this->logIn($this->member());
        $headers = ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'];

        $response = $this->site()->request('GET', 'service/security/csrftoken/', $headers);

        // (it was a 500 page: the token is the session's one stored token, so there is no address to make one for)
        $this->assertSame(200, $response->status);
        $json = $response->json();
        $this->assertSame(['time', 'token'], array_keys($json));
        $this->assertSame($json['token'], $this->site()->request('GET', 'service/security/csrftoken/?url=x', $headers)->json()['token'], 'the same token, whatever address is named');
        $this->assertSame(200, $this->site()->request('GET', 'service/security/csrftoken/?url[]=x', $headers)->status);
    }

    public function testCsrfTokenEndpointAnswersAVisitorToo(): void
    {
        $response = $this->site()->request('GET', 'service/security/csrftoken/?url=' . rawurlencode($this->site()->baseUrl() . 'session/login'), ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $response->status);
        $this->assertArrayHasKey('token', $response->json());
    }

    public function testApiSignedPostNeedsNoToken(): void
    {
        $body = 'csrf-signed-' . bin2hex(random_bytes(6));

        $response = $this->site()->request('POST', 'status/edit', self::signedHeaders($this->member(), '/status/edit'), ['body' => $body]);
        $this->forgetStatus($body);

        $this->assertContains($response->status, [200, 201, 302], substr($response->body, 0, 300));
        $this->assertTrue($this->statusExists($body), 'published without a form token');
    }

    public function testApiSignedPostWithTheWrongSignatureNeedsAToken(): void
    {
        $body = 'csrf-badsig-' . bin2hex(random_bytes(6));
        $headers = self::signedHeaders($this->member(), '/status/edit');
        $headers['X-IDNO-SIGNATURE'] = base64_encode('wrong');

        $response = $this->site()->request('POST', 'status/edit', $headers, ['body' => $body]);
        $this->forgetStatus($body);

        $this->assertSame(302, $response->status, 'refused for its token, as a browser post is');
        $this->assertFalse($this->statusExists($body));
    }

    // ------------------------------------------------------------------ helpers

    private function member(): User
    {
        $this->assertNotNull($this->member);

        return $this->member;
    }

    private function titleOfMember(): string
    {
        $uuid = $this->member()->getUUID();
        Entity::invalidateCache($uuid);
        $fresh = Entity::getByUUID($uuid);
        $this->assertInstanceOf(User::class, $fresh);

        return (string) $fresh->getTitle();
    }

    /**
     * The token fields of the settings form the member's session is given.
     *
     * @return array<string, string>
     */
    private function settingsToken(string $cookie): array
    {
        $page = $this->site()->request('GET', 'account/settings', ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);

        return self::formToken($page->body, 'account/settings/');
    }

    /**
     * @param array<string, mixed> $token
     */
    private function postSettings(string $cookie, array $token, string $name): SiteResponse
    {
        return $this->site()->request('POST', 'account/settings', ['Cookie' => $cookie], $token + ['name' => $name, 'email' => $this->member()->email]);
    }

    /**
     * The token the site made before the form token was the session's own: a keyed hash of the action, then the time,
     * then the session's id (the id of the cookie's session). No longer a token.
     *
     * @return array<string, string>
     */
    private function oldTokenFor(string $cookie, string $action, int $time): array
    {
        $sessionId = substr($cookie, (int) strpos($cookie, '=') + 1);
        $hmac = hash_hmac('sha256', explode('?', $action)[0], (string) Main::getSiteSecret(), true);
        $hmac = hash_hmac('sha256', (string) $time, $hmac, true);

        return ['__bTs' => (string) $time, '__bTk' => hash_hmac('sha256', $sessionId, $hmac), '__bTa' => $action];
    }

    private function forgetStatus(string $body): void
    {
        $status = \IdnoPlugins\Status\Status::getOne(['body' => $body]);
        if ($status instanceof Entity) {
            $this->registerFixture($status);
        }
    }
}
