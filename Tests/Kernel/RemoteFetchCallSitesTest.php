<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Webservice;
use IdnoPlugins\ActivityPub\RemoteActor;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\IdnoTestCase;

/**
 * Each place the site fetches a URL that a remote party supplied sends the request through the
 * client that refuses private networks on every hop (Webservice::safeSend()), never the site's own.
 * The sites whose first check (UrlValidator::isSafeUrl()) refuses the rig's own address cannot be
 * driven over HTTP, so each one's fetch is a small method that is called here on mock clients: the
 * remote client received the request, the site client none. Tests/Characterisation/RemoteFetchTest
 * proves the flows that can be driven on the rig.
 */
final class RemoteFetchCallSitesTest extends IdnoTestCase
{
    private string $previousErrorLog = '';
    private string $errorLog = '';

    /** @var list<string> "METHOD url" of what the site client was sent */
    private array $siteSeen = [];

    /** @var list<string> "METHOD url" of what the remote client was sent */
    private array $remoteSeen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-remote-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
        $this->siteSeen = [];
        $this->remoteSeen = [];
        $this->forgetMentionClient();
    }

    /**
     * The webmention client a test left behind (`Webmention::$mentionClient` is static) is not the next test's.
     */
    private function forgetMentionClient(): void
    {
        (new \ReflectionProperty(\Idno\Core\Webmention::class, 'mentionClient'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Webservice::useClient(null);
        $this->forgetMentionClient();
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        parent::tearDown();
    }

    /**
     * Installs a site client and a remote client that both answer with $respond (given the method and
     * URL) and remember what they were sent.
     */
    private function useClients(callable $respond): void
    {
        $make = function (array &$seen) use ($respond): MockHttpClient {
            return new MockHttpClient(function (string $method, string $url) use (&$seen, $respond): MockResponse {
                $seen[] = $method . ' ' . $url;

                return $respond($method, $url);
            });
        };
        Webservice::useClient($make($this->siteSeen), $make($this->remoteSeen));
    }

    private function assertOnlyTheRemoteClientWasUsed(int $requests = 1): void
    {
        $this->assertSame([], $this->siteSeen, 'the site client was not used');
        $this->assertCount($requests, $this->remoteSeen, 'the remote client was used: ' . implode(', ', $this->remoteSeen));
    }

    private function page(string $endpoint): MockResponse
    {
        return new MockResponse(
            '<html><head><link rel="webmention" href="' . $endpoint . '"></head><body class="h-entry">x</body></html>',
            ['http_code' => 200, 'response_headers' => ['Content-Type: text/html']]
        );
    }

    public function testAWebmentionsSourceIsFetchedThroughTheRemoteClient(): void
    {
        $this->useClients(fn() => new MockResponse('<a href="https://example.test/t">t</a>', ['http_code' => 200]));

        $result = \Idno\Pages\Webmentions\Endpoint::fetchSource('https://source.example/post');

        $this->assertSame(200, $result['response']);
        $this->assertSame('<a href="https://example.test/t">t</a>', $result['content']);
        $this->assertSame(['GET https://source.example/post'], $this->remoteSeen);
        $this->assertOnlyTheRemoteClientWasUsed();
    }

    public function testTheEndpointOfAWebmentionTargetIsDiscoveredThroughTheRemoteClient(): void
    {
        $this->useClients(fn() => $this->page('https://target.example/endpoint'));

        $endpoint = \Idno\Core\Webmention::supportsMentions('https://target.example/page');

        $this->assertSame('https://target.example/endpoint', $endpoint);
        $this->assertSame([], $this->siteSeen, 'the site client was not used');
        $this->assertNotSame([], $this->remoteSeen, 'the remote client was used');
    }

    public function testAWebmentionIsSentThroughTheRemoteClient(): void
    {
        $page = $this->page('https://target.example/endpoint');
        $this->useClients(fn(string $method) => $method === 'POST' ? new MockResponse('', ['http_code' => 202]) : $page);

        \Idno\Core\Webmention::sendWebmentionPayload('https://me.example/post', 'https://target.example/page');

        $this->assertSame([], $this->siteSeen, 'the site client was not used');
        $this->assertContains('POST https://target.example/endpoint', $this->remoteSeen);
    }

    public function testAHomepageIconIsFetchedThroughTheRemoteClient(): void
    {
        $this->useClients(fn() => new MockResponse('<html><body>none</body></html>', ['http_code' => 200]));

        \Idno\Core\Webmention::getIconFromURL('https://home.example/');

        $this->assertSame(['GET https://home.example/'], $this->remoteSeen);
        $this->assertOnlyTheRemoteClientWasUsed();
    }

    public function testAnActivityPubActorIsFetchedThroughTheRemoteClient(): void
    {
        $this->useClients(fn() => new MockResponse('{"id":"https://remote.example/actor"}', ['http_code' => 200]));

        $result = RemoteActor::fetchDocument('https://remote.example/actor', ['Accept: application/activity+json']);

        $this->assertSame(200, $result['response']);
        $this->assertSame(['GET https://remote.example/actor'], $this->remoteSeen);
        $this->assertOnlyTheRemoteClientWasUsed();
    }

    public function testAMicropubMediaUrlIsFetchedThroughTheRemoteClient(): void
    {
        $this->useClients(fn() => new MockResponse('IMAGEBYTES', ['http_code' => 200]));

        $bytes = \IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::fetchMedia('https://media.example/a.jpg');

        $this->assertSame('IMAGEBYTES', $bytes);
        $this->assertSame(['GET https://media.example/a.jpg'], $this->remoteSeen);
        $this->assertOnlyTheRemoteClientWasUsed();
    }

    public function testAMicropubMediaUrlThatDoesNotAnswerWithSuccessGivesNothing(): void
    {
        $this->useClients(fn() => new MockResponse('nope', ['http_code' => 404]));

        $this->assertFalse(\IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::fetchMedia('https://media.example/missing.jpg'));
        $this->assertOnlyTheRemoteClientWasUsed();
    }

    public function testAnUnfurledUrlIsFetchedThroughTheRemoteClient(): void
    {
        $this->useClients(fn() => new MockResponse(
            '<html><head><meta property="og:title" content="A page"></head><body></body></html>',
            ['http_code' => 200]
        ));

        // A literal public address: the first check resolves the host, and a literal needs no lookup.
        $unfurled = new \Idno\Entities\UnfurledUrl();
        $this->assertTrue($unfurled->unfurl('http://93.184.216.34/page'));

        $this->assertSame(['GET http://93.184.216.34/page'], $this->remoteSeen);
        $this->assertOnlyTheRemoteClientWasUsed();
    }

    /**
     * @return list<string> the temporary files Micropub makes for downloaded media
     */
    private function mediaTempFiles(): array
    {
        return glob(sys_get_temp_dir() . '/indiepub_*') ?: [];
    }

    /**
     * Runs the Micropub download of $url into $_FILES and returns the result, restoring $_FILES.
     */
    private function uploadFromUrl(string $url): mixed
    {
        $endpoint = (new \ReflectionClass(\IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::class))->newInstanceWithoutConstructor();
        $files = $_FILES;
        try {
            return (new \ReflectionMethod($endpoint, 'uploadFromUrl'))->invoke($endpoint, 'photo', [$url]);
        } finally {
            foreach ($_FILES['photo'] ?? [] as $file) {
                @unlink($file['tmp_name']);
            }
            $_FILES = $files;
        }
    }

    public function testAFailedMicropubDownloadLeavesNoTemporaryFile(): void
    {
        $before = $this->mediaTempFiles();

        foreach ([404, 500] as $status) {
            $this->useClients(fn() => new MockResponse('nope', ['http_code' => $status]));
            $this->assertFalse($this->uploadFromUrl('http://93.184.216.34/a.jpg'), (string) $status);
        }
        $this->useClients(fn() => new MockResponse('', ['http_code' => 200]));
        $this->assertEmpty($this->uploadFromUrl('http://93.184.216.34/empty.jpg'), 'an empty body');

        $this->assertSame($before, $this->mediaTempFiles());
    }

    public function testASuccessfulMicropubDownloadMakesTheFileItReports(): void
    {
        $this->useClients(fn() => new MockResponse('IMAGEBYTES', ['http_code' => 200]));

        $_FILES = [];
        $endpoint = (new \ReflectionClass(\IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::class))->newInstanceWithoutConstructor();
        try {
            $this->assertNotFalse((new \ReflectionMethod($endpoint, 'uploadFromUrl'))->invoke($endpoint, 'photo', ['http://93.184.216.34/a.jpg']));
            $this->assertCount(1, $_FILES['photo']);
            $this->assertSame('IMAGEBYTES', file_get_contents($_FILES['photo'][0]['tmp_name']));
            $this->assertSame('a.jpg', $_FILES['photo'][0]['name']);
            $this->assertOnlyTheRemoteClientWasUsed();
        } finally {
            foreach ($_FILES['photo'] ?? [] as $file) {
                @unlink($file['tmp_name']);
            }
            $_FILES = [];
        }
    }
}
