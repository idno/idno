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

    /** The keys an IP-literal host could leave (a failing test would pin the rig's own address to https). */
    private const IP_KEYS = ['127.0.0.1', '::1', '[::1]', '2001:db8::1', '[2001:db8::1]'];

    private function forget(): void
    {
        foreach ([...self::HOSTS, ...self::IP_KEYS] as $host) {
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

    /**
     * @return list<string> the URLs the client was asked for
     */
    private function requestedBy(string $url): array
    {
        $seen = [];
        Webservice::useClient(new MockHttpClient(static function (string $method, string $requested) use (&$seen) {
            $seen[] = $requested;

            return new MockResponse('', ['http_code' => 200]);
        }));
        Webservice::get($url);

        return $seen;
    }

    public function testOnlyTheSchemeOfAKnownHostIsUpgraded(): void
    {
        $this->remember('a.hsts-test.invalid');

        $this->assertSame(['https://a.hsts-test.invalid/x?url=http://other/y'], $this->requestedBy('http://a.hsts-test.invalid/x?url=http://other/y'));
        $this->assertSame(['https://a.hsts-test.invalid/p?u=http://o/'], $this->requestedBy('https://a.hsts-test.invalid/p?u=http://o/'));
        $this->assertSame(['https://a.hsts-test.invalid/up'], $this->requestedBy('HTTP://a.hsts-test.invalid/up'));
    }

    public function testTheHostIsLowerCased(): void
    {
        $this->remember('a.hsts-test.invalid');
        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=0']])]);

        Webservice::get('https://A.HSTS-Test.invalid/');

        $this->assertFalse($this->known('a.hsts-test.invalid'));

        $this->answer([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=600']])]);
        Webservice::get('https://B.HSTS-Test.invalid/');
        $this->assertTrue($this->known('b.hsts-test.invalid'));
        $this->assertTrue(Webservice::isHSTS('http://B.hsts-test.INVALID/'));
    }

    public function testAnIpLiteralIsNeverPinned(): void
    {
        foreach (['https://127.0.0.1/', 'https://[::1]/', 'https://[2001:db8::1]:8443/'] as $url) {
            foreach (['127.0.0.1', '::1', '[::1]', '2001:db8::1', '[2001:db8::1]'] as $stale) {
                Idno::site()->cache()->delete($stale);
            }
            Webservice::checkForHSTSHeader($url, ['Strict-Transport-Security: max-age=600']);
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            foreach ([$host, '[' . $host . ']', (string) parse_url($url, PHP_URL_HOST)] as $key) {
                $this->assertNull(Idno::site()->cache()->load($key), $url);
            }
            $this->assertFalse(Webservice::isHSTS(str_replace('https', 'http', $url)));
        }
    }

    public function testAPoolThePathCannotWriteIsAMissAndNeverAnErrorForAnOutboundRequest(): void
    {
        // A queue worker run as another Unix user, or a pool directory made under another umask: the namespace
        // directory is there and this process cannot write in it. HSTS memory is lost; nothing is thrown.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('The superuser can write anywhere.');
        }
        $base = sys_get_temp_dir() . '/idno-unwritable-pool-' . bin2hex(random_bytes(6));
        $namespace = md5('unwritable.example');
        mkdir($base . '/' . $namespace, 0700, true);
        chmod($base . '/' . $namespace, 0500);
        $property = new \ReflectionProperty(Idno::site(), 'cache');
        $property->setAccessible(true);
        $real = $property->getValue(Idno::site());
        $pool = new \Symfony\Component\Cache\Adapter\FilesystemAdapter($namespace, 0, $base);
        $property->setValue(Idno::site(), new \Idno\Caching\PersistentCache(static fn () => $pool));
        try {
            Idno::site()->cache()->store('some-key', 'value');
            $this->assertNull(Idno::site()->cache()->load('some-key'), 'the save could not be written: a miss');

            $this->answer([new MockResponse('ok', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=600']])]);
            $result = Webservice::get('https://a.hsts-test.invalid/');

            $this->assertSame(200, $result['response'], 'the outbound request succeeds');
            $this->assertSame('ok', $result['content']);
            $this->assertFalse(Webservice::isHSTS('http://a.hsts-test.invalid/'), 'only the HSTS memory is lost');
        } finally {
            $property->setValue(Idno::site(), $real);
            @chmod($base . '/' . $namespace, 0700);
            \Idno\Portability\ArchiveExporter::removeTree($base);
        }
    }
}
