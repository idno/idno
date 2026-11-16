<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * Command-line scripts refuse to run under a web SAPI: if a web server is misconfigured and
 * executes one, it answers 404 with an empty body before loading anything. The routers for
 * PHP's built-in web server refuse to run anywhere else.
 */
final class SapiGuardTest extends TestCase
{
    private ?string $cacheDir = null;

    protected function tearDown(): void
    {
        if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
            exec('rm -rf ' . escapeshellarg($this->cacheDir));
        }
        $this->cacheDir = null;
    }

    private static function phpCgi(): ?string
    {
        $candidates = [dirname(PHP_BINARY) . '/php-cgi', trim((string) shell_exec('command -v php-cgi 2>/dev/null'))];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Runs a script under the given binary with a fresh, not-yet-created cache directory.
     *
     * @return array{0: string, 1: bool} the output (headers and body) and whether the cache directory was created
     */
    private function runScript(string $binary, string $script): array
    {
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-sapi-' . bin2hex(random_bytes(6));
        $command = 'IDNO_CACHE_DIR=' . escapeshellarg($this->cacheDir) . ' '
            . escapeshellarg($binary) . ' -d error_reporting=22527 -d display_errors=1 '
            . escapeshellarg(dirname(__DIR__, 2) . '/' . $script) . ' 2>&1';
        $output = (string) shell_exec($command);

        return [$output, is_dir($this->cacheDir)];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function commandLineScripts(): array
    {
        return [
            'bin/console' => ['bin/console'],
            'idno.php' => ['idno.php'],
            'known.php' => ['known.php'],
            'boot_routes.php' => ['Tests/Kernel/boot_routes.php'],
            'boot_cache_dir.php' => ['Tests/Kernel/boot_cache_dir.php'],
            '_bootstrap.php' => ['Tests/_bootstrap.php'],
            'rig SQLite schema loader' => ['Tests/rig/sqlite_schema.php'],
            'SQLite concurrency writer' => ['Tests/Storage/sqlite_writer.php'],
        ];
    }

    /**
     * @dataProvider commandLineScripts
     */
    public function testCommandLineScriptExitsBeforeBootingUnderCgi(string $script): void
    {
        $cgi = self::phpCgi();
        if ($cgi === null) {
            $this->markTestSkipped('php-cgi is not installed, so a web SAPI cannot be simulated.');
        }

        [$output, $cacheCreated] = $this->runScript($cgi, $script);

        [$headers, $body] = array_pad(explode("\r\n\r\n", $output, 2), 2, '');
        $this->assertStringContainsString('Status: 404', $headers, $output);
        $this->assertSame('', $body, 'the body must be empty');
        $this->assertFalse($cacheCreated, 'the kernel must not have booted');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function builtInServerScripts(): array
    {
        return [
            'rig router' => ['Tests/rig/router.php'],
            'CI router' => ['Tests/build/router.php'],
            'legacy response server' => ['Tests/Kernel/legacy_response_server.php'],
        ];
    }

    /**
     * @dataProvider builtInServerScripts
     */
    public function testBuiltInServerScriptRunsOnlyUnderTheBuiltInServer(string $script): void
    {
        [$output, $cacheCreated] = $this->runScript(PHP_BINARY, $script);
        $this->assertSame('', $output, 'under the CLI it must print nothing');
        $this->assertFalse($cacheCreated);

        $cgi = self::phpCgi();
        if ($cgi === null) {
            $this->markTestSkipped('php-cgi is not installed, so a web SAPI cannot be simulated.');
        }
        [$output, $cacheCreated] = $this->runScript($cgi, $script);
        [$headers, $body] = array_pad(explode("\r\n\r\n", $output, 2), 2, '');
        $this->assertStringContainsString('Status: 404', $headers, $output);
        $this->assertSame('', $body);
        $this->assertFalse($cacheCreated);
    }
}
