<?php

declare(strict_types=1);

namespace Tests\Unit\Portability;

use Idno\Http\Client\HttpClientFactory;
use Idno\Portability\ImportedMedia;
use Idno\Storage\Files\FileStore;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class ImportedMediaTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    private string $directory = '';

    /** @var list<string> */
    private array $requests = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-media-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /**
     * @param callable(string, string): MockResponse|null $respond
     */
    private function media(?callable $respond = null, int $limit = 10 * 1024 * 1024, ?HttpClientInterface $client = null, ?int &$fetched = null): ImportedMedia
    {
        $client ??= new MockHttpClient(function (string $method, string $url) use ($respond): MockResponse {
            $this->requests[] = $method . ' ' . $url;

            return $respond !== null ? $respond($method, $url) : new MockResponse($this->png(), ['response_headers' => ['content-type' => 'image/png']]);
        });
        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $store = new FileStore(new Filesystem(new InMemoryFilesystemAdapter()), 'uploads');
        $count = &$fetched;

        return (new ImportedMedia(function () use ($client, &$count) {
            $count++;

            return $client;
        }, $store, new FakeSiteFacts($this->directory, $limit), $logger))->forDomain('wordpress.com');
    }

    public function testAnAllowedHostAndTypeIsFetchedThroughTheRemoteClient(): void
    {
        $media = $this->media();

        $path = $media->fetch('https://tidepoolnotes.files.wordpress.com/2024/02/anemone.png?w=640');

        $this->assertIsString($path);
        $this->assertSame(['GET https://tidepoolnotes.files.wordpress.com/2024/02/anemone.png?w=640'], $this->requests);
        $this->assertSame($this->png(), file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777, 'a private temporary file, in the site\'s temporary directory');
        $this->assertSame(realpath($this->directory), realpath(dirname($path)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accepted(): array
    {
        return [
            'the domain itself' => ['https://wordpress.com/a.png'],
            'a subdomain' => ['https://tidepoolnotes.files.wordpress.com/b.JPG'],
            'a deeper subdomain over http' => ['http://a.b.wordpress.com/c.jpeg'],
            'a query string' => ['https://a.wordpress.com/d.webp?w=640&h=480'],
            'upper case in the host' => ['https://A.WordPress.com/e.gif'],
            'the default port written out (http)' => ['http://a.wordpress.com:80/f.png'],
            'the default port written out (https)' => ['https://a.wordpress.com:443/g.png'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'a host that only ends with the domain' => ['https://evilwordpress.com/f.png'],
            'the domain as the start of another host' => ['https://wordpress.com.evil.example/g.png'],
            'the domain as the user name of an address' => ['https://a.wordpress.com@evil.example/h.png'],
            'a user name on the right host' => ['https://user:pass@a.wordpress.com/h.png'],
            'another host' => ['https://example.org/i.png'],
            'a script' => ['https://a.wordpress.com/j.php'],
            'a type that is not listed' => ['https://a.wordpress.com/k.svg'],
            'no extension' => ['https://a.wordpress.com/no-extension'],
            'no host' => ['/relative/l.png'],
            'a data URI' => ['data:image/png;base64,iVBORw0KGgo='],
            'ftp' => ['ftp://a.wordpress.com/a.png'],
            'a file address' => ['file:///etc/passwd.png'],
            'another port on https' => ['https://a.wordpress.com:8443/b.png'],
            'port 25 on http' => ['http://a.wordpress.com:25/c.png'],
            'the https port on http' => ['http://a.wordpress.com:443/c.png'],
            'an IP address' => ['http://127.0.0.1/c.png'],
        ];
    }

    /**
     * @dataProvider accepted
     */
    public function testTheAddressesItAccepts(string $url): void
    {
        $this->assertTrue($this->media()->accepts($url));
    }

    /**
     * @dataProvider refused
     */
    public function testAnotherHostIsNotFetched(string $url): void
    {
        $media = $this->media();

        $this->assertFalse($media->accepts($url));
        $this->assertNull($media->fetch($url));
        $this->assertSame([], $this->requests, 'the client was not asked');
        $this->assertCount(1, $this->logged, 'one line in the log');
        $this->assertSame([], glob($this->directory . '/*'), 'and no temporary file is left');
    }

    public function testWithNoSourceDomainNothingIsFetched(): void
    {
        $media = (new ImportedMedia(static fn () => new MockHttpClient(), new FileStore(new Filesystem(new InMemoryFilesystemAdapter()), 'u'), new FakeSiteFacts($this->directory), new \Psr\Log\NullLogger()));

        $this->assertFalse($media->accepts('https://wordpress.com/a.png'));
    }

    public function testAnAllowedHostThatRedirectsToAPrivateAddressIsNotFetched(): void
    {
        $target = 'http://10.0.0.5/secret.png';
        $mock = new MockHttpClient(function (string $method, string $url) use ($target): MockResponse {
            $this->requests[] = $url;

            return count($this->requests) === 1
                ? new MockResponse('', ['http_code' => 302, 'redirect_url' => $target, 'response_headers' => ['Location: ' . $target]])
                : new MockResponse($this->png());
        });
        // The site's remote client refuses private addresses; the allowed host's own address is given, so that no name is looked up.
        $remote = new class(HttpClientFactory::createRemote($mock)) implements HttpClientInterface {
            public function __construct(private readonly HttpClientInterface $inner)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->inner->request($method, $url, $options + ['resolve' => ['a.wordpress.com' => '93.184.216.34']]);
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                return $this->inner->stream($responses, $timeout);
            }

            public function withOptions(array $options): static
            {
                return new static($this->inner->withOptions($options));
            }
        };

        $media = $this->media(client: $remote);

        $this->assertNull($media->fetch('https://a.wordpress.com/a.png'));
        $this->assertSame(['https://a.wordpress.com/a.png'], $this->requests, 'the address it was redirected to is never requested');
        $this->assertSame([], glob($this->directory . '/*'));
        $this->assertCount(1, $this->logged);
        $this->assertStringNotContainsString('10.0.0.5', $this->logged[0], 'the log line does not quote the exception');
    }

    public function testABodyOverTheLimitIsCutAndDiscarded(): void
    {
        $media = $this->media(fn (): MockResponse => new MockResponse($this->png() . str_repeat("\0", 5000)), 1000);

        $this->assertNull($media->fetch('https://a.wordpress.com/big.png'));
        $this->assertSame(['GET https://a.wordpress.com/big.png'], $this->requests, 'the client was asked, and its body was cut');
        $this->assertSame([], glob($this->directory . '/*'), 'nothing is kept');
    }

    public function testABodyThatDeclaresItselfOverTheLimitIsNotEvenRead(): void
    {
        $media = $this->media(fn (): MockResponse => new MockResponse($this->png(), ['response_headers' => ['content-length' => '5000']]), 1000);

        $this->assertNull($media->fetch('https://a.wordpress.com/big.png'));
        $this->assertSame([], glob($this->directory . '/*'));
    }

    public function testABodyOfExactlyTheLimitIsKept(): void
    {
        $media = $this->media(null, strlen($this->png()));

        $path = $media->fetch('https://a.wordpress.com/exact.png');

        $this->assertIsString($path);
        $this->assertSame($this->png(), file_get_contents($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notImages(): array
    {
        return [
            'PHP code' => ['<?php echo "hello";'],
            'an SVG named .png' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'HTML' => ['<html><body>Not found</body></html>'],
            'an empty body' => [''],
            'a GIF header with nothing after it' => ['GIF8'],
        ];
    }

    /**
     * @dataProvider notImages
     */
    public function testAFileThatIsNotAnImageOfAnAllowedKindIsDiscarded(string $body): void
    {
        $media = $this->media(fn (): MockResponse => new MockResponse($body));

        $this->assertNull($media->fetch('https://a.wordpress.com/x.png'));
        $this->assertSame([], glob($this->directory . '/*'));
    }

    public function testAnErrorStatusAndATransportFailureGiveNull(): void
    {
        foreach ([new MockResponse('Not found', ['http_code' => 404]), new MockResponse('', ['error' => 'connection refused: secret-host'])] as $response) {
            $this->logged = [];
            $media = $this->media(fn (): MockResponse => $response);

            $this->assertNull($media->fetch('https://a.wordpress.com/x.png'));
            $this->assertCount(1, $this->logged);
            $this->assertStringNotContainsString('secret-host', $this->logged[0]);
        }
        $this->assertSame([], glob($this->directory . '/*'));
    }

    public function testImportKeepsTheFileWithItsNameAndType(): void
    {
        $fetched = 0;
        $media = $this->media(null, 10 * 1024 * 1024, null, $fetched);

        $id = $media->import('https://a.wordpress.com/2024/anemone.png?w=640');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
        $this->assertSame([], glob($this->directory . '/*'), 'the temporary file is removed');
    }

    public function testTheRemoteClientIsFetchedAtEachFetchNotKept(): void
    {
        $fetched = 0;
        $media = $this->media(null, 10 * 1024 * 1024, null, $fetched);

        $first = $media->fetch('https://a.wordpress.com/1.png');
        $second = $media->fetch('https://a.wordpress.com/2.png');
        $media->fetch('https://elsewhere.example/3.png');

        $this->assertSame(2, $fetched, 'once for each fetch that was made, none for the one refused');
        @unlink((string) $first);
        @unlink((string) $second);
    }
}
