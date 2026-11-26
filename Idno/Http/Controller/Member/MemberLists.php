<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Member;

/**
 * What the member's pages ask the site: the signed-in member's drafts and the feeds they follow. The legacy application implements it
 * (`Idno\Legacy\LegacyMemberLists`), so the pages' service is built and tested without it.
 */
interface MemberLists
{
    /**
     * One page of the signed-in member's own drafts (an entity of any type whose publish status is `draft` and whose owner is the member: what
     * another member or an administrator drafted is never in it), newest first, and the number of all of them.
     *
     * @return array{items: array<int, mixed>, total: int}
     */
    public function drafts(int $count, int $offset): array;

    /**
     * The feed subscriptions of the signed-in member.
     *
     * @return array<int, mixed>
     */
    public function subscriptions(): array;
}
