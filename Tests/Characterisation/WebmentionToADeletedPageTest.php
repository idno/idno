<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * A webmention aimed at the address of a page whose class is deleted. The endpoint asks the legacy router which page a target is and
 * hands the mention to it; the feeds and the tag pages are served by a controller now and have no legacy page, so the router finds
 * nothing for their addresses and the target is not found. Remote hosts are `.invalid`: nothing leaves the machine.
 */
final class WebmentionToADeletedPageTest extends CharacterisationTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function targets(): array
    {
        return ['the feed' => ['feed.xml'], 'the other feeds' => ['rss.xml'], 'a tag' => ['tag/x'], 'a search' => ['search']];
    }

    /**
     * @dataProvider targets
     */
    public function testAMentionOfTheAddressOfADeletedPageIsATargetNotFound(string $path): void
    {
        $target = $this->site()->baseUrl() . $path;

        $response = $this->site()->request('POST', 'webmention/', [], ['source' => 'https://remote.invalid/a', 'target' => $target]);

        $this->assertSame(400, $response->status);
        // The classes of these pages are deleted, so the router has no page for the target: the target is not found. While the pages
        // existed, a mention reached them (this rig cannot get a source past the endpoint's private-network check, so it saw the
        // endpoint's `target_not_supported` for a source it took to be the site's own; a source that could be fetched and linked the
        // target reached the page's `webmentionContent()`, which refused with `source_not_supported`).
        $this->assertSame(['error' => 'target_not_found', 'error_text' => 'The target page ' . $target . ' does not exist.'], json_decode($response->body, true));
    }
}
