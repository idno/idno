<?php

// No strict_types here, on purpose: this is Idno\Data\MySQL::checkAndUpgradeSchema() and
// AbstractSQL::getVersions() moved unchanged, and they pass database values to PHP functions as
// the legacy backend did.

namespace Idno\Legacy;

/**
 * The legacy MySQL schema upgrade, moved unchanged from Idno\Data\MySQL::checkAndUpgradeSchema()
 * (and the `versions` lookup from Idno\Data\AbstractSQL::getVersions()), for
 * RepositoryDataConcierge. It runs on the native PDO of the shared DBAL connection, the PDO the
 * legacy backend's own connection was.
 *
 * upgrade() applies, in one transaction each, the warmup/schemas/mysql/<date>.sql files newer than
 * the `schema` version, as the legacy backend did when it was constructed. It is a no-op for any
 * family but 'mysql': the SQLite schema (warmup/schemas/sqlite/sqlite.sql) is installed whole and has
 * no upgrades. Failures are written to PHP's error log, as before (the site's logger does not exist
 * yet when the db() service is built).
 *
 * Not a container service: RepositoryDataConcierge builds it.
 */
final class LegacySchemaUpgrader
{
    /** The upgrades, oldest first (Idno\Data\MySQL::checkAndUpgradeSchema()'s list). */
    public const UPGRADES = [
        // List upgrades, add yours to the end
        2014100801,
        2015061501,
        2016013101,
        2016102601,
        2016110301,
        2017032001,
        2019060501,
        2019121401,
        2020042101,
        2020111301,
        2020120201,
        2020120301,
    ];

    /**
     * Retrieve version information from the schema: the rows of `versions` as objects, or false.
     *
     * @return array|bool
     */
    public function versions(\PDO $client)
    {
        try {
            $statement = $client->prepare("select * from versions");
            if ($statement->execute()) {
                return $statement->fetchAll(\PDO::FETCH_OBJ);
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return false;
    }

    /**
     * Checks the current schema version and upgrades if necessary (MySQL only).
     */
    public function upgrade(\PDO $client, string $family): void
    {
        if ($family !== 'mysql') {
            return;
        }

        $versions = $this->versions($client);
        if (!$versions) {
            $versions = [(object)['label' => 'schema', 'value' => 0]];
        }
        if ($versions) {
            foreach ($versions as $version) {
                if ($version->label === 'schema') {
                    $basedate          = $newdate = (int)$version->value;
                    $upgrade_sql_files = array();
                    $schema_dir        = dirname(dirname(dirname(__FILE__))) . '/warmup/schemas/mysql/';

                    // Optimise upgrades
                    foreach (self::UPGRADES as $date) {
                        if ($basedate < $date) {
                            try {
                                $client->beginTransaction();

                                if ($sql = @file_get_contents($schema_dir . $date . '.sql')) {

                                    error_log("Applying schema updates from {$schema_dir}{$date}.sql");

                                    $statements = explode(";\n", $sql); // Explode statements; only mysql can support multiple statements per line, and then only badly.
                                    foreach ($statements as $sql) {
                                        $sql = trim($sql);
                                        if (!empty($sql)) {
                                            $statement = $client->prepare($sql);
                                            $statement->execute();
                                        }
                                    }
                                }
                                $newdate = $date;

                                $client->commit();

                            } catch (\Exception $e) {
                                error_log($e->getMessage());
                                if ($client->inTransaction()) {
                                    $client->rollback();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
