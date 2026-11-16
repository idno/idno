<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * An entity of a collection that has no table.
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_missing', collection: 'nosuchcollection')]
#[\Idno\Storage\Attribute\IndexedField('f')]
final class MissingCollectionRecord
{
}
