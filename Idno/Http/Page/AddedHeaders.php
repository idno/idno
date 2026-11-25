<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Templating\ShellHeaders;

/**
 * What a draw added to the headers PHP holds (`headers_list()` before and after): the headers a template or a plugin's
 * extension sent with `header()` while a page was drawn, and what to do with PHP's list so that each is sent once, by the
 * response. Pure; the one place that reads and changes PHP's list is Idno\Legacy\LegacyPageDrawer. A cookie is never taken (PHP
 * adds the session's).
 */
final class AddedHeaders
{
    private const NEVER = ['set-cookie'];

    /**
     * @param list<string> $before the lines of `headers_list()` before the draw
     * @param list<string> $after  the lines after it
     *
     * @return array{added: list<array{0: string, 1: string, 2: bool}>, remove: list<string>, keep: list<string>}
     *         `added`: [name, value, replace] in the order sent: the first line of a name replaces what is there unless it was
     *         sent beside lines PHP already held (or, with none held, unless the name is one that is added, `Link`), and every
     *         further line of it is added; `remove`: the names to remove from PHP's list; `keep`: the lines PHP held of those
     *         names, which stay (to be put back after the removal)
     */
    public static function between(array $before, array $after): array
    {
        $unmatched = array_count_values($before);
        $new = [];
        $present = [];
        foreach ($after as $line) {
            [$name, $value] = self::split($line);
            if ($name === null || in_array(strtolower($name), self::NEVER, true)) {
                continue;
            }
            if (($unmatched[$line] ?? 0) > 0) {
                --$unmatched[$line];
                $present[strtolower($name)][] = $line;
                continue;
            }
            $new[] = [$name, (string) $value];
        }

        $heldBefore = [];
        foreach ($before as $line) {
            [$name] = self::split($line);
            if ($name !== null) {
                $heldBefore[strtolower($name)] = true;
            }
        }

        $added = [];
        $remove = [];
        $keep = [];
        $first = [];
        foreach ($new as [$name, $value]) {
            $key = strtolower($name);
            if (!isset($first[$key])) {
                $first[$key] = true;
                $kept = $present[$key] ?? [];
                // beside lines PHP still holds: added; replacing lines it held (they are gone): replacing; with none held, as the core adds
                $replace = $kept !== [] ? false : (isset($heldBefore[$key]) ? true : ShellHeaders::replaces($name));
                $remove[] = $name;
                foreach ($kept as $line) {
                    $keep[] = $line;
                }
            } else {
                $replace = false;
            }
            $added[] = [$name, $value, $replace];
        }

        return ['added' => $added, 'remove' => $remove, 'keep' => $keep];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function split(string $line): array
    {
        $position = strpos($line, ':');
        if ($position === false || $position === 0) {
            return [null, null];
        }

        return [trim(substr($line, 0, $position)), trim(substr($line, $position + 1))];
    }
}
