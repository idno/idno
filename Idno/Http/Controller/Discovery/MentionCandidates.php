<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Discovery;

/**
 * The members an @-mention can name, over the legacy application (`Idno\Legacy\LegacyMentionCandidates`).
 */
interface MentionCandidates
{
    /**
     * Every member as the handle, name and avatar the mention picker shows, after the `search/mentions` event has been triggered for the name typed.
     * Nothing else of a member is given.
     *
     * @return list<array{username: string, name: string, image: string}>
     */
    public function candidates(string $username): array;
}
