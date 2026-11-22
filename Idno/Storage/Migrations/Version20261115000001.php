<?php

declare(strict_types=1);

namespace Idno\Storage\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The first "indexed fields" migration: no SQL of its own. The upgrade (Idno\Upgrade\UpgradeRunner)
 * holds back the NEWEST pending migration, whichever it is, runs every other pending migration,
 * creates the indexes the entity classes declare (#[IndexedField]), and records the held-back one only
 * once a reconcile pass finds nothing left to create; until then the schema is not current and the
 * schema gate stays closed, so a step killed between the baseline and the indexes is carried on by the
 * next one. When this migration was the newest it was that one. Now that later migrations exist it is
 * recorded with the baseline, before the indexes, and the newest migration is the one held back.
 *
 * A new #[IndexedField] declaration on a first-party class ships with a new migration like this one
 * (empty, its own version): being the newest, it is what makes existing sites reconcile on upgrade.
 */
final class Version20261115000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An empty migration of the indexed fields (no SQL; it was the one the upgrade held back until the indexes existed, while it was the newest).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Nothing (see the class comment).
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A marker of reconciled indexes is not undone.');
    }
}
