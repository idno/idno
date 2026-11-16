<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * Declares ActorRecord's field again, unique and with another length.
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_conflicting')]
#[\Idno\Storage\Attribute\IndexedField('actor_id', unique: true, length: 100)]
final class ConflictingRecord
{
}
