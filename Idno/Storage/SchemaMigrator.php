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
     * Executes every pending migration, oldest first. Nothing happens when none is pending.
     *
     * @return list<string> the versions this call executed, oldest first (none when another
     *                      process executed them first)
     */
    public function migrate(): array
    {
        $dependencies = $this->dependencies();
        $dependencies->getMetadataStorage()->ensureInitialized();
        $versions = [];
        foreach ($dependencies->getMigrationStatusCalculator()->getNewMigrations()->getItems() as $migration) {
            $versions[] = $migration->getVersion();
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
