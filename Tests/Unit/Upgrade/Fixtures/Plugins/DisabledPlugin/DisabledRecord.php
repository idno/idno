<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Fixtures\Plugins\DisabledPlugin;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * A plugin entity of a plugin that is present but not enabled (UpgradeRunnerTest): the upgrade
 * never creates its index.
 */
#[AsEntity('upgrade_fixture_disabled')]
#[IndexedField('upgrade_disabled_field')]
final class DisabledRecord
{
}
