<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The web installer's check that URL rewriting works: a GET of the site's own js/canary.js counts
 * only when it answers 200 to 299. Nothing here touches the network.
 */
final class UrlAnswersTest extends TestCase
{
    /**
     * @return array<string, array{int, bool}>
     */
    public static function statuses(): array
    {
        return [
            '200' => [200, true],
            '204' => [204, true],
            '299' => [299, true],
            '199' => [199, false],
            'a redirect is not followed' => [302, false],
            '404' => [404, false],
            '500' => [500, false],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testOnlyA2xxStatusCounts(int $status, bool $expected): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests, $status) {
            $requests[] = [$method, $url];
            return new MockResponse('', ['http_code' => $status]);
        });

        $installer = new InstallerUnderTest(sys_get_temp_dir());

        $this->assertSame($expected, $installer->answers($client, 'https://example.test/js/canary.js'));
        $this->assertSame([['GET', 'https://example.test/js/canary.js']], $requests);
    }

    public function testARequestThatFailsIsFalse(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'SSL certificate problem']));

        $this->assertFalse((new InstallerUnderTest(sys_get_temp_dir()))->answers($client, 'https://example.test/js/canary.js'));
    }
}
