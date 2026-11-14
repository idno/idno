<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * Symfony Console 8 requires command callbacks to return an int. These tests run
 * the real legacy CLI in a subprocess (read-only commands only).
 */
final class ConsoleCompatibilityTest extends TestCase
{
    /**
     * @return array{0: int, 1: string}
     */
    private function runCli(string $script, string ...$args): array
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg($root . '/' . $script)
            . implode('', array_map(static fn (string $a): string => ' ' . escapeshellarg($a), $args));

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            // IDNO_PROXY_STRING sends every outbound request (the "version" command's check for a
            // newer build on GitHub) to a closed local port, so it fails at once and never leaves the machine.
            ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'IDNO_PROXY_STRING' => '127.0.0.1:9', 'PATH' => (string) getenv('PATH')]
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertStringNotContainsString('must return an integer', $stdout . $stderr);

        return [$code, $stdout];
    }

    public function testVersionCommandExitsZeroAndPrintsVersion(): void
    {
        [$code, $out] = $this->runCli('idno.php', 'version');

        $this->assertSame(0, $code);
        $version = parse_ini_file(dirname(__DIR__, 2) . '/version.idno');
        $this->assertStringContainsString("version = '" . $version['version'] . "'", $out);
    }

    public function testListCommandShowsConsolePluginCommands(): void
    {
        [$code, $out] = $this->runCli('idno.php', 'list');

        $this->assertSame(0, $code);
        foreach (['example', 'export', 'service-event-queue', 'version'] as $name) {
            $this->assertMatchesRegularExpression('/^\s+' . preg_quote($name, '/') . '\s/m', $out, "$name not listed");
        }
    }

    public function testLegacyPluginCommandReturningNothingExitsZero(): void
    {
        [$code, $out] = $this->runCli('idno.php', 'example', 'hello');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello', $out);
    }

    public function testInstallerCheckRequirementsExitsZero(): void
    {
        [$code, $out] = $this->runCli('warmup/CLI/CLIInstaller.php', 'check-requirements');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Checking requirements', $out);
    }

    public function testInstallerGenerateManifestExitsZero(): void
    {
        $file = sys_get_temp_dir() . '/idno-manifest-' . bin2hex(random_bytes(6)) . '.ini';
        try {
            [$code] = $this->runCli('warmup/CLI/CLIInstaller.php', 'generate-manifest', $file);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('site_title', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }
}
