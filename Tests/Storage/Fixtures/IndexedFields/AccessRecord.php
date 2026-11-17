<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * An entity of the collection `entities` that declares `access`, which the schema indexes already.
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_access')]
#[\Idno\Storage\Attribute\IndexedField('access')]
final class AccessRecord
{
}
