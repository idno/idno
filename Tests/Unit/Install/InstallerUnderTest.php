<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Install\ConfigFile;
use Idno\Install\DatabaseChoice;
use Idno\Install\InstallPaths;

/**
 * The installer's database choice and configuration file on a root of the test's own, with a say in
 * whether the SQLite module counts as loaded.
 */
final class InstallerUnderTest
{
    private readonly InstallPaths $paths;
    private readonly DatabaseChoice $choice;

    public function __construct(string $root, bool $sqliteLoaded = true)
    {
        $this->paths = new InstallPaths($root);
        $this->choice = new DatabaseChoice($this->paths, static fn (string $module): bool => $module === 'pdo_sqlite' ? $sqliteLoaded : extension_loaded($module));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function params(string $database, array $input): array
    {
        return $this->choice->params($database, $input);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function config(array $params): string
    {
        return (new ConfigFile($this->paths))->build($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function assertWritableConfigValues(array $params): void
    {
        (new ConfigFile($this->paths))->assertWritable($params);
    }

    public function path(string $path): string
    {
        return $this->choice->sqlitePath($path);
    }

    public function defaultSqlitePath(): string
    {
        return $this->paths->sqliteFile();
    }

    public static function type(string $database): string
    {
        return DatabaseChoice::type($database);
    }
}
