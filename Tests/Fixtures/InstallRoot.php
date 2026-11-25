<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Config\BootConfig;
use Idno\Config\LegacyConfigLoader;
use Idno\Install\InstallPaths;

/**
 * Test environment only: lets a test's site keep what an installation writes (the configuration, the
 * setup marker, the SQLite database, uploads) in a directory of the test's own, named by the
 * environment variable IDNO_TEST_INSTALL_ROOT. Without the variable the project directory is used,
 * as in production. The directory needs the configuration template at
 * warmup/webserver-configs/config.ini.template.
 */
final class InstallRoot
{
    public static function root(string $projectDir): string
    {
        $root = $_SERVER['IDNO_TEST_INSTALL_ROOT'] ?? getenv('IDNO_TEST_INSTALL_ROOT');

        return is_string($root) && $root !== '' && is_dir($root) ? $root : $projectDir;
    }

    public static function bootConfig(string $projectDir): BootConfig
    {
        return (new LegacyConfigLoader())->load(self::root($projectDir), $_SERVER, $_ENV);
    }

    public static function paths(string $projectDir): InstallPaths
    {
        return new InstallPaths(self::root($projectDir));
    }
}
