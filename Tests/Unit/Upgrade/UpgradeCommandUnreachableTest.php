<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Console\UpgradeCommand;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\IndexedFields;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\EnabledPlugins;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\LegacyTables;
use Idno\Upgrade\PublicMessage;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradeLock;
use Idno\Upgrade\UpgradeRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `idno:upgrade` and `idno:upgrade --status` when they are reached while the database cannot be
 * connected to (here an SQLite file in a directory that does not exist): the unreachable message on
 * stderr and exit 1, never a raw exception. (A console PROCESS on such a site normally never gets
 * here: the legacy application boots, because the gate is open for an unreachable database, and its
 * database error page ends the run; UpgradeCommandTest pins that.)
 */
final class UpgradeCommandUnreachableTest extends TestCase
{
    public function testBothReportTheUnreachableDatabaseAndExitOne(): void
    {
        $path = sys_get_temp_dir() . '/idno-no-such-dir-' . bin2hex(random_bytes(6)) . '/site.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path], (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]));
        $command = self::command($connection);
        self::assertSame(SchemaStatus::Unreachable, self::state($connection)->status(), 'the precondition');

        foreach ([['--status' => true], []] as $options) {
            $tester = new CommandTester($command);
            $exit = $tester->execute($options, ['capture_stderr_separately' => true]);

            self::assertSame(1, $exit, json_encode($options) . ': ' . $tester->getErrorOutput());
            self::assertSame(PublicMessage::unreachable()->text . "\n", $tester->getErrorOutput(), json_encode($options));
        }
        $tester = new CommandTester($command);
        $tester->execute(['--status' => true], ['capture_stderr_separately' => true]);
        self::assertSame("Schema status: unreachable\n", $tester->getDisplay(), 'the status line, then nothing else on stdout');
        self::assertDirectoryDoesNotExist(dirname($path), 'nothing was created');
    }

    private static function state(Connection $connection): SchemaState
    {
        $catalog = new SchemaCatalog($connection);
        $migrator = new SchemaMigrator($connection);
        $copier = new LegacyDataCopier($connection, new SqliteDialect(), new IdentityTypeMap(), new UpgradeLock($connection));

        return new SchemaState(
            new BootConfig(['database' => 'sqlite'], false),
            static fn (): Connection => $connection,
            static fn (): SchemaCatalog => $catalog,
            static fn (): SchemaMigrator => $migrator,
            static fn (): LegacyDataCopier => $copier,
        );
    }

    private static function command(Connection $connection): UpgradeCommand
    {
        $config = new BootConfig(['database' => 'sqlite'], false);
        $catalog = new SchemaCatalog($connection);
        $migrator = new SchemaMigrator($connection);
        $lock = new UpgradeLock($connection);
        $copier = new LegacyDataCopier($connection, new SqliteDialect(), new IdentityTypeMap(), $lock);
        $state = self::state($connection);
        $runner = new UpgradeRunner(
            $state,
            $migrator,
            new IndexedFields($connection, new SqliteDialect(), $catalog),
            $catalog,
            $copier,
            $lock,
            new EnabledPlugins($config, $connection),
            new NullLogger(),
            dirname(__DIR__, 3),
            lockWaitSeconds: 0.0,
        );
        $never = static function (): never {
            throw new \LogicException('Not asked.');
        };

        return new UpgradeCommand($runner, $state, $migrator, $copier, new LegacyTables($config, static fn (): Connection => $connection, $never, $never, $never));
    }
}
