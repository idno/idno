<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * What the site says about a file a request names: there is none (`missing`), it is for administrators and the viewer is not one
 * (`administrators-only`), the post it is attached to refuses the viewer (`not-readable`), or the viewer may have it (`served`, with the file).
 * A value object: not a service.
 */
final class FileLookup
{
    public function __construct(public readonly string $outcome, public readonly ?ServedFile $file = null)
    {
    }
}
