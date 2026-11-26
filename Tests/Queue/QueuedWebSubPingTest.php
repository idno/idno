<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * A ping of the site's hub that waited in the queue under the event's earlier name when the site was upgraded is still delivered: the worker runs
 * it as the event it is now called, and the hub is asked to fetch the feeds as before.
 */
final class QueuedWebSubPingTest extends AsyncSiteTestCase
{
    public function testAPingQueuedUnderTheEarlierEventNameIsStillDelivered(): void
    {
        $cookie = $this->registerUser('alice');
        $this->publishStatus($cookie, 'A post to be announced');
        // (the pings the publication queued are not what is being tested)
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->assertSame([], $this->queuedEvents());

        $sink = $this->newSink('hub');
        $earlierName = \Tests\Core\WebSubCompatibilityTest::OLD_EVENT;
        $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true);' . "\n"
            . '$user = \Idno\Entities\User::getByHandle("alice");' . "\n"
            . '$post = \Idno\Core\Idno::site()->db()->getObjects(\IdnoPlugins\Status\Status::class, [], [], 1)[0];' . "\n"
            . '$id = \Idno\Core\Idno::site()->queue()->enqueue("default", ' . $this->literal($earlierName) . ', ["object" => $post], $user->getUUID());' . "\n"
            . 'return $id;'
        );
        $this->assertSame([$earlierName], array_column($this->queuedEvents(), 'event'), 'it waits under its earlier name');

        $worker = $this->runWorkerOnce(['IDNO_HUB' => $this->baseUrl() . '_test/sink/' . $sink]);

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $requests = $this->sink($sink);
        $this->assertCount(1, $requests, 'the hub is pinged once' . $worker['output']);
        $this->assertStringStartsWith('hub.mode=publish&hub.url=', (string) base64_decode($requests[0]['body'], true));
        $this->assertSame([], $this->failedEvents(), 'and the event did not fail');
    }
}
