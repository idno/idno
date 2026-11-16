<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * An entity of the collection `entities` with one indexed field, written with imported attributes.
 */
#[AsEntity('fixture_actor')]
#[IndexedField('actor_id')]
final class ActorRecord
{
}
