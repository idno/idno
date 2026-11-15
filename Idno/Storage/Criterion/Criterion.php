<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * One node of a storage query. A tree of these (rooted in AllOf) is what
 * Idno\Storage\Criteria holds; the nodes carry what the caller passed, uncoerced.
 */
interface Criterion
{
}
