<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A POST, PUT or DELETE a script sends (`X-Requested-With: XMLHttpRequest`) cannot be redirected, so `forward()` sends no `Location`: but a handler
 * that forwards to refuse, and carries on after it, must not carry on. For such a request `forward()` ENDS the page, as it ends one that can
 * redirect; the answer is what it was for a handler that ended at that call (the status it had set, no `Location`, an empty body). Held on a legacy
 * page and on a page a controller serves, each of which refuses and then would do the work (it answers `CONTINUED`). A forward that is asked not to
 * end the page (`exit` false) still returns.
 */
final class XhrForwardEndsRequestTest extends PrivateSiteTestCase
{
    use LogsIn;

    protected const SHARED_SITE = true;

    private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    /** @var array<string, string> the page's path by name */
    private const PAGES = ['a legacy page' => '/_test/legacy-forward', 'a controller page' => '/_test/site-page/forward'];

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->apiKeyOf('ada');
    }

    /** @return array{string, string} the visitor's session cookie and the form token of that session */
    private function visitor(): array
    {
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $answer = $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $cookie] + self::XHR);
        $this->assertSame(200, $answer->status);
        $token = trim($answer->body);
        $decoded = json_decode($token, true);
        if (is_array($decoded)) {
            $token = (string) ($decoded['token'] ?? $decoded['csrf'] ?? reset($decoded));
        }
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);

        return [$cookie, $token];
    }

    public function testAForwardEndsAScriptsStateChangingRequestAsItEndsAnOrdinaryOne(): void
    {
        [$cookie, $token] = $this->visitor();
        foreach (self::PAGES as $what => $path) {
            foreach (['POST', 'PUT', 'DELETE'] as $method) {
                $address = $path . '?__bTk=' . $token;
                $ordinary = $this->site()->request($method, $address, ['Cookie' => $cookie]);
                $this->assertSame(302, $ordinary->status, "$what, $method: an ordinary request is redirected");
                $this->assertSame('', $ordinary->body, "$what, $method: and the page ended there");

                $script = $this->site()->request($method, $address, ['Cookie' => $cookie] + self::XHR);
                $this->assertStringNotContainsString('CONTINUED', $script->body, "$what, $method: a script's request does not go on after the refusal");
                $this->assertSame('', $script->body, "$what, $method: an empty answer");
                $this->assertSame([], $script->lines('location'), "$what, $method: no Location, a script cannot be redirected");
                $this->assertSame(200, $script->status, "$what, $method: the status the page had set");
            }
        }
    }

    public function testASignedRequestThatSaysItIsAScriptsEndsToo(): void
    {
        $key = $this->apiKeyOf('ada');
        foreach (self::PAGES as $what => $path) {
            foreach (['POST', 'PUT', 'DELETE'] as $method) {
                $address = $path . '?x=1';
                $answer = $this->site()->request($method, $address, self::signedHeadersFor('ada', $key, $address) + self::XHR);
                $this->assertStringNotContainsString('CONTINUED', $answer->body, "$what, $method");
                $this->assertSame([], $answer->lines('location'), "$what, $method");
            }
        }
    }

    public function testAForwardThatIsAskedNotToEndThePageReturnsAsItDid(): void
    {
        [$cookie, $token] = $this->visitor();
        foreach (self::PAGES as $what => $path) {
            $address = $path . '?exit=0&__bTk=' . $token;
            $script = $this->site()->request('POST', $address, ['Cookie' => $cookie] + self::XHR);
            $this->assertStringContainsString('CONTINUED', $script->body, "$what: a forward that does not end the page returns, for a script");
            $this->assertSame([], $script->lines('location'), $what);
            $ordinary = $this->site()->request('POST', $address, ['Cookie' => $cookie]);
            $this->assertStringContainsString('CONTINUED', $ordinary->body, "$what: and for an ordinary request");
            $this->assertNotSame([], $ordinary->lines('location'), $what);
        }
    }

    public function testAScriptsGetIsNotAffected(): void
    {
        $answer = $this->site()->request('GET', '/_test/site-page/forward', self::XHR);
        $this->assertSame('', $answer->body, 'a GET is redirected as it was');
        $this->assertSame(302, $answer->status);
    }
}
