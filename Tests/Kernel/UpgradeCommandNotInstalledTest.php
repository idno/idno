<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * `idno:upgrade` runs without the legacy application, whose boot is what refuses a site with no
 * configuration. On such a site it must refuse the same way as every other command (one line on
 * stderr, exit 1), BEFORE anything connects to a database.
 *
 * The real bin/console runs in a subprocess, in a scratch project directory of the test's own (in
 * a temporary directory it creates and removes) that has no configuration: a copy of `bin/`,
 * `version.idno` and `Idno/` (so the kernel's project directory is the scratch one), the real `config/` (a symlink)
 * and an autoloader that loads `Idno\` from the copy and everything else from the checkout. The
 * environment points the database at a port this test listens on: any connection attempt would
 * be accepted there and fail the test.
 */
final class UpgradeCommandNotInstalledTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $checkout = dirname(__DIR__, 2);
        $this->root = (string) realpath(sys_get_temp_dir()) . '/idno-unconfigured-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/bin', 0777, true);
        mkdir($this->root . '/vendor');
        copy($checkout . '/bin/console', $this->root . '/bin/console');
        copy($checkout . '/version.idno', $this->root . '/version.idno');
        exec('cp -R ' . escapeshellarg($checkout . '/Idno') . ' ' . escapeshellarg($this->root . '/Idno'), $output, $code);
        $this->assertSame(0, $code, 'copying Idno/');
        symlink($checkout . '/config', $this->root . '/config');
        $autoload = '<?php' . "\n"
            . '$loader = require ' . self::literal($checkout . '/vendor/autoload.php') . ";\n"
            . '$loader->setPsr4(\'Idno\\\\\', [' . self::literal($this->root . '/Idno/') . "]);\n"
            . "return \$loader;\n";
        file_put_contents($this->root . '/vendor/autoload.php', $autoload);
    }

    /**
     * A PHP string literal of a path (a temporary directory: no `$` and no backslash).
     */
    private static function literal(string $path): string
    {
        return (string) json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function commands(): array
    {
        return [
            'idno:upgrade' => [['idno:upgrade']],
            'idno:upgrade --status' => [['idno:upgrade', '--status']],
            'another command (the control)' => [['list']],
        ];
    }

    /**
     * @dataProvider commands
     *
     * @param list<string> $arguments
     */
    public function testASiteWithNoConfigurationIsRefusedBeforeAnythingConnects(array $arguments): void
    {
        $this->assertFileDoesNotExist($this->root . '/configuration/config.ini');
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $address = (string) stream_socket_get_name($listener, false);
        $port = substr($address, strrpos($address, ':') + 1);

        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', $this->root . '/bin/console'], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            [
                'APP_ENV' => 'prod',
                'IDNO_CACHE_DIR' => $this->root . '/cache',
                'IDNO_DATABASE' => 'mysql',
                'IDNO_DBHOST' => '127.0.0.1',
                'IDNO_DBPORT' => $port,
                'IDNO_DBNAME' => 'idno',
                'PATH' => (string) getenv('PATH'),
            ],
        );
        $this->assertIsResource($process);
        // Until the process ends: a connection to the listener is recorded, and closed at once so
        // that the process fails instead of waiting for a server's greeting.
        $connected = false;
        $deadline = microtime(true) + 60;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            $client = @stream_socket_accept($listener, 0.1);
            if ($client !== false) {
                $connected = true;
                fclose($client);
            }
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $client = @stream_socket_accept($listener, 0);
        $connected = $connected || $client !== false;
        fclose($listener);

        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stdout);
        $this->assertSame("Idno is not installed yet: run the installer (warmup/ in a browser, or warmup/CLI/CLIInstaller.php) first.\n", $stderr);
        $this->assertFalse($connected, 'nothing connected to the database');
    }
}
