<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * Matches when at least one child matches. With no children it matches nothing
 * (this is what the MongoDB list form of $or parses to; see Criteria::fromLegacyArray()).
 */
final class AnyOf implements Criterion
{
    /**
     * @param list<Criterion> $children
     */
    public function __construct(public readonly array $children)
    {
    }
}
