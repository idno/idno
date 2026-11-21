<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Fixtures\LocalOnlyHttpClient;

/**
 * The site's two HTTP clients, as the container builds them (a kernel without the legacy
 * application: the clients need only the boot configuration).
 */
final class HttpClientServicesTest extends TestCase
{
    /** The kernel most tests share: each compiled container is a class this process keeps for good, so the tests do not boot one apiece. */
    private static ?Kernel $shared = null;
    private static ?string $sharedCacheDir = null;

    private ?Kernel $own = null;
    private ?string $ownCacheDir = null;

    public static function tearDownAfterClass(): void
    {
        self::$shared?->shutdown();
        self::$shared = null;
        if (self::$sharedCacheDir !== null) {
            self::removeDir(self::$sharedCacheDir);
            self::$sharedCacheDir = null;
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['IDNO_PROXY_STRING'], $_SERVER['IDNO_TEST_HTTP_ALLOW'], $_SERVER['IDNO_HTTP_ALLOW_PRIVATE']);
        $this->own?->shutdown();
        $this->own = null;
        if ($this->ownCacheDir !== null) {
            self::removeDir($this->ownCacheDir);
            $this->ownCacheDir = null;
        }
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private static function newKernel(string $environment, ?string &$cacheDir): Kernel
    {
        // A fresh cache directory forces the container to be compiled during this test.
        $previous = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $cacheDir;
        try {
            $kernel = new Kernel($environment, $environment === 'test', bootLegacy: false);
            $kernel->boot();
        } finally {
            if ($previous === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $previous;
            }
        }

        return $kernel;
    }

    private function shared(): Kernel
    {
        return self::$shared ??= self::newKernel('test', self::$sharedCacheDir);
    }

    /** A kernel of this test's own (its container is compiled for it alone, and removed after). */
    private function boot(string $environment = 'test'): Kernel
    {
        return $this->own = self::newKernel($environment, $this->ownCacheDir);
    }

    private function client(string $id = 'idno.http_client'): HttpClientInterface
    {
        $client = $this->shared()->getContainer()->get($id);
        $this->assertInstanceOf(HttpClientInterface::class, $client);

        return $client;
    }

    private function siteUrl(): string
    {
        $url = $this->shared()->getContainer()->get(BootConfig::class)->get('url');
        $this->assertIsString($url);

        return rtrim($url, '/');
    }

    /**
     * @return array{headers: array<string, string>}
     */
    private function echoed(HttpClientInterface $client): array
    {
        return $client->request('GET', $this->siteUrl() . '/_test/echo')->toArray();
    }

    public function testTheServicesAreTheFactorysClients(): void
    {
        $container = $this->shared()->getContainer();

        $site = $container->get('idno.http_client');
        $remote = $container->get('idno.http_client.remote');

        $this->assertInstanceOf(HttpClientInterface::class, $site);
        $this->assertInstanceOf(HttpClientInterface::class, $remote);
        $this->assertNotSame($site, $remote);
        $this->assertSame($site, $container->get(HttpClientInterface::class), 'the autowired client is the site client');
        $this->assertSame($site, $container->get('idno.http_client'), 'built once');
    }

    public function testEachClientTellsTheSiteWhichOneSentTheRequest(): void
    {
        $this->assertSame('site', $this->echoed($this->client())['headers']['x-idno-test-client']);
        $this->assertSame('remote', $this->echoed($this->client('idno.http_client.remote'))['headers']['x-idno-test-client']);
    }

    public function testTheClientsSendTheUserAgentOfTheSite(): void
    {
        $this->assertSame('Idno https://idno.co', $this->echoed($this->client())['headers']['user-agent']);
    }

    public function testAHostThatIsNotTheSiteIsRefusedInTests(): void
    {
        foreach (['idno.http_client', 'idno.http_client.remote'] as $id) {
            try {
                $this->client($id)->request('GET', 'https://user:secret@example.com/path?token=abc');
                $this->fail($id . ' sent a request to a host that is not the site');
            } catch (TransportException $e) {
                $this->assertSame('No network in tests: example.com', $e->getMessage());
            }
        }
    }

    public function testASameHostOnAnotherPortIsRefused(): void
    {
        $port = (int) parse_url($this->siteUrl(), PHP_URL_PORT);
        $host = (string) parse_url($this->siteUrl(), PHP_URL_HOST);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('No network in tests: ' . $host);
        $this->client()->request('GET', 'http://' . $host . ':' . ($port + 1) . '/');
    }

    public function testTheRemoteClientStillRefusesAPrivateAddressThatIsAllowedInTests(): void
    {
        $_SERVER['IDNO_TEST_HTTP_ALLOW'] = '10.0.0.5:80';

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/blocked/');
        $this->client('idno.http_client.remote')->request('GET', 'http://10.0.0.5/')->getStatusCode();
    }

    public function testARedirectIsFollowedToTheFinalResponseOnARealServer(): void
    {
        $response = $this->client()->request('GET', $this->siteUrl() . '/_test/redirect/302');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->siteUrl() . '/_test/echo', $response->getInfo('url'));
        $this->assertSame('site', $response->toArray()['headers']['x-idno-test-client']);
    }

