<?php

declare(strict_types=1);

namespace Tests\Core;

use Tests\Support\PrivateSiteTestCase;

/**
 * A private site (`walled_garden`) tells no other server what its members post: a hub it is configured to ping, and a page its
 * posts link to, hear nothing when a member publishes. The same post on a public site is announced to both, which the second
 * test shows, so the first cannot pass because the hub or the sink was never reachable. A site of the test's own.
 */
final class PrivateSiteOutboundTest extends PrivateSiteTestCase
{
    protected function siteEnvironment(): array
    {
        // (read when the site starts, which is before the test body runs: the control test names itself)
        return str_contains($this->getName(false), 'PublicSite') ? [] : ['IDNO_WALLED_GARDEN' => '1'];
    }

    /**
     * Publishes a note that links to a page advertising a webmention sink, with a hub configured.
     *
     * @return array{hub: list<array<string, mixed>>, mention: list<array<string, mixed>>}
     */
    private function publishAndListen(): array
    {
        $cookie = $this->registerUser('alice');
        $hub = 'hub-' . bin2hex(random_bytes(4));
        $mention = 'wm-' . bin2hex(random_bytes(4));
        $this->runInSite(
            '\Idno\Core\Idno::site()->config()->hub = ' . $this->literal($this->baseUrl() . '_test/sink/' . $hub) . ';' . "\n"
            . '\Idno\Core\Idno::site()->config()->save();' . "\n"
        );

        $this->publishStatus($cookie, 'Reading ' . $this->baseUrl() . '_test/page/' . $mention . ' today');

        $heard = [
            'hub' => $this->site()->request('GET', '_test/sink/' . $hub . '/log')->json(),
            'mention' => $this->site()->request('GET', '_test/sink/' . $mention . '/log')->json(),
        ];

        return $heard;
    }

    public function testAPrivateSiteSendsNothingToAnotherServer(): void
    {
        $heard = $this->publishAndListen();

        $this->assertSame([], $heard['hub'], 'the hub was told about a post nobody outside may read' . $this->serverLog());
        $this->assertSame([], $heard['mention'], 'the page the post links to was sent a webmention' . $this->serverLog());
    }

    public function testAPublicSiteAnnouncesTheSamePostToTheHubAndTheLinkedPage(): void
    {
        $heard = $this->publishAndListen();

        $this->assertCount(1, $heard['hub'], 'the hub is pinged' . $this->serverLog());
        $this->assertCount(1, $heard['mention'], 'the linked page is sent a webmention' . $this->serverLog());
    }

    /**
     * Sends the webmention a reply's author forwards to the page the reply answers (what the `webmention/send` event and a stored mention of a
     * reply do), from the site, to a page that advertises a sink.
     */
    private function forwardAMention(): array
    {
        $this->registerUser('alice');
        $name = 'wm-' . bin2hex(random_bytes(4));
        $this->runInSite('return \Idno\Core\Webmention::sendWebmentionPayload(' . $this->literal($this->baseUrl() . 'private-post') . ', ' . $this->literal($this->baseUrl() . '_test/page/' . $name) . ');');

        return $this->site()->request('GET', '_test/sink/' . $name . '/log')->json();
    }

    /** A reply on a private site is not announced to the page it answers, when another server mentions the reply and the site forwards it. */
    public function testAPrivateSiteForwardsNoWebmentionToTheRepliedToPage(): void
    {
        $this->assertSame([], $this->forwardAMention());
    }

    /** The same forward on a public site reaches the page, so the test above cannot pass because the page was unreachable. */
    public function testAPublicSiteForwardsTheWebmentionToTheRepliedToPage(): void
    {
        $this->assertCount(1, $this->forwardAMention());
    }
}
