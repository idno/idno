<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Webservice;
use IdnoPlugins\ActivityPub\RemoteActor;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\IdnoTestCase;

/**
 * A URL a remote party supplied that points at this machine or its private network is refused by
 * the first check (UrlValidator::isSafeUrl()) before any request is made, whichever client would
 * have made it. (What the remote client refuses at connect time, and on every redirect hop, is
 * proved in WebserviceAdapterTest and HttpClientServicesTest.) Both clients are mocks that count
 * what they are sent.
 */
final class RemoteFetchRefusalTest extends IdnoTestCase
{
    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seen = [];
        $make = function (): MockHttpClient {
            return new MockHttpClient(function (string $method, string $url): MockResponse {
                $this->seen[] = $method . ' ' . $url;

                return new MockResponse('{"id":"x"}', ['http_code' => 200]);
            });
        };
        Webservice::useClient($make(), $make());
    }

    protected function tearDown(): void
    {
        Webservice::useClient(null);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'loopback'            => ['http://127.0.0.1:8089/secret'],
            'loopback IPv6'       => ['http://[::1]/secret'],
            'private 10/8'        => ['http://10.0.0.1/secret'],
            'private 192.168/16'  => ['http://192.168.1.1/secret'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'file scheme'         => ['file:///etc/passwd'],
        ];
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function testAnActorOnThisMachineIsNotFetched(string $url): void
    {
        $this->assertFalse(RemoteActor::fetch($url));
        $this->assertSame([], $this->seen);
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function testALinkOnThisMachineIsNotUnfurled(string $url): void
    {
        $this->assertFalse((new \Idno\Entities\UnfurledUrl())->unfurl($url));
        $this->assertSame([], $this->seen);
    }

    /**
     * @dataProvider unsafeUrls
     */
    public function testAMicropubFileOnThisMachineIsNotDownloaded(string $url): void
    {
        $files = $_FILES;
        $_FILES = [];
        try {
            $endpoint = (new \ReflectionClass(\IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::class))->newInstanceWithoutConstructor();
            $result = (new \ReflectionMethod($endpoint, 'uploadFromUrl'))->invoke($endpoint, 'photo', [$url]);

            $this->assertEmpty($result);
            $this->assertArrayNotHasKey('photo', $_FILES);
            $this->assertSame([], $this->seen);
        } finally {
            $_FILES = $files;
        }
    }
}
