<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Fixtures\BadCore;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * A core directory (for UpgradeRunnerTest) whose declaration is invalid: the core reconcile fails,
 * and the marker migration must stay pending.
 */
#[AsEntity('upgrade_fixture_bad_core')]
#[IndexedField('Not a field name!')]
final class BadCoreRecord
{
}
