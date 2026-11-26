<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

/**
 * Says which page of this site an address is, for a webmention aimed at it.
 */
interface WebmentionTargets
{
    /**
     * The page the address is on this site, as a GET of it is routed, or null when the address is not this site's or no page serves it.
     */
    public function resolve(string $target): ?WebmentionTarget;
}
