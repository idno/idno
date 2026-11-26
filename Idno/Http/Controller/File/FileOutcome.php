<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * What the site says about the file a request names. An enum: not a service.
 */
enum FileOutcome: string
{
    /** There is no such file, or the viewer may not know there is: a file attached to a post the viewer may not read is a missing file */
    case Missing = 'missing';

    /** The file is for administrators and the viewer is not one */
    case AdministratorsOnly = 'administrators-only';

    /** The viewer may have it */
    case Served = 'served';
}
