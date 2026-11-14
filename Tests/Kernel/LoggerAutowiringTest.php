<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Logging;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\LoggerConsumer;

final class LoggerAutowiringTest extends TestCase
{
    private string $logFile;
    private string|false $previousErrorLog;
    private int $previousLevel;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'idno-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
        $this->previousLevel = Idno::site()->logging()->loglevel_filter;
    }

    protected function tearDown(): void
    {
        Idno::site()->logging()->setLogLevel($this->previousLevel);
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    /**
     * The kernel that booted the legacy application is the only one whose legacy services
     * are Idno::site()'s, so the fixture is fetched from its container.
     */
    private function fixture(): LoggerConsumer
    {
        $fixture = Idno::container()->get(LoggerConsumer::class);
        $this->assertInstanceOf(LoggerConsumer::class, $fixture);

        return $fixture;
    }

    public function testLoggerInterfaceAutowiresToTheLegacyLogger(): void
    {
        $this->assertSame(Idno::site()->logging(), $this->fixture()->logger);
    }

    public function testAutowiredLoggerHonoursTheSiteLogLevel(): void
    {
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_ERROR);
        $this->fixture()->logger->error('written');
        $this->assertCount(1, file($this->logFile, FILE_IGNORE_NEW_LINES));

        file_put_contents($this->logFile, '');
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_OFF);
        $this->fixture()->logger->error('nope');

        $this->assertSame('', (string) file_get_contents($this->logFile));
    }

    public function testAutowiredLoggerUsesTheLegacyLineFormat(): void
    {
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_ERROR);
        $this->fixture()->logger->error('pinned');

        $lines = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $lines[] = preg_replace('/^\[[^\]]+\] /', '', $line);
        }
        $host = Idno::site()->config()->host;
        $this->assertSame(["Idno ($host): error - pinned"], $lines);
    }

    public function testFrameworkLoggerServiceIsUnchanged(): void
    {
        // "logger" is private; config/services_test.php exposes it under a public test-only alias.
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        try {
            $logger = $kernel->getContainer()->get('idno.test.framework_logger');
            $container = $kernel->getContainer();
            $this->assertNotInstanceOf(Logging::class, $logger);
            $this->assertSame($container->get('idno.test.monolog_logger'), $logger);
            $this->assertNotSame($container->get('monolog.logger.idno'), $logger);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testLoggingServiceIsNotConstructedByKernelBootAlone(): void
    {
        $base = sys_get_temp_dir() . '/idno-test-logging-' . bin2hex(random_bytes(6));
        $command = 'IDNO_CACHE_DIR=' . escapeshellarg($base) . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 '
            . escapeshellarg(__DIR__ . '/boot_logging_uninitialised.php') . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $this->removeDir($base);

        $this->assertSame(0, $code, 'Subprocess failed: ' . implode("\n", $output));
        $this->assertSame(['logging_initialized' => false], json_decode(implode("\n", $output), true), implode("\n", $output));
    }

    public function testFixtureServiceExistsInTestButNotInProd(): void
    {
        $this->assertSame(['has' => true], $this->hasFixtureIn('test', true));
        $this->assertSame(['has' => false], $this->hasFixtureIn('prod', false));
    }

    public function testLoggerNeedsTheLegacyApplicationToHaveBooted(): void
    {
        $base = sys_get_temp_dir() . '/idno-test-logging-early-' . bin2hex(random_bytes(6));
        $script = 'require "vendor/autoload.php"; $k = new \\Idno\\Kernel("test", true, bootLegacy: false); $k->boot();'
            . ' try { $k->getContainer()->get(\'' . addslashes(LoggerConsumer::class) . '\'); echo json_encode(["thrown" => null]); }'
            . ' catch (\\Throwable $e) { echo json_encode(["thrown" => get_class($e), "message" => $e->getMessage()]); }';
        $output = [];
        $code = 0;
        $command = 'cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && IDNO_CACHE_DIR=' . escapeshellarg($base) . ' '
            . escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -r ' . escapeshellarg($script) . ' 2>&1';
        exec($command, $output, $code);
        $this->removeDir($base);

        $this->assertSame(0, $code, 'Subprocess failed: ' . implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, implode("\n", $output));
        $this->assertSame(\LogicException::class, $decoded['thrown']);
        $this->assertStringStartsWith('The logger needs the legacy application, which has not booted yet.', $decoded['message']);
        $this->assertStringContainsString('bootLegacy: false', $decoded['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function hasFixtureIn(string $environment, bool $debug): array
    {
        $base = sys_get_temp_dir() . '/idno-test-logging-env-' . bin2hex(random_bytes(6));
        $script = 'require "vendor/autoload.php"; $k = new \\Idno\\Kernel("' . $environment . '", ' . ($debug ? 'true' : 'false') . ', bootLegacy: false); $k->boot();'
            . ' echo json_encode(["has" => $k->getContainer()->has(\'' . addslashes(LoggerConsumer::class) . '\')]);';
        $output = [];
        $code = 0;
        $command = 'cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && IDNO_CACHE_DIR=' . escapeshellarg($base) . ' '
            . escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -r ' . escapeshellarg($script) . ' 2>&1';
        exec($command, $output, $code);
        $this->removeDir($base);

        $this->assertSame(0, $code, 'Subprocess failed: ' . implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, implode("\n", $output));

        return $decoded;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
