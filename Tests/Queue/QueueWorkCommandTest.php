<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * `service-event-queue` as a command: what it says at start (the abandoned messages, the legacy
 * records it moves), its options, and what it does with them. What it delivers is pinned in
 * QueuedDeliveryTest. Each test runs the worker in a subprocess on a site of its own
 * (AsyncSiteTestCase).
 */
final class QueueWorkCommandTest extends AsyncSiteTestCase
{
    private function worker(string ...$options): array
    {
        [$exit, $stdout, $stderr] = $this->consoleWith([], 'service-event-queue', '--once', ...$options);

        return ['exit' => $exit, 'output' => $stdout . $stderr, 'lines' => array_values(array_filter(preg_split('/\R/', $stdout . $stderr) ?: [], static fn (string $line): bool => $line !== '' && !str_starts_with($line, 'Idno (')))];
    }

    private function enqueue(string $event = 'idno/queue/probe'): string
    {
        $id = $this->runInSite('return \Idno\Core\Idno::site()->queue()->enqueue("default", ' . $this->literal($event) . ', ["a" => 1], null);');
        $this->assertIsString($id);

        return $id;
    }

    public function testAMessageAWorkerTookAndNeverFinishedIsReportedAndNotRunAgainByItself(): void
    {
        $id = $this->enqueue();
        $this->markTakenAgo($id, 7200);

        $first = $this->worker();

        $this->assertSame(0, $first['exit'], $first['output']);
        $this->assertSame("Processing pending events from queue 'default' (single run)...", $first['lines'][0]);
        $this->assertStringContainsString('1 message(s) were taken by a worker more than an hour ago and never finished', $first['lines'][1]);
        $this->assertStringContainsString('--requeue-abandoned', $first['lines'][1]);
        $this->assertCount(2, $first['lines'], 'and nothing was dispatched: ' . $first['output']);
        $this->assertCount(1, $this->queuedEvents(), 'the message is kept');
    }

    public function testRequeueAbandonedPutsThemBackAndTheWorkerHandlesThem(): void
    {
        $abandoned = $this->enqueue('idno/queue/abandoned');
        $recent = $this->enqueue('idno/queue/recent');
        $this->markTakenAgo($abandoned, 7200);
        $this->markTakenAgo($recent, 600);

        $worker = $this->worker('--requeue-abandoned');

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertSame('Requeued 1 abandoned message(s).', $worker['lines'][1], 'only the one taken over an hour ago');
        $dispatched = array_values(array_filter($worker['lines'], static fn (string $line): bool => str_contains($line, 'Dispatching event')));
        $this->assertCount(1, $dispatched);
        $this->assertStringContainsString('Dispatching event ' . $abandoned . ': idno/queue/abandoned', $dispatched[0]);
        $remaining = $this->queuedEvents();
        $this->assertSame(['idno/queue/recent'], array_column($remaining, 'event'), 'the recently taken one is left to its worker');

        $again = $this->worker();
        $this->assertSame(["Processing pending events from queue 'default' (single run)..."], $again['lines'], 'nothing is reported when nothing is abandoned');
    }

    public function testRequeueAbandonedSaysSoWhenThereAreNone(): void
    {
        $worker = $this->worker('--requeue-abandoned');
        $this->assertSame(0, $worker['exit']);
        $this->assertSame('Requeued 0 abandoned message(s).', $worker['lines'][1]);
    }

    public function testLegacyRecordsAreMovedIntoTheQueueAndHandledAndABrokenOneIsReported(): void
    {
        $good = $this->storeQueuedEvent('idno/queue/legacy', serialize(['a' => 1]), null);
        $broken = $this->storeQueuedEvent('idno/queue/legacy-broken', 'this is not serialised', null);

        $worker = $this->worker();

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertContains('Moved 1 event(s) queued by the previous version of Idno into the queue. 1 could not be moved and were left where they are (see the log).', $worker['lines']);
        $this->assertNotEmpty(array_filter($worker['lines'], static fn (string $line): bool => preg_match('/Dispatching event \d+: idno\/queue\/legacy$/', $line) === 1));
        $this->assertStringContainsString('A queued event of the previous queue could not be moved: its data cannot be read. Event ' . $broken . ' (idno/queue/legacy-broken)', $worker['output']);
        $this->assertStringNotContainsString('this is not serialised', $worker['output']);
        $this->assertNotSame($good, $broken);
        // The good record is gone and the broken one is where it was: a second run moves nothing and reports it again.
        $second = $this->worker();
        $this->assertSame(0, $second['exit']);
        $this->assertStringContainsString('Event ' . $broken . ' (idno/queue/legacy-broken)', $second['output']);
    }

    public function testAnotherQueueNameIsAcceptedAndSaysEveryQueueIsServed(): void
    {
        $this->enqueue();

        $worker = $this->worker('--queue=mail');

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertSame("Processing pending events from queue 'mail' (single run)...", $worker['lines'][0]);
        $this->assertSame("Events of every queue name are handled by this worker, not only 'mail'.", $worker['lines'][1]);
        $this->assertNotEmpty(array_filter($worker['lines'], static fn (string $line): bool => str_contains($line, 'Event ') && str_contains($line, ' completed.')));
    }

    public function testTheCommandIsListedAndDescribedAsASymfonyCommand(): void
    {
        [$exit, $stdout] = $this->console('list');
        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^\s+service-event-queue\s+Runs the asynchronous event queue worker$/m', $stdout);
        [$exit, $stdout] = $this->console('service-event-queue', '--help');
        $this->assertSame(0, $exit);
        foreach (['--queue', '--interval', '--once', '--requeue-abandoned'] as $option) {
            $this->assertStringContainsString($option, $stdout);
        }
    }
}
