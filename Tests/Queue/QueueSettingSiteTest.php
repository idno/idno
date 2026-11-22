<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * What the `event_queue` setting means to the code that asks the site's queue: the ActivityPub admin
 * page shows its "Asynchronous queue required" warning exactly when the queue is not asynchronous
 * (`Idno::site()->queue()->isAsynchronous()`), and the queue's old service pages are gone (404). A site
 * with `event_queue = 'AsynchronousQueue'` here; see QueueSettingSynchronousSiteTest for one without.
 */
class QueueSettingSiteTest extends AsyncSiteTestCase
{
    protected const WARNING = 'Asynchronous queue required';

    protected function expectsFederation(): bool
    {
        return true;
    }

    public function testActivityPubSeesFederationOnExactlyWhenTheQueueIsAsynchronous(): void
    {
        $cookie = $this->registerUser('alice');
        $page = $this->site()->request('GET', 'admin/activitypub', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status, substr($page->body, 0, 500) . $this->serverLog());
        if ($this->expectsFederation()) {
            $this->assertStringNotContainsString(self::WARNING, $page->body);
        } else {
            $this->assertStringContainsString(self::WARNING, $page->body);
        }
        $this->assertSame($this->expectsFederation(), $this->runInSite('return \Idno\Core\Idno::site()->queue()->isAsynchronous();'));
    }

    public function testTheQueuesServicePagesAreGone(): void
    {
        $cookie = $this->registerUser('alice');
        foreach (['service/queue/list', 'service/queue/dispatch/abc', 'service/queue/gc'] as $path) {
            $this->assertSame(404, $this->site()->request('GET', $path, ['Cookie' => $cookie])->status, $path);
        }
    }
}
