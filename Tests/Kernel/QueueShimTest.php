<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\EventQueue;
use Idno\Core\Idno;
use Idno\Legacy\MessengerEventQueue;
use Idno\Queue\QueuedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Tests\Unit\Queue\CollectingLogger;

/**
 * The adapter behind `Idno::site()->queue()` (MessengerEventQueue): what enqueue() sends when the
 * site's queue is asynchronous, what it does when it is not, how the `event_queue` setting is read,
 * and that nothing bundled names the two classes that were deleted. The adapter is built here over
 * a bus that records, and (once) over the container's real bus, the site's queue table and the
 * rig's own site.
 */
final class QueueShimTest extends TestCase
{
    /** @var list<object> */
    private array $sent = [];

    private ?string $failWith = null;

    private ?string $user = null;

    private CollectingLogger $logger;

    /** @var list<array{string, array<mixed>}> */
    private array $triggered = [];

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
    }

    private function bus(): MessageBusInterface
    {
        return new class ($this) implements MessageBusInterface {
            public function __construct(private readonly QueueShimTest $test)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->test->record($message);
            }
        };
    }

    /** @internal for the bus above */
    public function record(object $message): Envelope
    {
        if ($this->failWith !== null) {
            throw new TransportException($this->failWith);
        }
        $this->sent[] = $message;

        return new Envelope($message, [new TransportMessageIdStamp(40 + count($this->sent))]);
    }

    private function queue(?string $setting, ?\Closure $trigger = null): MessengerEventQueue
    {
        return new MessengerEventQueue(
            fn (): MessageBusInterface => $this->bus(),
            static fn (): ?string => $setting,
            fn (): ?string => $this->user,
            $trigger ?? function (string $event, array $data): mixed {
                $this->triggered[] = [$event, $data];

                return ['ran' => $event];
            },
            $this->logger,
        );
    }

    public function testEnqueueSendsOneMessageWithTheEventDataAndTheCurrentUser(): void
    {
        $this->user = 'http://example.test/view/alice';
        $id = $this->queue('AsynchronousQueue')->enqueue('mail', 'post/note/x', ['a' => 1, 'b' => ['c']]);

        $this->assertCount(1, $this->sent);
        $this->assertEquals(new QueuedEvent('mail', 'post/note/x', ['a' => 1, 'b' => ['c']], 'http://example.test/view/alice'), $this->sent[0]);
        $this->assertSame('41', $id, 'the transport\'s id of the message');
        $this->assertSame([], $this->triggered, 'nothing runs in the request');
    }

    public function testTheUserNamedInTheCallIsTheOneItRunsAs(): void
    {
        $this->user = 'http://example.test/view/alice';
        $this->queue('AsynchronousQueue')->enqueue('default', 'e', [], 'http://example.test/view/bob');
        $this->assertSame('http://example.test/view/bob', $this->sent[0]->runAs);
    }

    public function testNoUserMeansNobody(): void
    {
        $this->user = null;
        $this->queue('AsynchronousQueue')->enqueue('default', 'e', []);
        $this->assertNull($this->sent[0]->runAs);
    }

    public function testAnEmptyQueueNameIsDefault(): void
    {
        $this->queue('AsynchronousQueue')->enqueue('', 'e', []);
        $this->queue('AsynchronousQueue')->enqueue(null, 'e', []);
        $this->assertSame(['default', 'default'], [$this->sent[0]->queue, $this->sent[1]->queue]);
    }

    public function testEnqueueReturnsFalseAndLogsWhenTheTransportRefuses(): void
    {
        $this->failWith = 'database is locked';
        $result = $this->queue('AsynchronousQueue')->enqueue('default', 'post/note/x', ['secret' => 'value']);

        $this->assertFalse($result);
        $this->assertSame([], $this->sent);
        $this->assertCount(1, $this->logger->lines);
        [$level, $message] = $this->logger->lines[0];
        $this->assertSame('error', $level);
        $this->assertStringContainsString('post/note/x', $message);
        $this->assertStringContainsString('default', $message);
        $this->assertStringNotContainsString('value', $message, 'never the event\'s data');
    }

    public function testEnqueueReturnsFalseForDataThatCannotBeSent(): void
    {
        // The real bus serialises with serialize(): a closure cannot be.
        $bus = Idno::container()->get('idno.test.messenger_bus');
        $queue = new MessengerEventQueue(static fn (): MessageBusInterface => $bus, static fn (): string => 'AsynchronousQueue', static fn (): ?string => null, static fn (): mixed => null, $this->logger);

        $this->assertFalse($queue->enqueue('default', 'e', ['f' => static fn (): int => 1]));
        $this->assertSame('error', $this->logger->lines[0][0]);
    }

    public function testTheRealBusPutsTheMessageInTheIdnoQueueOfTheTable(): void
    {
        $bus = Idno::container()->get('idno.test.messenger_bus');
        $connection = Idno::container()->get('database_connection');
        $queue = new MessengerEventQueue(static fn (): MessageBusInterface => $bus, static fn (): string => 'AsynchronousQueue', static fn (): ?string => 'u-1', static fn (): mixed => null, $this->logger);
        $before = (int) $connection->fetchOne("select count(*) from idno_messages where queue_name = 'idno'");

        $id = $queue->enqueue('default', 'post/note/real', ['n' => 1]);
        try {
            $this->assertNotFalse($id);
            $this->assertSame($before + 1, (int) $connection->fetchOne("select count(*) from idno_messages where queue_name = 'idno'"));
            $row = $connection->fetchAssociative('select body, headers from idno_messages where id = ?', [$id]);
            $message = (new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer())->decode(['body' => $row['body'], 'headers' => json_decode((string) $row['headers'], true)])->getMessage();
            $this->assertEquals(new QueuedEvent('default', 'post/note/real', ['n' => 1], 'u-1'), $message);
        } finally {
            if ($id !== false) {
                $connection->executeStatement('delete from idno_messages where id = ?', [$id]);
            }
        }
    }

    public function testWithoutAnAsynchronousQueueTheEventRunsAtOnceAndItsResultIsKept(): void
    {
        $queue = $this->queue('SynchronousQueue');
        $id = $queue->enqueue('default', 'some/event', ['x' => 1]);

        $this->assertSame([], $this->sent, 'nothing is sent');
        $this->assertSame([['some/event', ['x' => 1]]], $this->triggered, 'the event ran at once');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id, 'an opaque 32-character hexadecimal id, as it was');
        $this->assertTrue($queue->isComplete($id));
        $this->assertSame(['ran' => 'some/event'], $queue->getResult($id));
        $this->assertFalse($queue->isComplete('0123456789abcdef0123456789abcdef'));
    }

    public function testAListenerThatThrowsIsLoggedAndEnqueueStillReturns(): void
    {
        $queue = $this->queue(null, static function (): never {
            throw new \RuntimeException('listener broke');
        });

        $id = $queue->enqueue('default', 'some/event', []);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertTrue($queue->isComplete($id));
        $this->assertFalse($queue->getResult($id), 'a failed event\'s result is false, as it was');
        $this->assertSame([['error', 'listener broke']], $this->logger->lines);
    }

    public function testAnAsynchronousEventHasNoKeptResult(): void
    {
        $queue = $this->queue('AsynchronousQueue');
        $id = $queue->enqueue('default', 'e', []);

        $this->assertFalse($queue->isComplete($id));
        $this->assertNull($queue->getResult($id));
    }

    /**
     * @dataProvider settings
     */
    public function testIsAsynchronousFollowsTheSetting(?string $setting, bool $expected, int $warnings): void
    {
        $queue = $this->queue($setting);

        $this->assertSame($expected, $queue->isAsynchronous());
        $this->assertSame($expected, $queue->isAsynchronous(), 'read at each call, the same answer');
        $this->assertCount($warnings, array_filter($this->logger->lines, static fn (array $line): bool => $line[0] === 'warning'));
    }

    /**
     * @return array<string, array{?string, bool, int}>
     */
    public static function settings(): array
    {
        return [
            'AsynchronousQueue' => ['AsynchronousQueue', true, 0],
            'the full class name' => ['Idno\\Core\\AsynchronousQueue', true, 0],
            'with a leading backslash' => ['\\Idno\\Core\\AsynchronousQueue', true, 0],
            'in any case, as a class name is' => ['asynchronousqueue', true, 0],
            'unset' => [null, false, 0],
            'empty' => ['', false, 0],
            'SynchronousQueue' => ['SynchronousQueue', false, 0],
            'another class name' => ['Acme\\Queue\\Beanstalk', false, 1],
        ];
    }

    public function testTheSettingIsReadAtEachCallAndAnUnknownClassWarnsOnce(): void
    {
        $setting = 'AsynchronousQueue';
        $queue = new MessengerEventQueue(fn (): MessageBusInterface => $this->bus(), static function () use (&$setting): ?string {
            return $setting;
        }, static fn (): ?string => null, static fn (): mixed => null, $this->logger);

        $this->assertTrue($queue->isAsynchronous());
        $setting = 'Acme\\Queue\\Beanstalk';
        $this->assertFalse($queue->isAsynchronous());
        $this->assertFalse($queue->isAsynchronous());
        $this->assertCount(1, $this->logger->lines, 'one warning, however often it is asked');
        $this->assertStringContainsString('Acme\\Queue\\Beanstalk', $this->logger->lines[0][1]);
    }

    public function testTheSitesQueueIsTheAdapter(): void
    {
        $queue = Idno::site()->queue();

        $this->assertInstanceOf(MessengerEventQueue::class, $queue);
        $this->assertInstanceOf(EventQueue::class, $queue);
        $this->assertSame($queue, Idno::container()->get(EventQueue::class));
        // The rig's own site does not set event_queue: events run in the request.
        $this->assertFalse($queue->isAsynchronous());
    }

    public function testTheRigsSiteRunsAnEventAtOnce(): void
    {
        $name = 'queue/shim/' . bin2hex(random_bytes(4));
        $seen = [];
        Idno::site()->events()->addListener($name, static function (\Idno\Core\Event $event) use (&$seen): void {
            $seen[] = $event->data();
            $event->setResponse('done');
        });

        $id = Idno::site()->queue()->enqueue('default', $name, ['k' => 'v']);

        $this->assertSame([['k' => 'v']], $seen);
        $this->assertTrue(Idno::site()->queue()->isComplete($id));
        $this->assertSame('done', Idno::site()->queue()->getResult($id));
    }

    /**
     * The plugin's own test of whether federation can work is the adapter's: ActivityPub, and the
     * StandardSiteSync backfill, ask `isAsynchronous()` of the site's queue. Over HTTP on a site with
     * the asynchronous queue and on one without: see Tests\Queue\QueueSettingSiteTest.
     */
    public function testTheDeletedClassesAreGoneAndNothingBundledNamesThem(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root . '/Idno/Core/AsynchronousQueue.php');
        $this->assertFileDoesNotExist($root . '/Idno/Core/SynchronousQueue.php');
        $this->assertDirectoryDoesNotExist($root . '/ConsolePlugins/EventQueueService');
        $this->assertDirectoryDoesNotExist($root . '/Idno/Pages/Service/Queues');
        $this->assertFalse(class_exists('Idno\\Core\\AsynchronousQueue', false));
        $this->assertFalse(class_exists('Idno\\Core\\SynchronousQueue', false));

        $hits = [];
        foreach (['Idno', 'IdnoPlugins', 'ConsolePlugins', 'templates', 'Themes', 'config', 'bin'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'inc'], true)) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                // The setting's value `AsynchronousQueue` is a word of the adapter and the docs; what must
                // not appear is a class or a page handler under those names.
                if (preg_match('/Core\\\\(?:Asynchronous|Synchronous)Queue\b|Service\\\\Queues/', $text) === 1) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        // The adapter names the setting's values; nothing else may name the classes.
        $this->assertSame([], array_values(array_diff($hits, ['Idno/Legacy/MessengerEventQueue.php'])), 'files that still name a deleted class');
    }
}
