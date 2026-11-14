<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Core\Config;
use Idno\Core\PageHandler;
use Idno\Legacy\LegacyDispatcher;
use PHPUnit\Framework\TestCase;

final class LegacyDispatcherTest extends TestCase
{
    private function dispatcher(string $url, ?PageHandler $routes = null): LegacyDispatcher
    {
        $config = new Config(new BootConfig([], false));
        $config->url = $url;

        return new LegacyDispatcher($routes ?? new PageHandler(), $config);
    }

    public function testBasePathAtDomainRoot(): void
    {
        $this->assertSame('', $this->dispatcher('https://example.com/')->basePath());
    }

    public function testBasePathWithoutTrailingSlash(): void
    {
        $this->assertSame('', $this->dispatcher('https://example.com')->basePath());
    }

    public function testBasePathInSubdirectory(): void
    {
        $this->assertSame('/blog', $this->dispatcher('https://example.com/blog/')->basePath());
    }

    public function testRoutesAreAddedUnderBasePath(): void
    {
        $routes = new PageHandler();
        $routes['/'] = 'Idno\\Pages\\Homepage';

        $served = $this->dispatcher('https://example.com/blog/', $routes)->routes();

        $this->assertTrue(isset($served['/blog/']));
        $this->assertSame($served['/'], $served['/blog/']);
    }

    public function testNoExtraRouteAtDomainRoot(): void
    {
        $routes = new PageHandler();
        $routes['/'] = 'Idno\\Pages\\Homepage';

        $served = $this->dispatcher('https://example.com/', $routes)->routes();

        $this->assertSame(['/' => 'Idno\\Pages\\Homepage'], iterator_to_array($served));
    }

    public function testDispatchRefusesRoutesThatAreNotTheBootedApplications(): void
    {
        // A dispatcher from a second kernel holds that kernel's own, empty PageHandler.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Idno::site()->routes()');

        $this->dispatcher('https://example.com/')->dispatch();
    }

    /**
     * headers_list() is always empty in the CLI SAPI, so this runs LegacyResponse::send()
     * in PHP's built-in web server (Tests/Kernel/legacy_response_server.php) and inspects
     * the real response.
     */
    public function testLegacyResponseSendsNoHeaders(): void
    {
        $response = $this->serve('plain');

        $this->assertSame(200, $response['status']);
        $this->assertSame('legacy body', $response['body']);
        $this->assertSame('1', $response['headers']['x-legacy'] ?? null);
        $this->assertArrayNotHasKey('x-symfony', $response['headers']);
        $this->assertArrayNotHasKey('cache-control', $response['headers']);
        // PHP's default, not Symfony's "text/html; charset=UTF-8" from Response::prepare().
        $this->assertSame('text/html; charset=UTF-8', $response['headers']['content-type'] ?? null);
    }

    public function testLegacyResponseLeavesTheStatusToLegacyCode(): void
    {
        $response = $this->serve('status');

        $this->assertSame(418, $response['status']);
    }

    public function testLegacyResponseRunsTheCallbackForHead(): void
    {
        // Response::prepare() empties the content of a HEAD response, which for a
        // StreamedResponse means never running the callback; legacy code handles HEAD itself.
        $response = $this->serve('head', 'HEAD');

        $this->assertSame(200, $response['status']);
        $this->assertSame('ran', $response['headers']['x-callback'] ?? null);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function serve(string $scenario, string $method = 'GET'): array
    {
        $script = __DIR__ . '/legacy_response_server.php';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -S 127.0.0.1:' . $port . ' ' . escapeshellarg($script);
            $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2));
            $this->assertIsResource($process);
            try {
                for ($i = 0; $i < 50; $i++) {
                    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                    if ($socket !== false) {
                        fclose($socket);

                        return $this->request('http://127.0.0.1:' . $port . '/?scenario=' . $scenario, $method);
                    }
                    if (!proc_get_status($process)['running']) {
                        break; // Port taken: try another.
                    }
                    usleep(100_000);
                }
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->fail('Could not start PHP\'s built-in web server');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $url, string $method): array
    {
        $lines = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                $lines[] = $line;

                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }
}
