<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Webservice;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * HSTS state comes only from the final response, only over https, and speaks only for the host that sent it.
 */
final class WebserviceHstsTest extends \Tests\IdnoTestCase
{
    private const HOSTS = ['a.hsts-test.invalid', 'b.hsts-test.invalid'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forget();
    }

    protected function tearDown(): void
    {
        Webservice::useClient(null);
        $this->forget();
        parent::tearDown();
    }

    private function forget(): void
    {
        foreach (self::HOSTS as $host) {
            Idno::site()->cache()->delete($host);
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function answer(array $responses): void
    {
        Webservice::useClient(new MockHttpClient(static function () use (&$responses) {
            return array_shift($responses) ?? new MockResponse('', ['http_code' => 200]);
        }));
    }

    private function known(string $host): bool
    {
        return Idno::site()->cache()->load($host) !== null;
    }

    private function remember(string $host): void
    {
        Idno::site()->cache()->store($host, serialize(['stored_ts' => time(), 'max-age' => 31536000, 'includeSubDomains' => false]));
    }

    public function testAHeaderOnAPlainHttpResponseStoresNothing(): void
    {
        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=31536000']])]);

        Webservice::get('http://a.hsts-test.invalid/');

        $this->assertFalse($this->known('a.hsts-test.invalid'));
    }

    public function testAHeaderOnAnHttpsResponseIsStoredForThatHost(): void
    {
        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=31536000']])]);

        Webservice::get('https://a.hsts-test.invalid/');

        $this->assertTrue(Webservice::isHSTS('http://a.hsts-test.invalid/'));
    }

    public function testAHeaderOnAnIntermediateHopStoresNothingForEitherHost(): void
    {
        $this->answer([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://b.hsts-test.invalid/', 'Strict-Transport-Security: max-age=31536000']]),
            new MockResponse('', ['http_code' => 200]),
        ]);

        Webservice::get('https://a.hsts-test.invalid/');

        $this->assertFalse($this->known('a.hsts-test.invalid'));
        $this->assertFalse($this->known('b.hsts-test.invalid'));
    }

    public function testTheFinalHttpsResponseSpeaksOnlyForItsOwnHost(): void
    {
        $this->answer([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://b.hsts-test.invalid/']]),
            new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=31536000']]),
        ]);

        Webservice::get('http://a.hsts-test.invalid/');

        $this->assertFalse($this->known('a.hsts-test.invalid'));
        $this->assertTrue($this->known('b.hsts-test.invalid'));
    }

    public function testAnotherHostsMaxAgeZeroDoesNotDeleteTheFirstHostsEntry(): void
    {
        $this->remember('a.hsts-test.invalid');
        $this->answer([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://b.hsts-test.invalid/']]),
            new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=0']]),
        ]);

        // a is known, so the request goes to https://a...; its redirect leads to b, which says max-age=0
        Webservice::get('http://a.hsts-test.invalid/');

        $this->assertTrue($this->known('a.hsts-test.invalid'));
    }

    public function testAHeaderWithNoMaxAgeChangesNothing(): void
    {
        $this->remember('a.hsts-test.invalid');
        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: includeSubDomains']])]);
        Webservice::get('https://a.hsts-test.invalid/');
        $this->assertTrue($this->known('a.hsts-test.invalid'), 'an entry stays');

        $this->forget();
        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=soon; includeSubDomains']])]);
        Webservice::get('https://b.hsts-test.invalid/');
        $this->assertFalse($this->known('b.hsts-test.invalid'), 'and none is made');
    }

    public function testAnExplicitMaxAgeZeroOverHttpsRemovesTheEntry(): void
    {
        $this->remember('a.hsts-test.invalid');
        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=0']])]);

        Webservice::get('https://a.hsts-test.invalid/');

        $this->assertFalse($this->known('a.hsts-test.invalid'));
    }

    public function testAStoredValueWithoutItsFieldsIsNoEntryAndIsDeleted(): void
    {
        Idno::site()->cache()->store('a.hsts-test.invalid', serialize(['includeSubDomains' => true]));

        $this->assertFalse(Webservice::isHSTS('http://a.hsts-test.invalid/'));
        $this->assertFalse($this->known('a.hsts-test.invalid'));

        Idno::site()->cache()->store('a.hsts-test.invalid', serialize('not an array'));
        $this->assertFalse(Webservice::isHSTS('http://a.hsts-test.invalid/'));
    }
}
