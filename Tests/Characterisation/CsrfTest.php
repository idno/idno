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
 * is a keyed hash of the action, the time and the session's id, so it belongs to one session. Every assertion is what
 * the site does today.
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

    public function testTheTokenIsBoundToTheActionItNames(): void
    {
        $cookie = $this->logIn($this->member());
        $token = $this->settingsToken($cookie);
        $token['__bTa'] = $this->site()->baseUrl() . 'status/edit';

        $this->postSettings($cookie, $token, 'Changed With A Moved Action');

        $this->assertSame('Csrf Test User', $this->titleOfMember());
    }

    public function testTokenOlderThanFormTokenExpiryIsRefused(): void
    {
        $cookie = $this->logIn($this->member());
        $expiry = (int) Idno::site()->config()->form_token_expiry;
        $this->assertSame(3600, $expiry, 'the default');
        $action = $this->settingsToken($cookie)['__bTa'];

        // The test's own token for the present is good (so the hash is made as the site makes it).
        $now = time();
        $this->postSettings($cookie, $this->tokenFor($cookie, $action, $now), 'Changed Once');
        $this->assertSame('Changed Once', $this->titleOfMember());

        // Pins today: the time is the one the token carries, and the site accepts it for the expiry either way (an
        // old one is refused, one just inside the window is not, and one from the future is not refused until it is
        // as far ahead).
        $this->postSettings($cookie, $this->tokenFor($cookie, $action, $now - $expiry - 10), 'Changed Late');
        $this->assertSame('Changed Once', $this->titleOfMember(), 'a token older than the expiry is refused');
        $this->postSettings($cookie, $this->tokenFor($cookie, $action, $now - $expiry + 60), 'Changed Inside');
        $this->assertSame('Changed Inside', $this->titleOfMember(), 'one inside the window is accepted');
        $this->postSettings($cookie, $this->tokenFor($cookie, $action, $now + $expiry - 60), 'Changed Ahead');
        $this->assertSame('Changed Ahead', $this->titleOfMember(), 'one dated in the future is accepted just as well');
        $this->postSettings($cookie, $this->tokenFor($cookie, $action, $now + $expiry + 60), 'Changed Far Ahead');
        $this->assertSame('Changed Ahead', $this->titleOfMember(), 'unless it is further ahead than the expiry');
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

    public function testArrayTokenFieldsAreRefusedOrError(): void
    {
        $cookie = $this->logIn($this->member());
        $good = $this->settingsToken($cookie);

        $arrayKey = $this->postSettings($cookie, ['__bTk' => ['x']] + $good, 'Changed With An Array Key');
        $arrayTime = $this->postSettings($cookie, ['__bTs' => ['1']] + $good, 'Changed With An Array Time');
        $arrayAction = $this->postSettings($cookie, ['__bTa' => ['x']] + $good, 'Changed With An Array Action');
        $wordTime = $this->postSettings($cookie, ['__bTs' => 'abc'] + $good, 'Changed With A Word For A Time');

        // Pins today: the answer for each, and that nothing was changed.
        $this->assertSame('Csrf Test User', $this->titleOfMember());
        $this->assertSame([500, 500, 500, 500], [$arrayKey->status, $arrayTime->status, $arrayAction->status, $wordTime->status], 'each is a server error (PHP refuses the arithmetic and the hash on an array or a word)');
    }

    public function testMissingTokenFieldsAreRefused(): void
    {
        $cookie = $this->logIn($this->member());
        $good = $this->settingsToken($cookie);

        foreach (['__bTs', '__bTk', '__bTa'] as $drop) {
            $fields = $good;
            unset($fields[$drop]);
            $this->postSettings($cookie, $fields, 'Changed Without ' . $drop);
            $this->assertSame('Csrf Test User', $this->titleOfMember(), 'without ' . $drop);
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
        $this->assertStringContainsString('no-cache', (string) $xhr->header('Cache-Control'));
        $json = $xhr->json();
        $this->assertSame(['time', 'token'], array_keys($json));
        $this->postSettings($cookie, ['__bTs' => (string) $json['time'], '__bTk' => $json['token'], '__bTa' => $url], 'Changed By Script');
        $this->assertSame('Changed By Script', $this->titleOfMember(), 'the token a script is given works for the session that asked');
    }

    public function testCsrfTokenEndpointWithoutAnAddressIsAnError(): void
    {
        $cookie = $this->logIn($this->member());

        $response = $this->site()->request('GET', 'service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(500, $response->status);
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
     * The token the site makes for an action, a time and the session of the cookie: a keyed hash of the action, then
     * the time, then the session's id (`Idno\Core\Bonita\Forms::token()`, with the id of the cookie's session).
     *
     * @return array<string, string>
     */
    private function tokenFor(string $cookie, string $action, int $time): array
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
