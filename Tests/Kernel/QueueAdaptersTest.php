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

    /**
     * Catches the events a member had queued before the account was closed (a webmention, a Create) piling up in the failure transport: the work does not run
     * (nothing is sent as the closed actor) and nothing is thrown, for an account that is closed and for one that was deleted; a user that was never there still throws.
     */
    public function testTheWorkOfAClosedOrDeletedAccountIsDroppedWithoutAnExceptionAndTheWorkDoesNotRun(): void
    {
        $user = new \Idno\Entities\User();
        $user->handle = 'queued-closed-' . bin2hex(random_bytes(3));
        $user->email = $user->handle . '@example.test';
        $user->setTitle('Closed');
        $user->setPassword('a-password-of-sufficient-length');
        $user->save(true);
        $uuid = $user->getUUID();
        $ran = [];
        try {
            $user->close();
            $this->assertNull($this->context()->runAs($uuid, static function () use (&$ran): string {
                $ran[] = 'closed';

                return 'ran';
            }));
            $user->delete();
            $this->assertNull($this->context()->runAs($uuid, static function () use (&$ran): string {
                $ran[] = 'deleted';

                return 'ran';
            }));
        } finally {
            \Idno\Core\AccountRegistry::releaseHandle($user->handle);
            \Idno\Core\AccountRegistry::unmarkClosed($uuid);
        }
        $this->assertSame([], $ran);
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

    /**
     * The worker's process has run earlier events: a user it cached then (the previous event of the
     * same user) and who has been deleted since must not pass the check, or the event would run as nobody
     * with the stale user's data.
     */
    public function testAUserCachedByAnEarlierEventAndDeletedSinceMakesTheNextEventFail(): void
    {
        $user = new User();
        $user->handle = 'gone' . bin2hex(random_bytes(4));
        $user->email = $user->handle . '@example.com';
        $user->setPassword(bin2hex(random_bytes(8)));
        $user->setTitle('Gone User');
        $this->assertNotFalse($user->save(true));
        $uuid = $user->getUUID();
        $ran = 0;
        $this->context()->runAs($uuid, static function () use (&$ran): void {
            ++$ran;
        });
        $this->assertSame(1, $ran, 'the first event ran, and cached the user');
        $this->assertNotFalse(User::getByUUID($uuid), 'cached');

        // Deleted by something else (another process): this one's cache does not know.
        $this->assertTrue(Idno::site()->db()->deleteRecord($user->getID()));

        try {
            $this->context()->runAs($uuid, static function () use (&$ran): void {
                ++$ran;
            });
            $this->fail('A user deleted since it was cached still passed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid user', $e->getMessage());
        }
        $this->assertSame(1, $ran, 'nothing was triggered');
        $this->assertFalse(Idno::site()->session()->currentUser());
    }

    /**
     * A user the session refuses to log on (the address is blocked, say) is not "someone": the event
     * does not run as nobody instead.
     */
    public function testAUserTheSessionWillNotLogOnMakesTheEventFailInsteadOfRunningAsNobody(): void
    {
        $blocked = new User();
        $blocked->handle = 'blocked' . bin2hex(random_bytes(4));
        $blocked->email = $blocked->handle . '@blocked.example';
        $blocked->setPassword(bin2hex(random_bytes(8)));
        $blocked->setTitle('Blocked User');
        $this->assertNotFalse($blocked->save(true));
        $previous = Idno::site()->config()->blocked_emails ?? null;
        Idno::site()->config()->blocked_emails = [$blocked->email];
        $ran = false;
        try {
            if (!Idno::site()->config()->emailIsBlocked($blocked->email)) {
                $this->markTestSkipped('The site\'s email blocking does not take this setting.');
            }
            try {
                $this->context()->runAs($blocked->getUUID(), static function () use (&$ran): void {
                    $ran = true;
                });
                $this->fail('The event ran although the user could not be logged on.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Invalid user', $e->getMessage());
            }
            $this->assertFalse($ran);
        } finally {
            Idno::site()->config()->blocked_emails = $previous;
            $blocked->delete();
        }
    }

    /**
     * What the worker does after every message: an entity read and cached by one event, and changed in the
     * database since, is read fresh by the next.
     */
    public function testTheEntityCacheAdapterMakesTheNextEventReadFreshData(): void
    {
        $cache = Idno::container()->get('idno.test.queue_entity_cache');
        $this->assertInstanceOf(\Idno\Legacy\LegacyEntityCache::class, $cache);
        $entity = new \IdnoPlugins\Status\Status();
        $entity->body = 'before';
        $this->assertNotFalse($entity->save(true));
        $uuid = $entity->getUUID();
        try {
            $this->assertSame('before', \Idno\Common\Entity::getByUUID($uuid)->body, 'read, and cached');
            // Another process changes it (written straight to the database, as a web request's save does
            // from its own process: this process's cache is not told).
            $other = \IdnoPlugins\Status\Status::getOneFromAll(["uuid" => $uuid]);
            $other->body = 'after';
            $this->assertNotFalse(Idno::site()->db()->saveObject($other));
            $this->assertSame('before', \Idno\Common\Entity::getByUUID($uuid)->body, 'the cache is stale: what a long-running worker would see');

            $cache->clear();

            $this->assertSame('after', \Idno\Common\Entity::getByUUID($uuid)->body, 'emptied: the next event reads fresh data');
        } finally {
            $entity->delete();
        }
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
