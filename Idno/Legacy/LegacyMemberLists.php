<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Http\Controller\Member\MemberLists;

/**
 * What the member's pages ask the site, over the legacy application: what `Idno\Pages\Drafts` asked, moved here
 * unchanged, so the same lookups are made in the same order and the same database statements are sent. It answers for the member the session holds, and
 * the page is served only for one.
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
}
