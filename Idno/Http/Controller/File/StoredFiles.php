<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * The stored files, as the file page asks for them: whether the viewer may have one and how to read it. Implemented over the legacy application by
 * `Idno\Legacy\LegacyStoredFiles`, so that the page service is built and tested without it.
 */
interface StoredFiles
{
    /**
     * The file with this id and whether the viewer may have it: the rules of who may read a stored file (an archive of the site is for administrators,
     * a file attached to a post is for those who may read the post) are the implementation's, and are applied before anything of the file is read.
     */
    public function lookUp(string $id): FileLookup;

    /**
     * Lets go of the session, which a file that is sent for a long time must not hold.
     */
    public function releaseSession(): void;
}
