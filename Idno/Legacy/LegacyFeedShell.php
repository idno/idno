<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\DefaultTemplate;
use Idno\Portability\FeedShell;

/**
 * The feed export's document shell, drawn by the site's RSS template with no items.
 */
final class LegacyFeedShell implements FeedShell
{
    public function render(string $title, string $description, string $baseUrl, bool $wxr): string
    {
        $theme = new DefaultTemplate();
        $theme->setTemplateType('rss');

        return (string) $theme->__(
            [
                'title' => $title,
                'description' => $description,
                'body' => $theme->__(
                    [
                        'items' => [],
                        'offset' => 0,
                        'count' => 0,
                        'subject' => [],
                        'base_url' => $baseUrl,
                        'wxr_mode' => $wxr,
                    ]
                )->draw('pages/home'),
                'wxr_mode' => $wxr,
            ]
        )->drawPage(false);
    }
}
