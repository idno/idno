<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * What a server must have before Idno can be installed on it, checked from facts the caller gives
 * (so that it can be tested): the PHP version, the PHP extensions, the folders the web server must
 * be able to write to, a secure connection and the site being at the top of its domain.
 */
final class ServerChecks
{
    public const PHP_VERSION = '8.4';

    /** The extensions Idno needs whatever the database. */
    public const EXTENSIONS = ['curl', 'date', 'dom', 'gd', 'json', 'libxml', 'mbstring', 'pdo', 'reflection', 'session', 'simplexml', 'openssl'];

    /** At least one of these is needed. */
    public const DATABASE_EXTENSIONS = ['pdo_sqlite', 'pdo_mysql'];

    /**
     * @param array{php?: string, extension?: callable(string): bool, writable?: callable(string): bool, secure?: bool, host?: string, basePath?: string, cacheInTemp?: bool, web?: bool} $facts
     *        `web` false leaves out what only a browser install needs (a secure connection, the base path)
     * @return list<CheckResult>
     */
    public static function run(InstallPaths $paths, array $facts = []): array
    {
        $php = $facts['php'] ?? PHP_VERSION;
        $extension = $facts['extension'] ?? static fn (string $name): bool => extension_loaded($name);
        $writable = $facts['writable'] ?? static fn (string $path): bool => is_dir($path) && is_writable($path);
        $web = $facts['web'] ?? true;
        $results = [];

        $results[] = version_compare($php, self::PHP_VERSION, '>=')
            ? new CheckResult('php', CheckResult::OK, 'PHP ' . $php)
            : new CheckResult(
                'php',
                CheckResult::FAIL,
                'PHP ' . $php,
                'Idno needs PHP ' . self::PHP_VERSION . ' or later, and this server runs PHP ' . $php . '.',
                'In your hosting control panel, look for "PHP version" (sometimes "Select PHP version" or "MultiPHP") and choose ' . self::PHP_VERSION . ' or later for this domain. If there is no such choice, ask your host to upgrade PHP.'
            );

        $missing = array_values(array_filter(self::EXTENSIONS, static fn (string $name): bool => !$extension($name)));
        $databases = array_values(array_filter(self::DATABASE_EXTENSIONS, static fn (string $name): bool => $extension($name)));
        if ($missing === [] && $databases !== []) {
            $results[] = new CheckResult('extensions', CheckResult::OK, 'PHP extensions');
        } else {
            $needed = $missing;
            if ($databases === []) {
                $needed[] = 'pdo_sqlite (or pdo_mysql)';
            }
            $results[] = new CheckResult(
                'extensions',
                CheckResult::FAIL,
                'PHP extensions',
                'Idno needs these PHP extensions, which are not switched on: ' . implode(', ', $needed) . '.',
                'In your hosting control panel, look for "PHP extensions" and tick them. On your own server, install the packages for your PHP version, then restart the web server. For example:',
                'sudo apt install ' . implode(' ', array_map(static fn (string $name): string => 'php-' . self::package($name), $needed === [] ? ['sqlite3'] : array_map(static fn (string $n): string => explode(' ', $n)[0], $needed)))
            );
        }

        $folders = [
            'configuration' => [$paths->configDir(), 'Idno saves its settings in this folder'],
            'uploads' => [$paths->uploadDir(), 'Idno saves uploaded pictures and files in this folder'],
        ];
        foreach ($folders as $id => [$path, $why]) {
            $results[] = $writable($path)
                ? new CheckResult($id, CheckResult::OK, 'The folder ' . basename($path) . ' can be written to')
                : self::unwritable($id, $path, $why, CheckResult::FAIL, $paths);
        }
        if (in_array('pdo_sqlite', $databases, true)) {
            if ($writable($paths->dataDir())) {
                $results[] = new CheckResult('data', CheckResult::OK, 'The folder data can be written to');
            } else {
                $results[] = self::unwritable(
                    'data',
                    $paths->dataDir(),
                    'Idno keeps its database in this folder when the database is a file on this server'
                        . (in_array('pdo_mysql', $databases, true) ? ' (you can still use a MySQL or MariaDB database)' : ''),
                    in_array('pdo_mysql', $databases, true) ? CheckResult::WARN : CheckResult::FAIL,
                    $paths
                );
            }
        }
        if (!empty($facts['cacheInTemp'])) {
            $results[] = self::unwritable('var', $paths->root() . '/var', 'Idno keeps prepared files in this folder to be fast; it is using a temporary folder instead, which is slower after each restart', CheckResult::WARN, $paths);
        }

        if ($web) {
            $host = (string) ($facts['host'] ?? '');
            if (($facts['basePath'] ?? '') !== '') {
                $results[] = new CheckResult(
                    'root',
                    CheckResult::FAIL,
                    'Idno at the top of its domain',
                    'Idno is in a folder of this domain, and it has to be at the top of a domain or subdomain (for example notes.example.com).',
                    'In your hosting control panel, create a subdomain and point it at the folder Idno is in, then open that subdomain.'
                );
            }
            if (!empty($facts['secure'])) {
                $results[] = new CheckResult('https', CheckResult::OK, 'Secure connection (HTTPS)');
            } elseif (SiteAddress::isLocal($host)) {
                $results[] = new CheckResult('https', CheckResult::WARN, 'Secure connection (HTTPS)', 'This page was loaded without HTTPS. That is fine for trying Idno on your own computer; a site on the internet needs HTTPS.');
            } else {
                $results[] = new CheckResult(
                    'https',
                    CheckResult::FAIL,
                    'Secure connection (HTTPS)',
                    'This page was loaded without HTTPS, and Idno needs it: without it, passwords can be read on the network.',
                    'In your hosting control panel, switch on the free certificate for this domain (often called "SSL" or "Let\'s Encrypt"), then open this page again with https:// at the start of its address. If the address already starts with https://, the server in front of Idno is not saying so: it must send the header X-Forwarded-Proto: https.'
                );
            }
        }

        return $results;
    }

    /**
     * Whether a failed check stops the installation.
     *
     * @param list<CheckResult> $results
     */
    public static function blocking(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->state === CheckResult::FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<CheckResult> $results
     */
    public static function find(array $results, string $id): ?CheckResult
    {
        foreach ($results as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        return null;
    }

    private static function unwritable(string $id, string $path, string $why, string $state, InstallPaths $paths): CheckResult
    {
        $name = basename($path);

        return new CheckResult(
            $id,
            $state,
            'The folder ' . $name . ' can be written to',
            $why . ', and the web server is not allowed to write to it' . (is_dir($path) ? '' : ' (it does not exist)') . '.',
            'In your hosting file manager, ' . (is_dir($path) ? 'open the permissions of the folder ' . $name : 'create the folder ' . $name . ', open its permissions') . ' and allow the owner and the group to write (often shown as 775). Or, from a terminal in the folder Idno is in, with the user your web server runs as in place of www-data:',
            'mkdir -p ' . $name . ' && chown -R www-data ' . $name
        );
    }

    private static function package(string $extension): string
    {
        return match ($extension) {
            'pdo_sqlite' => 'sqlite3',
            'pdo_mysql' => 'mysql',
            'dom', 'simplexml', 'libxml' => 'xml',
            default => $extension,
        };
    }
}
