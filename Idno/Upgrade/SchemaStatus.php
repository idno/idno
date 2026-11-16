<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * What the site's database is, as far as its schema goes (SchemaState::status()).
 *
 *  - NotApplicable: the site's database is not SQL (MongoDB, or another backend class): there is
 *    no schema to check, and nothing was asked of any database.
 *  - Unreachable: connecting failed. The legacy application boots as before and shows its own
 *    database error page.
 *  - UnsupportedServer: a MySQL server older than 8.0 or a MariaDB server older than 10.6.
 *  - Empty: no schema yet (no `idno_migrations` table): a new installation.
 *  - Current: the schema is up to date.
 *  - PendingMigrations: a schema migration has not run yet.
 *  - Legacy: a 1.x database to copy into the new schema, or a copy that has begun and is not
 *    complete (the 1.x tables, under their own names or renamed `legacy_<name>`).
 *  - LegacyTooOld: a 1.x database older than the oldest schema the copy reads (2020120301).
 *  - RenamedWithoutRecord: the three 1.x `entities` tables renamed `legacy_<name>` with no upgrade
 *    on record (no progress row at all): nothing is done until someone renames them back or removes
 *    them, so that an empty site is never set up beside the content.
 *  - OneXBesideCompletedUpgrade: an upgrade is complete on record, and the three 1.x `entities`
 *    tables (`entities`, `entities_metadata`, `entities_search`) exist again
 *    (LegacyDataCopier::isOneXBesideCompletedUpgrade()). A booting 1.x process re-creates several
 *    1.x tables, `entities_metadata` and `entities_search` among them, but never `entities`; all
 *    three mean an undo or a restored backup: opening the site on the earlier copy would drop
 *    everything written to the 1.x site since. Nothing is done until someone removes one set.
 *
 * The gate is closed (the legacy application does not boot) for Empty, PendingMigrations, Legacy,
 * LegacyTooOld, RenamedWithoutRecord, OneXBesideCompletedUpgrade and UnsupportedServer; open for Current, Unreachable and NotApplicable.
 */
enum SchemaStatus: string
{
    case NotApplicable = 'not applicable';
    case Unreachable = 'unreachable';
    case UnsupportedServer = 'unsupported server';
    case Empty = 'empty';
    case Current = 'current';
    case PendingMigrations = 'pending migrations';
    case Legacy = 'legacy';
    case LegacyTooOld = 'legacy too old';
    case RenamedWithoutRecord = 'renamed 1.x tables without an upgrade on record';
    case OneXBesideCompletedUpgrade = '1.x tables beside a completed upgrade';

    /**
     * Whether the legacy application must not boot on a database in this state.
     */
    public function closesTheGate(): bool
    {
        return match ($this) {
            self::Empty, self::PendingMigrations, self::Legacy, self::LegacyTooOld, self::RenamedWithoutRecord, self::OneXBesideCompletedUpgrade, self::UnsupportedServer => true,
            self::Current, self::Unreachable, self::NotApplicable => false,
        };
    }
}
