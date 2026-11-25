<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Discovery;

/**
 * What the web app manifest says of the site: its icons, its name and its address. Over the legacy application
 * (`Idno\Legacy\LegacySiteIcons`), so the manifest is built without it in a unit test.
 */
interface SiteIcons
{
    /**
     * The site's icons as `Idno::getSiteIcons()` answers: `['defaults' => ['default' => <url>, 'default_36' => <url>, ...], 'page' => ...]`.
     *
     * @return array<string, mixed>
     */
    public function icons(): array;

    /**
     * The site's title.
     */
    public function title(): string;

    /**
     * The site's address as it is shown (with its trailing slash).
     */
    public function displayUrl(): string;
}
