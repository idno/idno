<?php

declare(strict_types=1);

namespace Tests\Unit\Portability;

use Idno\Portability\ImportedImages;
use Idno\Portability\ImportedMedia;
use Idno\Storage\Files\FileStore;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImportedImagesTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    private string $directory = '';

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-images-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function media(): ImportedMedia
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $method . ' ' . $url;

            return new MockResponse((string) base64_decode(self::PNG, true));
        });

        return (new ImportedMedia(static fn () => $client, new FileStore(new Filesystem(new InMemoryFilesystemAdapter()), 'uploads'), new FakeSiteFacts($this->directory), new NullLogger()))->forDomain('wordpress.com');
    }

    private function body(string ...$sources): string
    {
        return '<p>Pictures</p>' . implode('', array_map(static fn (string $src): string => '<p><img src="' . $src . '"></p>', $sources));
    }

    /**
     * @return list<string> the ids in the addresses of the site's files in the HTML
     */
    private function ids(string $html): array
    {
        preg_match_all('~https://example\.test/file/([0-9a-f]{32})~', $html, $matches);

        return $matches[1];
    }

    public function testImagesAreFetchedOnlyFromTheSourceDomainAndSubdomainsAndOnlyWhenTheyAreImageFiles(): void
    {
        $fetched = [
            'https://wordpress.com/a.png',
            'https://tidepoolnotes.files.wordpress.com/b.JPG',
            'http://a.b.wordpress.com/c.jpeg',
            'https://a.wordpress.com/d.webp?w=640&h=480',
            'https://A.WordPress.com/e.gif',
        ];
        $refused = [
            'https://evilwordpress.com/f.png' => 'a host that only ends with the domain',
            'https://wordpress.com.evil.example/g.png' => 'the domain as a prefix of another host',
            'https://a.wordpress.com@evil.example/h.png' => 'the domain as the user name of a URL',
            'https://example.org/i.png' => 'another host',
            'https://a.wordpress.com/j.php' => 'a script',
            'https://a.wordpress.com/k.svg' => 'a type that is not listed',
            'https://a.wordpress.com/no-extension' => 'no extension',
            '/relative/l.png' => 'no host',
            'data:image/png;base64,iVBORw0KGgo=' => 'a data URI',
        ];

        [$body, $ids] = ImportedImages::bring($this->body(...$fetched, ...array_keys($refused)), $this->media(), 'https://example.test/');

        // The HTTP client writes the host in lower case, as it sends it.
        $this->assertSame(array_map(static fn (string $url): string => 'GET ' . str_replace('A.WordPress.com', 'a.wordpress.com', $url), $fetched), $this->requests);
        $this->assertCount(count($fetched), $ids);
        $this->assertSame($ids, $this->ids($body));
        foreach (array_keys($refused) as $src) {
            $this->assertStringContainsString('src="' . $src . '"', $body, 'left as it was: ' . $refused[$src]);
        }
        foreach ($fetched as $src) {
            $this->assertStringNotContainsString('src="' . $src . '"', $body);
        }
    }

    public function testOnlyHttpAndHttpsOnTheDefaultPortsOfAnAllowedHostAreFetched(): void
    {
        $refused = ['ftp://a.wordpress.com/a.png', 'https://a.wordpress.com:8443/b.png', 'http://a.wordpress.com:25/c.png', 'https://user:pass@a.wordpress.com/d.png'];
        $fetched = ['http://a.wordpress.com:80/e.png', 'https://a.wordpress.com:443/f.png', 'http://a.wordpress.com/g.png'];

        [$body] = ImportedImages::bring($this->body(...$refused, ...$fetched), $this->media(), 'https://example.test/');

        foreach ($refused as $src) {
            $this->assertStringContainsString('src="' . $src . '"', $body, $src);
        }
        foreach ($fetched as $src) {
            $this->assertStringNotContainsString('src="' . $src . '"', $body, $src);
        }
        // The HTTP client leaves a default port out, as it sends the request.
        $this->assertSame(['GET http://a.wordpress.com/e.png', 'GET https://a.wordpress.com/f.png', 'GET http://a.wordpress.com/g.png'], $this->requests);
    }

    public function testAnImageAddressWrittenWithAnAmpersandEntityIsRewrittenToo(): void
    {
        [$body, $ids] = ImportedImages::bring('<p><img src="https://a.wordpress.com/p.png?w=640&amp;h=480" alt="x"></p><p><img src="https://a.wordpress.com/q.png?w=1&h=2" alt="y"></p>', $this->media(), 'https://example.test/');

        $this->assertCount(2, $ids);
        $this->assertStringNotContainsString('wordpress.com', $body);
        $this->assertSame(['GET https://a.wordpress.com/p.png?w=640&h=480', 'GET https://a.wordpress.com/q.png?w=1&h=2'], $this->requests, 'the address is fetched as the browser reads it');
        $this->assertSame('<p><img src="https://example.test/file/' . $ids[0] . '" alt="x"></p><p><img src="https://example.test/file/' . $ids[1] . '" alt="y"></p>', $body);
    }

    public function testTheSameAddressTwiceIsFetchedOnce(): void
    {
        [$body, $ids] = ImportedImages::bring($this->body('https://a.wordpress.com/a.png', 'https://a.wordpress.com/a.png'), $this->media(), 'https://example.test/');

        $this->assertCount(1, $ids);
        $this->assertSame(['GET https://a.wordpress.com/a.png'], $this->requests);
        $this->assertSame(2, substr_count($body, '/file/' . $ids[0]));
    }

    public function testTheRestOfTheHtmlIsUntouchedIncludingNonAsciiText(): void
    {
        $html = '<p>Café — “quoted” 🌊 &amp; more</p><p><img src="https://example.org/pool.jpg" alt="An overview" /></p>';

        $this->assertSame([$html, []], ImportedImages::bring($html, $this->media(), 'https://example.test/'));
    }

    public function testHtmlWithNoImageIsNotParsedAtAll(): void
    {
        $this->assertSame(['<p>text</p>', []], ImportedImages::bring('<p>text</p>', $this->media(), 'https://example.test/'));
        $this->assertSame(['', []], ImportedImages::bring('', $this->media(), 'https://example.test/'));
    }

    public function testAPictureThatCannotBeFetchedStaysWhereItIs(): void
    {
        $client = new MockHttpClient(new MockResponse('Not found', ['http_code' => 404]));
        $media = (new ImportedMedia(static fn () => $client, new FileStore(new Filesystem(new InMemoryFilesystemAdapter()), 'u'), new FakeSiteFacts($this->directory), new NullLogger()))->forDomain('wordpress.com');

        [$body, $ids] = ImportedImages::bring($this->body('https://a.wordpress.com/a.png'), $media, 'https://example.test/');

        $this->assertSame([], $ids);
        $this->assertStringContainsString('https://a.wordpress.com/a.png', $body);
    }

    public function testLibxmlsErrorHandlingIsPutBackAsItWas(): void
    {
        foreach ([true, false] as $state) {
            libxml_use_internal_errors($state);
            ImportedImages::bring('<p><img src="https://a.wordpress.com/a.png"><b></p>', $this->media(), 'https://example.test/');
            $this->assertSame($state, libxml_use_internal_errors());
        }
        libxml_use_internal_errors(false);
    }
}
