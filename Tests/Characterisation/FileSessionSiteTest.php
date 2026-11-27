<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;
use Tests\Support\WebServer;

/**
 * A site whose sessions are PHP files, on a PHP that names no session directory: its sessions are in a private directory of
 * the site's own (not PHP's shared one, whose garbage collection once stalled requests for tens of seconds), with the
 * collection on, and the console never makes that directory. The site's server sets `session.save_path` to nothing; the
 * directory is named by `IDNO_SESSION_DIR`.
 */
final class FileSessionSiteTest extends PrivateSiteTestCase
{
    use LogsIn;

    private string $ownDirectory = '';

    protected function siteEnvironment(): array
    {
        $this->ownDirectory = $this->ownDirectory !== '' ? $this->ownDirectory : sys_get_temp_dir() . '/idno-own-sessions-' . bin2hex(random_bytes(6));

        return ['IDNO_SESSION_DIR' => $this->ownDirectory];
    }

    protected function siteIni(): array
    {
        return ['session.save_path' => ''];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all()) !== 'sqlite') {
            $this->markTestSkipped('A MySQL or MariaDB site keeps its sessions in the database: there are no session files.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownDirectory !== '') {
            WebServer::removeTree($this->ownDirectory);
        }
        parent::tearDown();
    }

    // Catches file sessions staying in PHP's shared directory, or collected by a host that turns collection off.
    public function testTheSessionIsAFileInTheSitesOwnDirectory(): void
    {
        $first = $this->site()->request('GET', '_test/session');
        $cookie = self::sessionCookie($first);
        $answer = json_decode($first->body, true);

        $this->assertSame('files', $answer['module']);
        $this->assertSame($this->ownDirectory, $answer['save_path']);
        $this->assertSame(1, $answer['gc_probability']);
        $this->assertSame(1000, $answer['gc_divisor']);
        $this->assertSame(0700, fileperms($this->ownDirectory) & 0777);
        $id = substr($cookie, strpos($cookie, '=') + 1);
        $this->assertFileExists($this->ownDirectory . '/sess_' . $id);
    }

    // Catches a request that keeps writing to the system's temporary directory beside the site's own.
    public function testNoSessionFileIsWrittenToThePhpTemporaryDirectory(): void
    {
        $before = glob(sys_get_temp_dir() . '/sess_*') ?: [];
        for ($i = 0; $i < 5; ++$i) {
            $this->site()->request('GET', '_test/session');
        }
        $after = glob(sys_get_temp_dir() . '/sess_*') ?: [];

        $this->assertSame([], array_values(array_diff($after, $before)));
        $this->assertNotEmpty(glob($this->ownDirectory . '/sess_*'), 'the sessions went to the site\'s own directory');
    }

    // Catches the new directory losing a signed-in session between requests (a wrong owner or mode check, a collection that removes live files).
    public function testASignedInMemberStaysSignedInAcrossRequests(): void
    {
        $cookie = $this->registerUser('ada');

        for ($i = 0; $i < 3; ++$i) {
            $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status);
        }
    }

    // Catches the console (or a queue worker, often another Unix user) making the directory, which it would then own against the web server.
    public function testTheConsoleCreatesNoDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->ownDirectory);

        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'session.save_path=', '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__, 2) . '/bin/console', 'list'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $this->environment()
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $stderr);

        $this->assertStringContainsString('Available commands', (string) $stdout);
        $this->assertDirectoryDoesNotExist($this->ownDirectory);
        $this->assertStringNotContainsString('session', strtolower((string) $stderr), 'nothing was warned');

        $this->site()->request('GET', '_test/session');
        $this->assertDirectoryExists($this->ownDirectory);
        $this->assertSame(function_exists('posix_geteuid') ? posix_geteuid() : fileowner($this->ownDirectory), fileowner($this->ownDirectory), 'owned by the server\'s user');
    }
}
