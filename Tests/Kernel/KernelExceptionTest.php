<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Http\FrontController;
use Idno\Kernel;
use Idno\Legacy\LegacyResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tests\Fixtures\ThrowingController;

/**
 * Exceptions that Symfony handles (before routing, or thrown by a new-style controller):
 * Idno\Legacy\LegacyFallbackListener leaves them alone without building the legacy logger,
 * and Symfony's own log records reach PHP's error log through the error_log handler for
 * every channel except "idno" (config/packages/monolog.php).
 */
final class KernelExceptionTest extends TestCase
{
    /** A line written by Monolog's standard line formatter at warning or above. */
    private const SYMFONY_LOG_LINE = '/\] [a-z_.]+\.(WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): /';

    /**
     * Runs boot_handled_exception.php in a clean PHP process.
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the probe's result and the error-log lines
     */
    private function probe(string $scenario): array
    {
        $base = sys_get_temp_dir() . '/idno-test-exception-' . bin2hex(random_bytes(6));
        mkdir($base);
        $log = $base . '/error.log';
        $command = 'IDNO_CACHE_DIR=' . escapeshellarg($base . '/cache') . ' ' . escapeshellarg(PHP_BINARY)
            . ' -d error_reporting=22527 -d log_errors=1 -d error_log=' . escapeshellarg($log) . ' '
            . escapeshellarg(__DIR__ . '/boot_handled_exception.php') . ' ' . escapeshellarg($scenario) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        exec('rm -rf ' . escapeshellarg($base));

        $this->assertSame(0, $code, 'Subprocess failed: ' . implode("\n", $output));
        $decoded = json_decode((string) end($output), true);
        $this->assertIsArray($decoded, 'Subprocess output was not JSON: ' . implode("\n", $output));

        return [$decoded, $lines === false ? [] : $lines];
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function symfonyLines(array $lines): array
    {
        return array_values(array_filter($lines, static fn (string $line): bool => preg_match(self::SYMFONY_LOG_LINE, $line) === 1));
    }

    public function testAnExceptionTheListenerIgnoresSurfacesAsItselfWithoutTheLegacyApplication(): void
    {
        [$result, $lines] = $this->probe('before-routing');

        // Before the fix, building the listener built the legacy logger, which threw
        // "LogicException: The logger needs the legacy application" instead.
        $this->assertNull($result['thrown'], (string) $result['message']);
        $this->assertSame(500, $result['status']);
        $this->assertNotSame(LegacyResponse::class, $result['response_class']);
        $this->assertTrue($result['error_handler_unchanged']);
        $this->assertStringNotContainsString('The logger needs the legacy application', implode("\n", $lines));
    }

    public function testAnExceptionBeforeRoutingIsLoggedOnce(): void
    {
        [, $lines] = $this->probe('before-routing');

        $symfony = self::symfonyLines($lines);
        $this->assertCount(1, $symfony, implode("\n", $lines));
        $this->assertStringContainsString('RuntimeException', $symfony[0]);
        $this->assertStringContainsString('thrown before routing by a test listener', $symfony[0]);
    }

    public function testAnExceptionFromANewStyleControllerIsLoggedOnce(): void
    {
        [$result, $lines] = $this->probe('controller');

        $this->assertNull($result['thrown'], (string) $result['message']);
        $this->assertSame(500, $result['status']);
        $symfony = self::symfonyLines($lines);
        $this->assertCount(1, $symfony, implode("\n", $lines));
        $this->assertStringContainsString('RuntimeException', $symfony[0]);
        $this->assertStringContainsString(ThrowingController::MESSAGE, $symfony[0]);
    }

    public function testARequestForTheLegacyCatchAllLogsNothing(): void
    {
        [$result, $lines] = $this->probe('legacy');

        $this->assertNull($result['thrown'], (string) $result['message']);
        $this->assertSame(LegacyResponse::class, $result['response_class']);
        $this->assertSame([], $lines);
    }

    public function testHandlingAnIgnoredExceptionOnASecondKernelKeepsThePhpErrorHandler(): void
    {
        // Under PHPUnit Idno::site() exists, so before the fix the listener built a second
        // Idno\Core\Logging, whose constructor replaced the process's error handler.
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        $kernel->getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::REQUEST,
            static function (RequestEvent $event): void {
                if ($event->isMainRequest()) {
                    throw new \RuntimeException('broken before routing');
                }
            },
            4096
        );

        $log = tempnam(sys_get_temp_dir(), 'idno-test-log-');
        $previousLog = ini_set('error_log', $log);
        $before = set_error_handler(null);
        restore_error_handler();
        try {
            $response = FrontController::handle($kernel, Request::create('/'));
            $after = set_error_handler(null);
            restore_error_handler();
        } finally {
            ini_set('error_log', (string) $previousLog);
            unlink($log);
            $kernel->shutdown();
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($before, $after, 'the PHP error handler was replaced');
    }
}
