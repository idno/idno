<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Psr\Log\NullLogger;

/**
 * Runs the schema migrations (the classes in Idno/Storage/Migrations/, namespace
 * Idno\Storage\Migrations) on a connection, with doctrine/migrations used as a library: the
 * migrations executed are recorded in the table `idno_migrations`, which migrate() creates.
 *
 * Constructing it queries nothing. Each call reads the database afresh. It writes no output.
 * Versions are the numeric part of a migration's class name (`Version20261115000000` is
 * '20261115000000').
 */
final class SchemaMigrator
{
    public const METADATA_TABLE = 'idno_migrations';

    public const NAMESPACE = 'Idno\\Storage\\Migrations';

    /** probe()'s markers: no migration version can look like them. */
    private const INCOMPLETE = '#incomplete';

    private const COMPLETE = '#complete';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The migrations not yet executed on this database, oldest first. Asking creates nothing.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $versions = [];
        foreach ($this->dependencies()->getMigrationStatusCalculator()->getNewMigrations()->getItems() as $migration) {
            $versions[] = self::number($migration->getVersion());
        }

        return $versions;
    }

    /**
     * Whether a migration on disk has not been executed: pending() !== [], answered with ONE
     * statement (the executed versions, from `idno_migrations`, which must exist) compared with the
     * migration classes in Idno/Storage/Migrations/, without doctrine/migrations' machinery. It is
     * probe()'s `pending` without a progress table (the schema gate asks probe(), which also reads
     * the upgrade's progress rows in the same statement).
     */
    public function hasPending(): bool
    {
        return $this->probe()['pending'];
    }

    /**
     * hasPending() and, in the same ONE statement, whether rows of $progressTable (the upgrade's
     * progress table, `idno_upgrade_progress`; it must exist when given) are incomplete (`completed_at`
     * null) or complete: the executed versions UNION ALL one marker per progress row. It is what the
     * schema gate sends on every request to a migrated database. Same SQL on MySQL, MariaDB and SQLite.
     *
     * @return array{pending: bool, incomplete: bool, complete: bool}
     */
    public function probe(?string $progressTable = null): array
    {
        $sql = 'select version from ' . self::METADATA_TABLE;
        if ($progressTable !== null) {
            if (preg_match('/^[a-z0-9_]{1,64}$/', $progressTable) !== 1) {
                throw new \InvalidArgumentException('Not a table name: ' . $progressTable);
            }
            $sql .= " union all select case when completed_at is null then '" . self::INCOMPLETE . "' else '" . self::COMPLETE . "' end from " . $progressTable;
        }
        $rows = array_map('strval', $this->connection->fetchFirstColumn($sql));
        $executed = array_flip($rows);
        $pending = false;
        foreach (glob(__DIR__ . '/Migrations/Version*.php') ?: [] as $file) {
            if (!isset($executed[self::NAMESPACE . '\\' . basename($file, '.php')])) {
                $pending = true;
                break;
            }
        }

        return ['pending' => $pending, 'incomplete' => isset($executed[self::INCOMPLETE]), 'complete' => isset($executed[self::COMPLETE])];
    }

    /**
     * Executes every pending migration, oldest first. Nothing happens when none is pending. With
     * $exceptLast the newest pending migration is left pending: the upgrade records it only once the
     * declared indexed fields exist (see UpgradeRunner).
     *
     * @return list<string> the versions this call executed, oldest first (none when another
     *                      process executed them first)
     */
    public function migrate(bool $exceptLast = false): array
    {
        $dependencies = $this->dependencies();
        $dependencies->getMetadataStorage()->ensureInitialized();
        $versions = [];
        foreach ($dependencies->getMigrationStatusCalculator()->getNewMigrations()->getItems() as $migration) {
            $versions[] = $migration->getVersion();
        }
        if ($exceptLast) {
            array_pop($versions);
        }
        if ($versions === []) {
            return [];
        }
        $plan = $dependencies->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP);
        $executed = $dependencies->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(false));

        return array_values(array_map(static fn (string|int $version): string => self::number(new Version((string) $version)), array_keys($executed)));
    }

    /**
     * The version of the newest migration executed on this database, or '0' when none has been.
     */
    public function latest(): string
    {
        $latest = '0';
        foreach ($this->dependencies()->getMetadataStorage()->getExecutedMigrations()->getItems() as $migration) {
            $number = self::number($migration->getVersion());
            if (strlen($number) > strlen($latest) || (strlen($number) === strlen($latest) && strcmp($number, $latest) > 0)) {
                $latest = $number;
            }
        }

        return $latest;
    }

    private static function number(Version $version): string
    {
        return preg_match('/Version(\d+)$/', (string) $version, $matches) === 1 ? $matches[1] : (string) $version;
    }

    private function dependencies(): DependencyFactory
    {
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName(self::METADATA_TABLE);

        $configuration = new Configuration();
        $configuration->addMigrationsDirectory(self::NAMESPACE, __DIR__ . '/Migrations');
        $configuration->setMetadataStorageConfiguration($storage);
        $configuration->setAllOrNothing(false);
        $configuration->setCheckDatabasePlatform(false);

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->connection),
            new NullLogger(),
        );
    }
}
