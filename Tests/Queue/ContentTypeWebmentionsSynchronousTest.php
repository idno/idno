<?php

declare(strict_types=1);

namespace Tests\Queue;

use Tests\Support\PrivateSiteTestCase;

/**
 * On a site with the default (synchronous) queue every bundled content type still sends its webmentions
 * during the request that saves the post: the queue runs the event at once. A private site of the test's
 * own; the target is a `/_test/page/` page that advertises a sink as its webmention endpoint.
 */
final class ContentTypeWebmentionsSynchronousTest extends PrivateSiteTestCase
{
    use PublishesEachContentType;

    /** @var list<string> */
    private array $sinks = [];

    protected function tearDown(): void
    {
        foreach ($this->sinks as $name) {
            $this->site()->request('GET', '_test/sink/' . $name . '/log');
        }
        parent::tearDown();
    }

    /**
     * @dataProvider contentTypes
     */
    public function testTheWebmentionIsSentDuringTheRequest(string $type): void
    {
        $cookie = $this->registerUser('alice');
        $name = 'wm-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;
        $target = $this->baseUrl() . '_test/page/' . $name;

        $response = $this->postNew($type, $cookie, $target);
        $this->assertSame(302, $response->status, $type . ': ' . substr($response->body, 0, 800) . $this->serverLog());

        $log = $this->site()->request('GET', '_test/sink/' . $name . '/log');
        $requests = $log->json();
        $this->assertCount(1, $requests, $type . ': the request that saved the post sent the webmention');
        $this->assertSame(['source' => (string) $response->header('Location'), 'target' => $target], $requests[0]['fields'], $type);
    }
}
