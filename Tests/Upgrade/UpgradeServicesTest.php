<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\UpgradeLock;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade's services in the rig's container: the copier logs on the Monolog channel `upgrade`
 * (not through the legacy logger). Skipped on a site whose
 * database is not SQL (a plugin's backend class), where the copier has no dialect.
 */
final class UpgradeServicesTest extends TestCase
{
    public function testTheCopierLogsOnTheUpgradeChannel(): void
    {
        $container = Idno::container();
        if (!in_array(ConnectionParameters::driverFamily($container->get(BootConfig::class)->all()), ['mysql', 'sqlite'], true)) {
            self::markTestSkipped('The site\'s database is not SQL: the copier has no dialect here.');
        }

        $copier = $container->get(LegacyDataCopier::class);

        self::assertInstanceOf(LegacyDataCopier::class, $copier);
        $logger = (new \ReflectionProperty(LegacyDataCopier::class, 'logger'))->getValue($copier);
        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('upgrade', $logger->getName());
        self::assertInstanceOf(UpgradeLock::class, (new \ReflectionProperty(LegacyDataCopier::class, 'lock'))->getValue($copier));
    }
}
