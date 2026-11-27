<?php

declare(strict_types=1);

namespace Idno\Security;

use Idno\Kernel\CacheDirResolver;

/**
 * Where a site's PHP session files are kept when no directory was named: a private directory of the site's own, so that
 * PHP's garbage collection reads this site's sessions and not every site's of the machine (a shared directory made one
 * request in a thousand stat every file in it), and so that nobody who can list the directory learns session ids (a
 * session file's name is its id).
 *
 * The choice, in order: the boot setting `session_path` as given; a directory PHP's `session.save_path` names (PHP's
 * own, collected by the host); else Idno's own, the first usable of the override (`IDNO_SESSION_DIR`),
 * `<project>/var/sessions` and a per-project directory under the system's temporary directory. Idno's own is made 0700
 * and accepted only when only this user can use it.
 */
final class SessionDirectory
{
    /**
     * @return array{path: ?string, own: bool, problem: ?string} `path` null means PHP's own (none to set); `own` is true for a
     *                                                           directory of Idno's own, whose garbage collection Idno switches on
     */
    public static function choose(?string $configured, string $iniPath, ?string $override, string $projectDir, string $temporaryDirectory): array
    {
        if ($configured !== null && $configured !== '') {
            return ['path' => $configured, 'own' => false, 'problem' => null];
        }
        // PHP writes `N;MODE;/path` for a directory with levels: the path is the part after the last `;`
        $named = ($semicolon = strrpos($iniPath, ';')) === false ? $iniPath : substr($iniPath, $semicolon + 1);
        if (trim($named) !== '') {
            return ['path' => null, 'own' => false, 'problem' => null];
        }

        $candidates = [];
        if ($override !== null && $override !== '') {
            $candidates[] = $override;
        }
        $var = $projectDir . '/var';
        if (is_dir($var) && is_writable($var)) {
            $candidates[] = $var . '/sessions';
        }
        $real = realpath($projectDir);
        $candidates[] = rtrim($temporaryDirectory, '/') . '/idno-sessions-' . substr(hash('sha256', $real === false ? $projectDir : $real), 0, 12);

        foreach ($candidates as $directory) {
            if (self::usable($directory)) {
                return ['path' => $directory, 'own' => true, 'problem' => null];
            }
        }

        return [
            'path'    => null,
            'own'     => false,
            'problem' => 'No private directory could be made for session files, so PHP\'s own is used. Set session_path in configuration/config.ini, or IDNO_SESSION_DIR, to a directory only the web server user can use.',
        ];
    }

    private static function usable(string $directory): bool
    {
        if (is_link($directory)) {
            return false;
        }
        if (!file_exists($directory)) {
            @mkdir($directory, 0700, true);
            @chmod($directory, 0700);
        }
        if (!CacheDirResolver::usableAndPrivate($directory, requireOwner: true)) {
            return false;
        }
        clearstatcache(true, $directory);
        $perms = fileperms($directory);

        // the cache's rule refuses a directory others can WRITE; a session directory others can LIST gives their ids away
        return $perms !== false && ($perms & 0077) === 0;
    }
}
