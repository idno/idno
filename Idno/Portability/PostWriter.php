<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * Creates the posts an import brings in, as entities saved through Entity::save(), so that slugs, tags,
 * file links and events work as for any post (Idno\Legacy\LegacyPostWriter is the adapter over the
 * article and note classes).
 */
interface PostWriter
{
    /** Whether the content types an import writes (articles, notes) are installed. */
    public function available(): bool;

    /**
     * @return ?string the UUID of the post, null when it could not be saved
     */
    public function write(ImportedPost $post, string $ownerUuid): ?string;

    /**
     * Adds comments to a post written earlier.
     *
     * @param list<ImportedComment> $comments
     *
     * @return int how many were added
     */
    public function addComments(string $postUuid, array $comments): int;
}
