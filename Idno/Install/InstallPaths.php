<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * Where an installation keeps what the installer reads and writes, under the project's root: the
 * configuration file, the marker of a setup that is not finished, the default SQLite database, the
 * upload directory and the template of the configuration file.
 */
final class InstallPaths
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function configDir(): string
    {
        return $this->root . '/configuration';
    }

    public function configFile(): string
    {
        return $this->configDir() . '/config.ini';
    }

    /**
     * The file that exists from the moment the web installer writes the configuration (or
     * `idno:setup:link` runs) until the site has an administrator. It holds the SHA-256 of the
     * setup token and the site's name, never the token.
     */
    public function marker(): string
    {
        return $this->configDir() . '/setup-pending';
    }

    public function dataDir(): string
    {
        return $this->root . '/data';
    }

    public function sqliteFile(): string
    {
        return $this->dataDir() . '/idno.sqlite';
    }

    public function uploadDir(): string
    {
        return $this->root . '/Uploads';
    }

    public function template(): string
    {
        return $this->root . '/warmup/webserver-configs/config.ini.template';
    }

    /**
     * The configuration files the site reads, in either location, that exist (readable or not).
     *
     * @return string[]
     */
    public function existingConfigFiles(): array
    {
        $found = [];
        foreach ([$this->root, $this->configDir()] as $directory) {
            foreach (['config.ini', 'config.json'] as $name) {
                if (file_exists($directory . '/' . $name)) {
                    $found[] = $directory . '/' . $name;
                }
            }
        }

        return $found;
    }
}
