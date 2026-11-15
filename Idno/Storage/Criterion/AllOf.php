<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * Matches when every child matches. With no children it matches everything.
 */
final class AllOf implements Criterion
{
    /**
     * @param list<Criterion> $children
     */
    public function __construct(public readonly array $children)
    {
    }
}
