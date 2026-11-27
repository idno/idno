<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\ContentType;
use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Controller\Profile\Profiles;
use Idno\Http\Page\PageContext;

/**
 * What the profile page asks the site, over the legacy application: what `Idno\Pages\User\View` asked, moved here unchanged, so the same lookups are made in
 * the same order and the same database statements are sent.
 */
final class LegacyProfiles implements Profiles
{
    public function byHandle(string $handle): ?User
    {
        $user = User::getByHandle($handle);

        return $user instanceof User ? $user : null;
    }

    public function typesFor(?string $friendly): array
    {
        if ($friendly === null) {
            return ['IdnoPlugins\Status\Status', 'IdnoPlugins\Text\Entry'];
        }
        $types = [];
        // Run through the URL parameters and set content types appropriately
        foreach (array_filter(explode('/', $friendly)) as $friendlyType) {
            if ($friendlyType == 'all') {
                $types = ContentType::getRegisteredClasses();
                break;
            }
            if ($class = ContentType::categoryTitleToClass($friendlyType)) {
                $types[] = $class;
            }
        }

        return $types;
    }

    public function stream(User $user, array $types, int $offset): array
    {
        $criteria = ['owner' => $user->getUUID(), 'publish_status' => 'published'];
        $count = Entity::countFromX($types, $criteria);
        $items = Entity::getFromX($types, $criteria, [], Idno::site()->config()->items_per_page, $offset);

        return ['items' => $items, 'count' => (int) $count];
    }

    public function title(User $user): string
    {
        return (string) $user->getTitle();
    }

    public function siteTitle(): string
    {
        return (string) Idno::site()->config()->title;
    }

    public function updated(User $user): int
    {
        $updated = $user->updated;

        return is_numeric($updated) ? (int) $updated : 0;
    }

    public function handle(User $user): string
    {
        return (string) $user->getHandle();
    }

    public function saveFromInput(User $user): bool
    {
        return (bool) $user->saveDataFromInput();
    }

    public function url(User $user): string
    {
        return (string) $user->getURL();
    }

    public function referrer(PageContext $page): string
    {
        return \Idno\Http\RedirectTarget::sanitize($page->request()->headers->get('Referer') ?? '', \Idno\Core\Idno::site()->config()->getDisplayURL());
    }
}
