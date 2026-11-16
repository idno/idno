<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

use Idno\Storage\Attribute\IndexedField;

/**
 * Declares an indexed field but is no entity type of its own (no #[AsEntity]): its declaration
 * reaches the database through its subclasses.
 */
#[IndexedField('parent_field')]
abstract class DeclaringParent
{
}
