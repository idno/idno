<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * field equals none of values. Values are exactly what the caller passed.
 */
final class NotIn implements Criterion
{
    /**
     * @param list<string|int|float|bool|null> $values
     */
    public function __construct(
        public readonly string $field,
        public readonly array $values,
    )
    {
    }
}