    public function testTheRemoteClientFollowsARedirectWithinTheSite(): void
    {
        // The redirect route only ever points at this site, whose origin the test wiring exempts.
        $response = $this->client('idno.http_client.remote')->request('GET', $this->siteUrl() . '/_test/redirect/302');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('remote', $response->toArray()['headers']['x-idno-test-client']);
    }

    public function testNoConfigurationValueIsCompiledIntoTheContainer(): void
    {
        $_SERVER['IDNO_PROXY_STRING'] = 'proxy-sentinel.example:3128';
        $_SERVER['IDNO_HTTP_ALLOW_PRIVATE'] = '10.9.8.0/24,allow-sentinel-not-an-address';
        $kernel = $this->boot();
        $container = $kernel->getContainer();
        $this->assertSame('proxy-sentinel.example:3128', $container->get(BootConfig::class)->get('proxy_string'));
        $container->get('idno.http_client');
        try {
            $container->get('idno.http_client.remote');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('http_allow_private', $e->getMessage(), 'the invalid entry is read at runtime');
        }

        $compiled = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kernel->getCacheDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $compiled++;
                $this->assertStringNotContainsString('proxy-sentinel.example', (string) file_get_contents($file->getPathname()));
                $this->assertStringNotContainsString('10.9.8.0', (string) file_get_contents($file->getPathname()));
                $this->assertStringNotContainsString('allow-sentinel', (string) file_get_contents($file->getPathname()));
            }
        }
        $this->assertGreaterThan(0, $compiled);
    }

    public function testASilentServerFailsWithinTheIdleTimeout(): void
    {
        $client = $this->client()->withOptions(['timeout' => 1.0]);

        $start = microtime(true);
        try {
            $client->request('GET', $this->siteUrl() . '/_test/slow?seconds=5')->getStatusCode();
            $this->fail('a server that says nothing for 5 seconds must time out');
        } catch (TransportException) {
            $this->assertLessThan(3.0, microtime(true) - $start);
        }
    }

    public function testProductionWiringHasNoTestWrapperAndRefusesPrivateNetworksForRemoteUrls(): void
    {
        $container = $this->boot('prod')->getContainer();

        $site = $container->get('idno.http_client');
        $remote = $container->get('idno.http_client.remote');

        $this->assertNotInstanceOf(LocalOnlyHttpClient::class, $site);
        $this->assertNotInstanceOf(LocalOnlyHttpClient::class, $remote);
        $this->assertInstanceOf(NoPrivateNetworkHttpClient::class, $remote);
        $this->assertSame($site, $container->get(HttpClientInterface::class));

        // The remote client refuses the site's own (loopback) address: nothing exempts it in production.
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/blocked/');
        $remote->request('GET', $this->siteUrl() . '/_test/echo')->getStatusCode();
    }

    public function testEachFetchOfTheRemoteServiceIsANewClientOverTheSameSiteClient(): void
    {
        $container = $this->shared()->getContainer();

        $first = $container->get('idno.http_client.remote');
        $second = $container->get('idno.http_client.remote');

        $this->assertNotSame($first, $second, 'a long-running worker must not keep one DNS cache for good');
        $this->assertInstanceOf(LocalOnlyHttpClient::class, $first);
        $this->assertSame(self::property($first, 'siteClient'), self::property($second, 'siteClient'), 'the shared site client');
        $this->assertNotSame(self::property($first, 'client'), self::property($second, 'client'));
        $this->assertSame(self::property($container->get('idno.http_client'), 'client'), self::property($first, 'siteClient'), 'the site client the site service wraps');
    }

    public function testProductionBuildsANewRemoteClientOnEachFetchOverTheSharedSiteClient(): void
    {
        $container = $this->boot('prod')->getContainer();

        $first = $container->get('idno.http_client.remote');
        $second = $container->get('idno.http_client.remote');

        $this->assertNotSame($first, $second);
        $this->assertInstanceOf(NoPrivateNetworkHttpClient::class, $first);
        $this->assertSame($container->get('idno.http_client'), self::property($first, 'client'));
        $this->assertSame(self::property($first, 'client'), self::property($second, 'client'));
    }

    public function testAStarInTheBootConfigurationMakesTheRemoteClientTheSiteClient(): void
    {
        $_SERVER['IDNO_HTTP_ALLOW_PRIVATE'] = '*';
        $container = $this->boot('prod')->getContainer();

        $this->assertSame($container->get('idno.http_client'), $container->get('idno.http_client.remote'));
    }

    public function testAnInvalidAllowListEntryIsAnErrorWhenTheRemoteClientIsFetched(): void
    {
        $_SERVER['IDNO_HTTP_ALLOW_PRIVATE'] = '192.168.1.0/24, intranet';
        $container = $this->boot('prod')->getContainer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('http_allow_private');
        $container->get('idno.http_client.remote');
    }

    public function testAnAllowedRangeIsHonouredByTheProductionRemoteClient(): void
    {
        $_SERVER['IDNO_HTTP_ALLOW_PRIVATE'] = '203.0.113.0/24';
        $remote = $this->boot('prod')->getContainer()->get('idno.http_client.remote');

        $this->assertInstanceOf(NoPrivateNetworkHttpClient::class, $remote);
        $this->assertSame(['203.0.113.0/24'], self::property($remote, 'allowList'));
    }

    private static function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
