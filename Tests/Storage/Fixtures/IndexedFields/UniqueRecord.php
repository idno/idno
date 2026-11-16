<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * An entity whose field `code` is unique.
 */
#[AsEntity('fixture_unique')]
#[IndexedField('code', unique: true)]
final class UniqueRecord
{
}
