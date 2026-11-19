<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Core\Installer;

/**
 * An Installer whose root is a directory of the test's own, with a say in which PHP modules count as
 * loaded, and its protected methods made callable.
 */
final class InstallerUnderTest extends Installer
{
    public function __construct(string $root, private readonly bool $sqliteLoaded = true)
    {
        parent::__construct();
        $this->root_path = $root;
    }

    public function run()
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function params(string $database, array $input): array
    {
        return $this->databaseParams($database, $input);
    }

    /**
     * @param array<string, string> $params
     */
    public function config(array $params): string
    {
        return $this->buildConfig($params);
    }

    public function defaultSqlitePath(): string
    {
        return parent::defaultSqlitePath();
    }

    public static function type(string $database): string
    {
        return self::databaseType($database);
    }

    protected function moduleLoaded(string $module): bool
    {
        return $module === 'pdo_sqlite' ? $this->sqliteLoaded : parent::moduleLoaded($module);
    }
}
