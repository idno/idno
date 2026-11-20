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

    /** The directories whose PHP scripts (files that declare no class) are found by scripts(). */
    private const SCRIPT_DIRECTORIES = ['Tests/Kernel', 'Tests/rig', 'Tests/Storage', 'Tests/Unit/Upgrade/Mongo'];

    /**
     * The PHP scripts (files that declare no class, interface or trait) in SCRIPT_DIRECTORIES, from
     * the filesystem, so a new helper cannot be missed: those guarded for PHP's built-in web server
     * (`PHP_SAPI !== 'cli-server'`) are routers, every other one is a command-line script (and
     * fails testCommandLineScriptExitsBeforeBootingUnderCgi if it has no guard).
     *
     * @return array{cli: list<string>, server: list<string>} paths relative to the project root
     */
    private static function scripts(): array
    {
        $root = dirname(__DIR__, 2);
        $found = ['cli' => [], 'server' => []];
        foreach (self::SCRIPT_DIRECTORIES as $directory) {
            foreach (glob($root . '/' . $directory . '/*.php') ?: [] as $file) {
                $source = (string) file_get_contents($file);
                if (preg_match('/^\s*(final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s/m', $source)) {
                    continue;
                }
                $found[str_contains($source, "PHP_SAPI !== 'cli-server'") ? 'server' : 'cli'][] = $directory . '/' . basename($file);
            }
        }

        return $found;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function commandLineScripts(): array
    {
        $scripts = [
            'bin/console' => ['bin/console'],
            'idno.php' => ['idno.php'],
            'known.php' => ['known.php'],
            '_bootstrap.php' => ['Tests/_bootstrap.php'],
        ];
        foreach (self::scripts()['cli'] as $script) {
            $scripts[$script] = [$script];
        }

        return $scripts;
    }

    /**
     * The derived lists hold the helpers known today (a guard against a broken scan that finds
     * nothing), and every boot_*.php helper is in one of them.
     */
    public function testTheScriptListsAreDerivedFromTheFilesystem(): void
    {
        $scripts = self::scripts();
        foreach (['Tests/Kernel/boot_routes.php', 'Tests/Kernel/boot_cache_dir.php', 'Tests/Kernel/boot_db_backend.php', 'Tests/Storage/sqlite_writer.php'] as $script) {
            $this->assertContains($script, $scripts['cli']);
        }
        foreach (['Tests/rig/router.php', 'Tests/Kernel/legacy_response_server.php', 'Tests/Kernel/boot_db_unreachable.php'] as $script) {
            $this->assertContains($script, $scripts['server']);
        }
        $this->assertNotContains('Tests/Kernel/SapiGuardTest.php', [...$scripts['cli'], ...$scripts['server']], 'test classes are not scripts');
        foreach (glob(dirname(__DIR__, 2) . '/Tests/Kernel/boot_*.php') ?: [] as $file) {
            $this->assertContains('Tests/Kernel/' . basename($file), [...$scripts['cli'], ...$scripts['server']]);
        }
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
        $scripts = ['Tests/build/router.php' => ['Tests/build/router.php']];
        foreach (self::scripts()['server'] as $script) {
            $scripts[$script] = [$script];
        }

        return $scripts;
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
