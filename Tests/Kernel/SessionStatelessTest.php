<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Session;
use Idno\Entities\User;
use PHPUnit\Framework\TestCase;

/**
 * What Idno\Core\Session does on a stateless request (a signed API request, a bearer token, a plugin's hook): the
 * user is held in memory, the session is never written, logging off clears the memory only, and the deprecated
 * setIsAPIRequest() sets the request's flag and nothing in the session. In-process, on the process's one session.
 */
final class SessionStatelessTest extends TestCase
{
    private static ?User $user = null;

    private ?Session $session = null;

    private mixed $sessionBefore = null;

    public static function setUpBeforeClass(): void
    {
        $user = new User();
        $user->handle = 'stateless' . bin2hex(random_bytes(4));
        $user->email = $user->handle . '@example.com';
        $user->setPassword(bin2hex(random_bytes(8)));
        $user->setTitle('Stateless User');
        $user->save(true);
        self::$user = $user;
    }

    public static function tearDownAfterClass(): void
    {
        self::$user?->delete();
        self::$user = null;
    }

    protected function setUp(): void
    {
        $this->session = Idno::site()->session();
        $this->session->resetStatelessAuthentication();
        $this->sessionBefore = $_SESSION ?? [];
        unset($_SESSION['user_uuid'], $_SESSION['is_api_request']);
    }

    protected function tearDown(): void
    {
        $this->session?->resetStatelessAuthentication();
        $_SESSION = is_array($this->sessionBefore) ? $this->sessionBefore : [];
    }

    public function testAuthenticatingStatelesslyHoldsTheUserAndWritesNothing(): void
    {
        $this->session->authenticateStatelessly(self::$user);

        $this->assertTrue($this->session->isAPIRequest());
        $this->assertTrue($this->session->isLoggedIn());
        $this->assertSame(self::$user->getUUID(), $this->session->currentUserUUID());
        $this->assertArrayNotHasKey('user_uuid', $_SESSION, 'the session is not written');
        $this->assertArrayNotHasKey('is_api_request', $_SESSION);
    }

    public function testRefreshingTheUserOfAStatelessRequestKeepsItOutOfTheSession(): void
    {
        $this->session->authenticateStatelessly(self::$user);

        $this->session->refreshSessionUser(self::$user);

        $this->assertSame(self::$user->getUUID(), $this->session->currentUserUUID());
        $this->assertArrayNotHasKey('user_uuid', $_SESSION);
    }

    public function testLoggingOffAStatelessRequestClearsTheMemoryAndLeavesTheSessionAlone(): void
    {
        $_SESSION['user_uuid'] = 'the-browsers-user';
        $_SESSION['kept'] = 'yes';
        $this->session->authenticateStatelessly(self::$user);

        $this->session->logUserOff();

        $this->assertFalse($this->session->isLoggedIn());
        $this->assertSame('the-browsers-user', $_SESSION['user_uuid'], 'the browser\'s session is untouched');
        $this->assertSame('yes', $_SESSION['kept']);
    }

    public function testTheDeprecatedSetterSetsTheRequestFlagNeverTheSessionAndLogsOneLine(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'idno-test-log-');
        $previous = ini_set('error_log', $log);
        try {
            $this->session->setIsAPIRequest(true);
            $this->session->setIsAPIRequest(true);
            $this->session->setIsAPIRequest(false);
        } finally {
            ini_set('error_log', (string) $previous);
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $l): bool => str_contains($l, 'setIsAPIRequest')));
            unlink($log);
        }

        $this->assertFalse($this->session->isAPIRequest(), 'the last call decides');
        $this->assertArrayNotHasKey('is_api_request', $_SESSION);
        $this->assertCount(1, $lines, 'one deprecation line, however often it is called');
        $this->assertStringContainsString('Deprecated', $lines[0]);
    }

    public function testAnOldFlagInAStoredSessionMeansNothing(): void
    {
        $_SESSION['is_api_request'] = true;

        $this->assertFalse($this->session->isAPIRequest());
    }

    public function testResettingForgetsTheUserAndTheFlag(): void
    {
        $this->session->authenticateStatelessly(self::$user);

        $this->session->resetStatelessAuthentication();

        $this->assertFalse($this->session->isAPIRequest());
        $this->assertFalse($this->session->isLoggedIn());
    }
}
