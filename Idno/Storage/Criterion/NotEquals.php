<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * field does not equal value. The value is exactly what the caller passed (a bool stays a bool, an int an int).
 */
final class NotEquals implements Criterion
{
    public function __construct(
        public readonly string $field,
        public readonly string|int|float|bool|null $value,
    )
    {
    }
}
