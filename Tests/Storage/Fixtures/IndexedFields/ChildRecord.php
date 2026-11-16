<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * An entity of the collection `reader` that declares a field of its own and inherits one.
 */
#[AsEntity('fixture_child', collection: 'reader')]
#[IndexedField('child_field')]
final class ChildRecord extends DeclaringParent
{
}
