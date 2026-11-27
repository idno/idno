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
     *                                                           directory of Idno's own, whose garbage collection Idno switches on;
     *                                                           `problem` is a sentence for the log (never a path outside the project),
     *                                                           set when a candidate that was asked for or exists could not be used,
     *                                                           whether or not a later one was
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

        $problems = [];
        if ($override !== null && $override !== '') {
            if (!self::isAbsolute($override)) {
                $reason = 'it is not an absolute path';
            } else {
                $reason = self::refusal($override);
            }
            if ($reason === null) {
                return ['path' => $override, 'own' => true, 'problem' => null];
            }
            $problems[] = 'IDNO_SESSION_DIR cannot be used (' . $reason . '), so another directory is used for sessions. Set it to an absolute directory only the web server user can use.';
        }

        $var = $projectDir . '/var';
        if (is_dir($var) && is_writable($var)) {
            $directory = $var . '/sessions';
            $existed   = file_exists($directory) || is_link($directory);
            $reason    = self::refusal($directory);
            if ($reason === null) {
                return ['path' => $directory, 'own' => true, 'problem' => $problems === [] ? null : implode(' ', $problems)];
            }
            if ($existed) {
                $problems[] = 'var/sessions cannot be used (' . $reason . '), so another directory is used for sessions. Change its owner to the web server user (chown -R <user> var/sessions) or remove it (rm -r var/sessions).';
            }
        }

        $real      = realpath($projectDir);
        $temporary = rtrim($temporaryDirectory, '/') . '/idno-sessions-' . substr(hash('sha256', $real === false ? $projectDir : $real), 0, 12);
        if (self::refusal($temporary) === null) {
            return ['path' => $temporary, 'own' => true, 'problem' => $problems === [] ? null : implode(' ', $problems)];
        }

        $problems[] = 'No private directory could be made for session files, so PHP\'s own is used. Set session_path in configuration/config.ini, or IDNO_SESSION_DIR, to a directory only the web server user can use.';

        return ['path' => null, 'own' => false, 'problem' => implode(' ', $problems)];
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\/]~', $path) === 1;
    }

    /**
     * Why the directory cannot be used (made 0700 when missing, tightened to 0700 when this user owns it and it is more open), or null when it can.
     * A session file's name is its id, so a directory others can list gives sessions away: it must be only this user's.
     */
    private static function refusal(string $directory): ?string
    {
        if (is_link($directory)) {
            return 'it is a link';
        }
        if (file_exists($directory) && !is_dir($directory)) {
            return 'it is not a directory';
        }
        if (!file_exists($directory)) {
            @mkdir($directory, 0700, true);
            @chmod($directory, 0700);
            if (!is_dir($directory)) {
                return 'it cannot be created';
            }
        }
        if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
            return 'it is owned by another user';
        }
        clearstatcache(true, $directory);
        $perms = fileperms($directory);
        if ($perms !== false && ($perms & 0077) !== 0) {
            @chmod($directory, 0700);
            clearstatcache(true, $directory);
            $perms = fileperms($directory);
            if ($perms === false || ($perms & 0077) !== 0) {
                return 'it can be used by others and cannot be made private';
            }
        }
        if (!is_writable($directory)) {
            return 'it is not writable';
        }
        if (!CacheDirResolver::usableAndPrivate($directory, requireOwner: true)) {
            return 'it is not private to this user';
        }

        return null;
    }
}
