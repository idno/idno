<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * The document around the items of an RSS or WXR export: the template of the site's RSS type, drawn
 * with no items. The items are mixed in at the line that holds FeedExporter::MARKER.
 * (Idno\Legacy\LegacyFeedShell is the adapter over the template service.)
 */
interface FeedShell
{
    public function render(string $title, string $description, string $baseUrl, bool $wxr): string;
}
