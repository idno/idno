<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Kernel\CacheDirResolver;
use Idno\Security\SessionDirectory;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebServer;

/**
 * Where a site's session files are kept when nothing names a directory: the choice, that the directory is private to the owner
 * (a session file's name is its id, so a directory others can list gives sessions away), and that PHP's own rules for a named
 * directory are left alone.
 */
final class SessionDirectoryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-sessiondir-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/project/var', 0700, true);
        mkdir($this->root . '/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        WebServer::removeTree($this->root);
    }

    private function choose(?string $configured = null, string $iniPath = '', ?string $override = null, ?string $project = null, ?string $tmp = null): array
    {
        return SessionDirectory::choose($configured, $iniPath, $override, $project ?? $this->root . '/project', $tmp ?? $this->root . '/tmp');
    }

    public function testTheConfiguredPathWins(): void
    {
        $chosen = $this->choose('/configured', '/ini', $this->root . '/override');

        $this->assertSame(['path' => '/configured', 'own' => false, 'problem' => null], $chosen);
        $this->assertDirectoryDoesNotExist($this->root . '/override');
    }

    public function testAPathPhpNamesStands(): void
    {
        // a host's own directory (Debian's) is used as PHP has it, with the host's collection: Idno sets nothing and makes nothing
        foreach (['/x', '2;0600;/x'] as $ini) {
            $this->assertSame(['path' => null, 'own' => false, 'problem' => null], $this->choose(null, $ini), $ini);
        }
        $this->assertDirectoryDoesNotExist($this->root . '/project/var/sessions');
    }

    public function testTheOverrideComesFirstOfIdnosOwn(): void
    {
        $chosen = $this->choose(null, '', $this->root . '/override');

        $this->assertSame($this->root . '/override', $chosen['path']);
        $this->assertTrue($chosen['own']);
    }

    public function testOtherwiseVarSessionsUnderTheProject(): void
    {
        $chosen = $this->choose();

        $this->assertSame($this->root . '/project/var/sessions', $chosen['path']);
        $this->assertTrue($chosen['own']);
        $this->assertNull($chosen['problem']);
    }

    public function testANotWritableVarFallsBackToAPrivateTemporaryDirectory(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write a read-only directory');
        }
        chmod($this->root . '/project/var', 0500);
        try {
            $chosen = $this->choose();
        } finally {
            chmod($this->root . '/project/var', 0700);
        }

        $this->assertTrue($chosen['own']);
        $this->assertStringStartsWith($this->root . '/tmp/idno-sessions-', (string) $chosen['path']);
        $this->assertSame(0, fileperms((string) $chosen['path']) & 0077);
    }

    public function testItIsCreatedForTheOwnerOnly(): void
    {
        $this->choose();

        $this->assertSame(0700, fileperms($this->root . '/project/var/sessions') & 0777);
    }

    // A directory group or others can list gives every session id away: it is refused, and the next candidate taken.
    public function testADirectoryOthersCanListIsRefused(): void
    {
        mkdir($this->root . '/project/var/sessions', 0755);
        chmod($this->root . '/project/var/sessions', 0755);

        $chosen = $this->choose();

        $this->assertNotSame($this->root . '/project/var/sessions', $chosen['path']);
        $this->assertStringStartsWith($this->root . '/tmp/idno-sessions-', (string) $chosen['path'], 'the next candidate');

        // with every candidate refused there is no path, and the problem names the settings, never a directory
        chmod($this->root . '/tmp', 0755);
        mkdir($this->root . '/override', 0755);
        chmod($this->root . '/override', 0755);
        $tmp = $this->root . '/tmp';
        $hash = substr(hash('sha256', (string) realpath($this->root . '/project')), 0, 12);
        chmod($tmp . '/idno-sessions-' . $hash, 0755); // made by the first call above
        $none = $this->choose(null, '', $this->root . '/override');

        $this->assertNull($none['path']);
        $this->assertFalse($none['own']);
        $this->assertStringContainsString('session_path', (string) $none['problem']);
        $this->assertStringContainsString('IDNO_SESSION_DIR', (string) $none['problem']);
        $this->assertStringNotContainsString($this->root, (string) $none['problem']);
    }

    public function testALinkIsRefused(): void
    {
        mkdir($this->root . '/elsewhere', 0700);
        symlink($this->root . '/elsewhere', $this->root . '/project/var/sessions');

        $chosen = $this->choose();

        $this->assertNotSame($this->root . '/project/var/sessions', $chosen['path']);
    }

    // Two checkouts must never share a fallback directory (and so each other's sessions).
    public function testTwoProjectsGetTwoTemporaryDirectories(): void
    {
        // two projects with no var/ directory: both fall to the temporary directory
        mkdir($this->root . '/other', 0700);
        mkdir($this->root . '/project2', 0700);
        $a = $this->tmpFallback($this->root . '/other');
        $b = $this->tmpFallback($this->root . '/project2');

        $this->assertNotNull($a);
        $this->assertNotSame($a, $b);
    }

    private function tmpFallback(string $project): ?string
    {
        // a project with no var/ at all has no project candidate
        return SessionDirectory::choose(null, '', null, $project, $this->root . '/tmp')['path'];
    }

    // The shared rule the cache pool uses is not tightened by this: a directory the owner made 0755 is still a usable cache.
    public function testTheCacheDirectoryRuleIsUnchanged(): void
    {
        $directory = $this->root . '/cache';
        mkdir($directory, 0755);
        chmod($directory, 0755);

        $this->assertTrue(CacheDirResolver::usableAndPrivate($directory, requireOwner: true));
    }
}
