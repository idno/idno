<?php

declare(strict_types=1);

namespace Idno\Storage\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The marker "the declared indexed fields have been reconciled": no SQL of its own. The upgrade
 * (Idno\Upgrade\UpgradeRunner) runs every pending migration but the newest, then creates the indexes
 * the entity classes declare (#[IndexedField]), and records the newest pending migration only once a
 * reconcile pass finds nothing left to create. While it is pending the schema is not current and the
 * schema gate stays closed, so a step killed between the baseline and the indexes is carried on by
 * the next one.
 *
 * A new #[IndexedField] declaration on a first-party class ships with a new migration like this one
 * (empty, its own version): that is what makes existing sites reconcile on upgrade.
 */
final class Version20261115000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The declared indexed fields have been reconciled (no SQL: recorded by the upgrade once the indexes exist).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Nothing: this version is recorded once the declared indexes exist (see the class comment).
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A marker of reconciled indexes is not undone.');
    }
}
