<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\ContentType;
use Idno\Common\Entity;
use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Core\Time;
use Idno\Entities\User;
use Idno\Http\Controller\Home\HomeStream;

/**
 * The home stream over the legacy application: what `Idno\Pages\Homepage` asked the site, moved here unchanged, so the same statements are
 * made in the same order and the same lines are logged. The finders are the legacy `Entity::countFromX()` and `getFromX()`, whose
 * access rules and publish-status rule are the viewer's.
 */
final class LegacyHomeStream implements HomeStream
{
    public function siteHasUsers(): bool
    {
        return (bool) User::get();
    }

    public function singleUserOwner(): ?User
    {
        if (!Idno::site()->config()->single_user) {
            return null;
        }
        $owners = User::get(['admin' => true]);
        if (!empty($owners) && count($owners) === 1) {
            return $owners[0];
        }
        $number = 0;
        if (!empty($owners)) {
            $number = count($owners);
        }
        Idno::site()->logging()->warning("Expected exactly 1 admin user for single-user site; got $number");

        return null;
    }

    public function types(?string $friendly, string|array|null $query, string|array|null $requested): array
    {
        $types = $requested;
        $friendlyTypes = [];
        if (!empty($friendly)) { // the friendly content-specific URL
            if ($friendlyTypes = explode('/', $friendly)) {
                $friendlyTypes = array_filter($friendlyTypes);
                if (empty($friendlyTypes) && !empty($query)) {
                    $friendlyTypes = ['all'];
                }
                $types = [];
                // Run through the URL parameters and set content types appropriately
                foreach ($friendlyTypes as $friendlyType) {
                    if ($friendlyType == 'all') {
                        $types = ContentType::getRegisteredClasses();
                        break;
                    }
                    if ($class = ContentType::categoryTitleToClass($friendlyType)) {
                        $types[] = $class;
                    }
                }
            }
        } elseif (empty($query)) {
            $types = Idno::site()->config()->getHomepageContentTypes();
        }

        if (empty($types)) {
            $types = ContentType::getRegisteredClasses();
        } else {
            $types = (array) $types;
        }

        return ['types' => $types, 'friendly' => $friendlyTypes];
    }

    public function page(array $types, string|array|null $query, int $offset): array
    {
        $search = [];
        if (!empty($query)) {
            $search = Idno::site()->db()->createSearchArray($query);
        }
        $search['publish_status'] = 'published';

        $count = Entity::countFromX($types, $search);
        $items = Entity::getFromX($types, $search, [], Idno::site()->config()->items_per_page, $offset);

        return ['items' => $items, 'count' => $count];
    }

    public function creatableTypes(): array|false
    {
        if (!Idno::site()->session()->isLoggedIn()) {
            return false;
        }
        $create = ContentType::getRegistered();
        // If we can't create an object of this type, hide from the button bar
        foreach ($create as $key => $type) {
            $type->editUrl = $type->getEditURL(); // Helper for API clients: handle flexible site configuration
            if (!$type->createable) {
                unset($create[$key]);
            }
        }

        return $create;
    }

    public function titles(): array
    {
        $config = Idno::site()->config();
        $description = !empty($config->description) ? $config->description : 'An independent social website, powered by Idno.';
        $title = !empty($config->homepagetitle) ? $config->homepagetitle : $config->title;

        return ['title' => $title, 'description' => $description];
    }

    public function lastModified(int $timestamp): string
    {
        return Time::timestampToRFC2616($timestamp);
    }

    public function profilePageWebmention(User $user, string $source, string $target, array $sourceResponse, array $sourceMf2): bool
    {
        Idno::site()->logging()->debug("pass on webmention to solo user: {$user->getHandle()}");
        $page = Idno::site()->routes()->getRoute($user->getURL());
        if ($page instanceof Page) {
            return (bool) $page->webmentionContent($source, $target, $sourceResponse, $sourceMf2);
        }
        Idno::site()->logging()->debug('failed to find a Page to serve route ' . $user->getURL());

        return false;
    }
}
