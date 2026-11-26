<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Http\Controller\Member\MemberLists;

/**
 * What the member's pages ask the site, over the legacy application: what `Idno\Pages\Drafts` and `Idno\Pages\Following\Home` asked, moved here
 * unchanged, so the same lookups are made in the same order and the same database statements are sent. Both answer for the member the session holds, and
 * the pages are served only for one.
 */
final class LegacyMemberLists implements MemberLists
{
    public function drafts(int $count, int $offset): array
    {
        $owner = Idno::site()->session()->currentUser()->getUUID();
        $criteria = ['publish_status' => 'draft', 'owner' => $owner];
        $items = Entity::getFromAll($criteria, [], $count, $offset);
        $total = Entity::countFromAll($criteria);

        return ['items' => is_array($items) ? $items : [], 'total' => (int) $total];
    }

    public function subscriptions(): array
    {
        $subscriptions = Idno::site()->reader()->getUserSubscriptions(Idno::site()->session()->currentUserUUID());

        return is_array($subscriptions) ? $subscriptions : [];
    }
}
