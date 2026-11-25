<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Http\Controller\Discovery\SiteIcons;

/**
 * The site's icons, title and address over the legacy application, read when asked.
 */
final class LegacySiteIcons implements SiteIcons
{
    public function icons(): array
    {
        return Idno::site()->getSiteIcons();
    }

    public function title(): string
    {
        return (string) Idno::site()->config()->getTitle();
    }

    public function displayUrl(): string
    {
        return (string) Idno::site()->config()->getDisplayURL();
    }
}
