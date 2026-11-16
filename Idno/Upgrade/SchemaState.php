<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;

/**
 * Works out what the site's database is (SchemaStatus), each time status() is called. In order:
 *
 *  1. NotApplicable when ConnectionParameters::driverFamily() is neither `mysql` nor `sqlite`:
 *     nothing is fetched (no connection, catalog, migrator or copier).
 *  2. Unreachable when CONNECTING fails: Connection::getServerVersion() (which connects and reads
 *     the version the driver reports, without a statement) throws and the connection is not
 *     connected. Any other failure there is thrown: the gate never opens on a database it could not
 *     check. (Platform\ConnectFailureIsFinal makes that connect failure final for the request.)
 *  3. UnsupportedServer on MySQL below 8.0 or MariaDB below 10.6 (ServerVersion).
 *  4. The tables, from the SchemaCatalog (its one statement, which the record store then reuses:
 *     the catalog is the container's shared instance).
 *  5. On a migrated database (`idno_migrations` exists): SchemaMigrator::probe(), ONE statement that
 *     says whether a migration is pending and whether an upgrade is on record, complete or not
 *     (the progress rows of `idno_upgrade_progress`, when that table exists). That is the one
 *     statement the gate adds to a request, whatever other tables there are.
 *  6. The 1.x part (MySQL and MariaDB; no 1.x site runs on SQLite), the rule stated once, here:
 *     - an upgrade on record and not complete → Legacy, whatever the tables look like (a 1.x or
 *       renamed table dropped by hand mid-upgrade keeps the gate closed; the copier says which);
 *     - otherwise a 1.x database (LegacyDataCopier::isOneXDatabase(): `entities`,
 *       `entities_metadata` AND `entities_search`) with no complete upgrade on record → Legacy, or
 *       LegacyTooOld when its schema version is below OLDEST_LEGACY_SCHEMA or cannot be read (only
 *       then are the 1.x version statements sent);
 *     - a complete upgrade on record and the three 1.x `entities` tables present again
 *       (LegacyDataCopier::isOneXBesideCompletedUpgrade()) → OneXBesideCompletedUpgrade (closed;
 *       nothing is done): an undo or a restored backup;
 *     - otherwise the three renamed tables (LegacyDataCopier::isRenamedOneXDatabase()) with no
 *       progress row at all → RenamedWithoutRecord (closed; nothing is done);
 *     - everything else is not a 1.x database: a complete upgrade's `legacy_` tables, 1.x tables
 *       recreated after completion (any but all three `entities` tables), another application's `entities`, `versions`, `legacy_config`
 *       ... ask nothing and never close the gate.
 *  7. Empty when there is no `idno_migrations` table; PendingMigrations when the probe says so;
 *     otherwise Current.
 *
 * Each closure returns the service it names; nothing is fetched before it is needed.
 */
final class SchemaState
{
    /** The oldest 1.x schema the copy reads (Idno 1.6.4). */
    public const OLDEST_LEGACY_SCHEMA = 2020120301;

    private ?PublicMessage $refusal = null;

    /**
     * @param \Closure(): Connection        $connection
     * @param \Closure(): SchemaCatalog     $catalog
     * @param \Closure(): SchemaMigrator    $migrator
     * @param \Closure(): LegacyDataCopier  $copier
     */
    public function __construct(
        private readonly BootConfig $config,
        private readonly \Closure $connection,
        private readonly \Closure $catalog,
        private readonly \Closure $migrator,
        private readonly \Closure $copier,
    )
    {
    }

    public function status(): SchemaStatus
    {
        $this->refusal = null;
        $family = ConnectionParameters::driverFamily($this->config->all());
        if ($family !== 'mysql' && $family !== 'sqlite') {
            return SchemaStatus::NotApplicable;
        }
        $connection = ($this->connection)();
        try {
            $version = (string) $connection->getServerVersion();
        } catch (\Throwable $e) {
            if ($connection->isConnected()) {
                throw $e;
            }

            return SchemaStatus::Unreachable;
        }
        if ($family === 'mysql') {
            $this->refusal = ServerVersion::refusal($version);
            if ($this->refusal !== null) {
                return SchemaStatus::UnsupportedServer;
            }
        }

        $tables = ($this->catalog)()->tables();
        $migrated = in_array(SchemaMigrator::METADATA_TABLE, $tables, true);
        $probe = $migrated
            ? ($this->migrator)()->probe(in_array(LegacyDataCopier::PROGRESS_TABLE, $tables, true) ? LegacyDataCopier::PROGRESS_TABLE : null)
            : ['pending' => true, 'incomplete' => false, 'complete' => false];
        if ($family === 'mysql') {
            if ($probe['incomplete']) {
                return SchemaStatus::Legacy;
            }
            if (LegacyDataCopier::isOneXBesideCompletedUpgrade($tables, $probe['complete'])) {
                // An undo or a restored backup beside the copy: never open the site on either.
                return SchemaStatus::OneXBesideCompletedUpgrade;
            }
            if (!$probe['complete'] && LegacyDataCopier::isOneXDatabase($tables)) {
                $legacyVersion = ($this->copier)()->legacySchemaVersion();

                return $legacyVersion !== null && $legacyVersion >= self::OLDEST_LEGACY_SCHEMA ? SchemaStatus::Legacy : SchemaStatus::LegacyTooOld;
            }
            if (!$probe['complete'] && LegacyDataCopier::isRenamedOneXDatabase($tables)) {
                // No progress row at all (an incomplete one was handled above): renamed 1.x tables
                // nobody is upgrading. Never set an empty site up beside them.
                return SchemaStatus::RenamedWithoutRecord;
            }
        }
        if (!$migrated) {
            return SchemaStatus::Empty;
        }

        return $probe['pending'] ? SchemaStatus::PendingMigrations : SchemaStatus::Current;
    }

    /**
     * For UnsupportedServer (after status()): what is required and what was found; otherwise null.
     */
    public function refusal(): ?PublicMessage
    {
        return $this->refusal;
    }
}
