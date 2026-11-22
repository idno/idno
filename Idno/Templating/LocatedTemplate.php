<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * A template file the cascade found.
 */
final class LocatedTemplate
{
    /**
     * @param string $path        the file
     * @param string $engine      what renders it: `php` or `twig`
     * @param string $type        the template type directory it was found in
     * @param string $base        the cascade directory it was found in
     * @param string $logicalName `<type>/<name>.<extension>`
     */
    public function __construct(
        public readonly string $path,
        public readonly string $engine,
        public readonly string $type,
        public readonly string $base,
        public readonly string $logicalName,
    )
    {
    }
}
