<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Core\Installer;
use PHPUnit\Framework\TestCase;

/**
 * The command-line installer's `makeconfig` command (which writes nothing but its output) runs the same
 * check of an SQLite path as the installation does, and the installer says who must own the files.
 */
final class CliInstallerTest extends TestCase
{
    private string $manifest = '';

    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir() . '/idno-cli-manifest-' . bin2hex(random_bytes(6)) . '.ini';
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifest)) {
            unlink($this->manifest);
        }
    }

    /**
     * @return array{int, string}
     */
    private function makeconfig(string $sqlitePath): array
    {
        file_put_contents($this->manifest, "database = sqlite\nsqlite_path = \"$sqlitePath\"\nupload_path = \"/tmp/uploads/\"\n");
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 ' . escapeshellarg(dirname(__DIR__, 3) . '/warmup/CLI/CLIInstaller.php')
            . ' makeconfig ' . escapeshellarg($this->manifest) . ' 2>&1';
        exec($command, $lines, $status);

        return [$status, implode("\n", $lines)];
    }

    public function testMakeconfigRefusesADatabaseNamedLikeAScript(): void
    {
        foreach (['shell.php', '../idno.sqlite', 'data/idno.db'] as $path) {
            [$status, $output] = $this->makeconfig($path);

            self::assertNotSame(0, $status, $path . ': ' . $output);
            self::assertStringNotContainsString('dbname', $output);
        }
    }

    public function testMakeconfigWritesAValidPath(): void
    {
        [$status, $output] = $this->makeconfig('data/site.sqlite');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('/data/site.sqlite', $output);
    }

    public function testTheOwnershipNotesNameTheFilesTheDirectoryAndTheWebServersUser(): void
    {
        $text = implode("\n", Installer::sqliteOwnershipNotes('/srv/idno/data/idno.sqlite'));

        self::assertStringContainsString('/srv/idno/data/idno.sqlite', $text);
        self::assertStringContainsString('chown -R www-data /srv/idno/data', $text);
        self::assertStringContainsString('.upgrade.lock', $text);
        self::assertStringContainsString('-wal', $text);
        self::assertStringContainsString('as that user', $text);
    }
}
