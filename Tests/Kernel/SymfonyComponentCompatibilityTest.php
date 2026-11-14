<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\EventDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Exercises the legacy call sites of the Symfony components bumped to 8.x
 * (event-dispatcher, http-foundation; cache is covered by Tests/Cache/FilesystemCacheTest) that the characterisation suite does not reach.
 */
final class SymfonyComponentCompatibilityTest extends TestCase
{
    public function testEventDispatcherRoundTrip(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('kernel-test/event', static function (\Idno\Core\Event $event): void {
            $event->setResponse('handled:' . $event->data()['x']);
        });

        $this->assertSame('handled:1', $dispatcher->triggerEvent('kernel-test/event', ['x' => 1]));
    }

    public function testPdoSessionHandlerWorksWithTheLegacyOptions(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $pdo = new \PDO('sqlite::memory:');
        $handler = new PdoSessionHandler($pdo, [
            'db_table' => 'session',
            'db_id_col' => 'session_id',
            'db_data_col' => 'session_value',
            'db_time_col' => 'session_time',
            'db_lifetime_col' => 'session_lifetime',
            'lock_mode' => PdoSessionHandler::LOCK_NONE,
        ]);
        $handler->createTable();
        $handler->open('', 'PHPSESSID');
        $this->assertTrue($handler->write('abc', 'payload'));
        $this->assertSame('payload', $handler->read('abc'));
        $handler->close();
        $this->assertSame(PdoSessionHandler::LOCK_ADVISORY, 1);
    }
}
