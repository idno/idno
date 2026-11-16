<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Fixtures\Plugins\UniqueClash;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * A plugin entity with a unique declaration (UpgradeRunnerTest), over a field the test stores
 * duplicate values in: the index cannot be built.
 */
#[AsEntity('upgrade_fixture_unique')]
#[IndexedField('upgrade_clash_field', unique: true)]
final class UniqueRecord
{
}
