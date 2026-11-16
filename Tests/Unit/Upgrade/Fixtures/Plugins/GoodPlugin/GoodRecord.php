<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Fixtures\Plugins\GoodPlugin;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * A plugin entity with a valid declaration (UpgradeRunnerTest): an enabled plugin's index.
 */
#[AsEntity('upgrade_fixture_good')]
#[IndexedField('upgrade_good_field')]
final class GoodRecord
{
}
