<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Fixtures\Plugins\BadDeclaration;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * A plugin entity whose declaration is invalid (UpgradeRunnerTest): reconciling it throws.
 */
#[AsEntity('upgrade_fixture_bad')]
#[IndexedField('Not a field name!')]
final class BadRecord
{
}
