<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

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

    // A directory the owner made readable by others gives every session id away: it is tightened to 0700 and used, not refused (that moved every session to another directory on every request).
    public function testAnOwnedDirectoryOthersCanListIsTightenedAndUsed(): void
    {
        mkdir($this->root . '/project/var/sessions', 0755);
        chmod($this->root . '/project/var/sessions', 0755);

        $chosen = $this->choose();

        $this->assertSame($this->root . '/project/var/sessions', $chosen['path']);
        $this->assertTrue($chosen['own']);
        $this->assertNull($chosen['problem']);
        clearstatcache();
        $this->assertSame(0, fileperms($this->root . '/project/var/sessions') & 0077);
    }

    // With every candidate unusable there is no path, and the problem names the settings, never a directory.
    public function testWithNoCandidateUsableThereIsNoPathAndTheProblemNamesTheSettings(): void
    {
        mkdir($this->root . '/elsewhere', 0700);
        $hash = substr(hash('sha256', (string) realpath($this->root . '/project')), 0, 12);
        symlink($this->root . '/elsewhere', $this->root . '/override');
        symlink($this->root . '/elsewhere', $this->root . '/project/var/sessions');
        symlink($this->root . '/elsewhere', $this->root . '/tmp/idno-sessions-' . $hash);

        $none = $this->choose(null, '', $this->root . '/override');

        $this->assertNull($none['path']);
        $this->assertFalse($none['own']);
        $this->assertStringContainsString('session_path', (string) $none['problem']);
        $this->assertStringContainsString('IDNO_SESSION_DIR', (string) $none['problem']);
        $this->assertStringNotContainsString($this->root, (string) $none['problem']);
    }

    // A relative override resolves against whatever the process's working directory is (different under php -S, FPM and the command line): refused, and said.
    public function testARelativeOverrideIsRefusedAndSaid(): void
    {
        $chosen = $this->choose(null, '', 'relative-sessions');

        $this->assertSame($this->root . '/project/var/sessions', $chosen['path'], 'the next candidate');
        $this->assertStringContainsString('IDNO_SESSION_DIR', (string) $chosen['problem']);
        $this->assertStringNotContainsString($this->root, (string) $chosen['problem']);
        $this->assertDirectoryDoesNotExist('relative-sessions');
    }

    // An explicit override that cannot be used must not be replaced in silence: the operator believes sessions are there.
    public function testAnUnusableOverrideIsSaidAndTheNextCandidateTaken(): void
    {
        mkdir($this->root . '/elsewhere', 0700);
        symlink($this->root . '/elsewhere', $this->root . '/override');

        $chosen = $this->choose(null, '', $this->root . '/override');

        $this->assertSame($this->root . '/project/var/sessions', $chosen['path']);
        $this->assertStringContainsString('IDNO_SESSION_DIR', (string) $chosen['problem']);
        $this->assertStringNotContainsString($this->root, (string) $chosen['problem']);
    }

    // A var/sessions that exists but cannot be used (here a link; elsewhere another owner) is said by its project-relative name with the fix, then the temporary directory is taken.
    public function testAnUnusableVarSessionsIsSaidWithTheFix(): void
    {
        mkdir($this->root . '/elsewhere', 0700);
        symlink($this->root . '/elsewhere', $this->root . '/project/var/sessions');

        $chosen = $this->choose();

        $this->assertStringStartsWith($this->root . '/tmp/idno-sessions-', (string) $chosen['path']);
        $this->assertStringContainsString('var/sessions', (string) $chosen['problem']);
        $this->assertStringContainsString('chown', (string) $chosen['problem']);
        $this->assertStringNotContainsString($this->root, (string) $chosen['problem']);
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
}
