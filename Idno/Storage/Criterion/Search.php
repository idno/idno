<?php

declare(strict_types=1);

namespace Idno\Storage\Criterion;

/**
 * Full-text search over the searchable fields of a record.
 */
final class Search implements Criterion
{
    public function __construct(public readonly string $query)
    {
    }
}
