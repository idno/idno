<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * What the site says about a file a request names: there is none, or the post it is attached to refuses the viewer (`Missing`: the two are
 * answered alike, so that an address does not tell which files exist), it is for administrators and the viewer is not one (`AdministratorsOnly`), or the
 * viewer may have it (`Served`, with the file).
 * A value object: not a service.
 */
final class FileLookup
{
    public function __construct(public readonly FileOutcome $outcome, public readonly ?ServedFile $file = null)
    {
    }
}
