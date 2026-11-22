<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Event;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Legacy\LegacyEventTrigger;
use Idno\Legacy\LegacyUserContext;
use Idno\Queue\QueuedEvent;
use Idno\Queue\QueuedEventHandler;
use Idno\Queue\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * The queue's two interfaces over the legacy application, wired in config/services.php: the event
 * dispatcher (what the listeners return) and the session (who an event runs as, and that nobody is
 * left logged on).
 */
final class QueueAdaptersTest extends TestCase
{
    private static ?User $user = null;

    private mixed $previous = false;

    public static function setUpBeforeClass(): void
    {
        $user = new User();
        $user->handle = 'queueuser' . bin2hex(random_bytes(4));
        $user->email = $user->handle . '@example.com';
        $user->setPassword(bin2hex(random_bytes(8)));
        $user->setTitle('Queue User');
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
        $this->previous = Idno::site()->session()->currentUser();
    }

    protected function tearDown(): void
    {
        Idno::site()->session()->logUserOff();
        if ($this->previous) {
            Idno::site()->session()->refreshSessionUser($this->previous);
        }
    }

    private function context(): UserContext
    {
        $context = Idno::container()->get('idno.test.queue_user_context');
        $this->assertInstanceOf(LegacyUserContext::class, $context);

        return $context;
    }

    public function testTheInterfacesAreTheLegacyAdapters(): void
    {
        $this->assertInstanceOf(LegacyEventTrigger::class, Idno::container()->get('idno.test.queue_event_trigger'));
        $this->assertInstanceOf(LegacyUserContext::class, Idno::container()->get('idno.test.queue_user_context'));
        $this->assertInstanceOf(QueuedEventHandler::class, Idno::container()->get('idno.test.queue_handler'));
    }

    public function testTheEventTriggerReturnsWhatTheListenersReturned(): void
    {
        $name = 'queue/test/' . bin2hex(random_bytes(4));
        Idno::site()->events()->addListener($name, static function (Event $event): void {
            $event->setResponse(['seen' => $event->data()['value'] * 2]);
        });

        $result = Idno::container()->get('idno.test.queue_event_trigger')->trigger($name, ['value' => 21]);

        $this->assertSame(['seen' => 42], $result);
    }

    public function testTheWorkRunsAsTheUserAndNobodyIsLoggedOnAfterwards(): void
    {
        Idno::site()->session()->logUserOff();

        $seen = $this->context()->runAs(self::$user->getUUID(), static fn () => Idno::site()->session()->currentUserUUID());

        $this->assertSame(self::$user->getUUID(), $seen);
        $this->assertFalse(Idno::site()->session()->currentUser());
    }

    public function testTheWorkRunsAsNobodyForNull(): void
    {
        Idno::site()->session()->refreshSessionUser(self::$user);

        $seen = $this->context()->runAs(null, static fn () => Idno::site()->session()->currentUser());

        $this->assertFalse($seen, 'null is nobody, even when someone was logged on');
        $this->assertFalse(Idno::site()->session()->currentUser());
    }

    public function testAFailureDoesNotLeaveTheUserLoggedOn(): void
    {
        foreach ([new \RuntimeException('failed'), new \TypeError('an error')] as $failure) {
            try {
                $this->context()->runAs(self::$user->getUUID(), static function () use ($failure): void {
                    throw $failure;
                });
                $this->fail('The failure was swallowed.');
            } catch (\Throwable $e) {
                $this->assertSame($failure, $e);
            }
            $this->assertFalse(Idno::site()->session()->currentUser());
        }
    }

    public function testAUserThatDoesNotExistThrowsAndTheWorkDoesNotRun(): void
    {
        $ran = false;
        try {
            $this->context()->runAs('https://example.com/user/nobody-' . bin2hex(random_bytes(4)), static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('A missing user did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid user', $e->getMessage());
        }
        $this->assertFalse($ran);
        $this->assertFalse(Idno::site()->session()->currentUser());
    }

    public function testEnsureNobodyLoggedOnLogsOffWhoeverIsOn(): void
    {
        Idno::site()->session()->refreshSessionUser(self::$user);
        $this->assertNotFalse(Idno::site()->session()->currentUser());

        $this->context()->ensureNobodyLoggedOn();

        $this->assertFalse(Idno::site()->session()->currentUser());
        $this->context()->ensureNobodyLoggedOn(); // nobody: nothing to do
        $this->assertFalse(Idno::site()->session()->currentUser());
    }

    public function testTheHandlerRunsAnEventAsItsUserEndToEnd(): void
    {
        $name = 'queue/test/' . bin2hex(random_bytes(4));
        $seen = [];
        Idno::site()->events()->addListener($name, static function (Event $event) use (&$seen): void {
            $seen[] = Idno::site()->session()->currentUserUUID();
            $event->setResponse('done');
        });
        $handler = Idno::container()->get('idno.test.queue_handler');

        $this->assertSame('done', $handler(new QueuedEvent('idno', $name, [], self::$user->getUUID())));
        $this->assertSame('done', $handler(new QueuedEvent('idno', $name, [], null)));

        $this->assertSame([self::$user->getUUID(), false], $seen);
        $this->assertFalse(Idno::site()->session()->currentUser());
    }
}
