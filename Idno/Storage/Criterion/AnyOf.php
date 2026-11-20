<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * Matches when at least one child matches. With no children it matches nothing
 * (this is what the list form of $or parses to; see Criteria::fromLegacyArray()).
 */
final class AnyOf implements Criterion
{
    /** @var list<Criterion> */
    public readonly array $children;

    /**
     * @param array<int|string, Criterion> $children Kept in order, as a list.
     *
     * @throws \InvalidArgumentException when a child is not a Criterion
     */
    public function __construct(array $children)
    {
        foreach ($children as $child) {
            if (!$child instanceof Criterion) {
                throw new \InvalidArgumentException(sprintf('%s children must be Criterion objects, %s given.', self::class, get_debug_type($child)));
            }
        }
        $this->children = array_values($children);
    }
}
