<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Config\BootConfig;
use Idno\Upgrade\EnabledPlugins;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * Which plugins are enabled, decided without the legacy application (the upgrade runs while it is
 * not booted): what Idno\Core\Config and Idno\Core\Plugins load, from the boot configuration and the
 * stored `plugins` setting.
 */
final class EnabledPluginsTest extends TestCase
{
    public function testTheStoredListAndTheAlwaysLoadedOnes(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->insert('idno_settings', ['name' => 'plugins', 'value' => '["Status","Text","Photo"]', 'position' => 0]);
        $config = new BootConfig(['database' => 'sqlite', 'alwaysplugins' => ['ActivityPub'], 'prerequisiteplugins' => ['Styles'], 'antiplugins' => ['Photo', 'Styles']], false);

        self::assertSame(['Styles', 'Status', 'Text', 'ActivityPub'], (new EnabledPlugins($config, $connection))->names(), 'prerequisites first, antiplugins left out (not from the prerequisites)');
    }

    /**
     * Idno\Core\Config merges the boot configuration over the stored settings with
     * array_replace_recursive(): a boot `plugins` list replaces the stored one index by index.
     */
    public function testABootPluginListIsMergedOverTheStoredOneByIndex(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->insert('idno_settings', ['name' => 'plugins', 'value' => '["Status","Text","Photo"]', 'position' => 0]);

        self::assertSame(['IndiePub', 'Text', 'Photo'], (new EnabledPlugins(new BootConfig(['plugins' => ['IndiePub']], false), $connection))->names());
    }

    public function testAnEmptyBootPluginListMeansTheStoredOne(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->insert('idno_settings', ['name' => 'plugins', 'value' => '["Status","Text"]', 'position' => 0]);

        self::assertSame(['Status', 'Text'], (new EnabledPlugins(new BootConfig(['plugins' => []], false), $connection))->names());
    }

    public function testStoredPrerequisitesCountToo(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->insert('idno_settings', ['name' => 'prerequisiteplugins', 'value' => '["Styles"]', 'position' => 0]);

        self::assertSame(['Styles', 'Status'], (new EnabledPlugins(new BootConfig(['alwaysplugins' => ['Status']], false), $connection))->names());
    }

    public function testWithoutTheSettingsOnlyTheAlwaysLoadedOnes(): void
    {
        $config = new BootConfig(['alwaysplugins' => ['Status', '../evil', 'Status']], false);

        self::assertSame(['Status'], (new EnabledPlugins($config, PrivateDatabase::sqliteMemory()))->names(), 'no row');
        self::assertSame(['Status'], (new EnabledPlugins($config, PrivateDatabase::sqliteMemory(migrate: false)))->names(), 'no table');
    }

    /**
     * Only "the table or the row is not there" means none: any other database error is thrown (the
     * runner turns it into a warning line for the plugin phase).
     */
    public function testAnyOtherDatabaseErrorIsThrown(): void
    {
        $connection = PrivateDatabase::sqliteMemory(migrate: false);
        $connection->executeStatement('create table idno_settings (something text)');

        $this->expectException(\Doctrine\DBAL\Exception::class);
        (new EnabledPlugins(new BootConfig([], false), $connection))->names();
    }
}
