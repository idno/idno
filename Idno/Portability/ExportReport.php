<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * What an export had trouble with, for the mail that ends it. A value object a caller passes to the exporter,
 * not a service.
 */
final class ExportReport
{
    /** Entities whose HTML (or JSON) could not be made: they are in the archive as data only. */
    public int $unrendered = 0;
}
