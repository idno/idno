<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * An entity of the collection `entities` with the indexed field `f` (fully qualified attributes).
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_field')]
#[\Idno\Storage\Attribute\IndexedField('f')]
final class FieldRecord
{
}
