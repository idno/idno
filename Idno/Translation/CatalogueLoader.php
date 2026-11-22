<?php

declare(strict_types=1);

namespace Idno\Translation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads a message catalogue (a gettext `.po` file, else its compiled `.mo`) in the layout the gettext
 * tools use, `<directory>/<locale>/LC_MESSAGES/<domain>.po`, into an array of source string to translation.
 * No extension is needed: the two formats are read by the small readers below.
 *
 * Which locale directory is read, in order: the exact locale (`fr_CA`), the bare language (`fr`), then the
 * first directory of that catalogue named `<language>_*` in sorted order (`fr_FR`), so a visitor asking for
 * `fr` or `fr_CA` gets `fr_FR` when that is all there is. (gettext itself would not.) The directory names
 * come from the catalogue's own directory and each must be a locale identifier; the listing is made once
 * per directory, domain and language for the process.
 *
 * Plural forms are ignored: an entry with a plural keeps its singular translation (`msgstr[0]`) under the
 * singular source string and nothing else. Both formats are read here, not with symfony/translation's
 * loaders, because those join the plural forms into one `singular|plural` key and value and cannot be
 * told from an ordinary string that contains a bar.
 *
 * A catalogue is read the first time it is asked for and kept for the life of the process (keyed by the
 * file, its size and its modification time). A file is trusted input from a plugin's own directory, but a
 * file that cannot be read never throws: it is an empty catalogue and one warning that names the file.
 * The locale and the domain are validated before they build a path.
 */
final class CatalogueLoader
{
    /** @var array<string, array<string, string>> */
    private static array $loaded = [];

    /** @var array<string, list<string>> the locale directories `<language>_*` of a catalogue, sorted */
    private static array $regions = [];

    /** A catalogue file larger than this is not a catalogue. */
    private const MAX_BYTES = 16777216;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Forget every catalogue read so far (for tests).
     */
    public static function forget(): void
    {
        self::$loaded = [];
        self::$regions = [];
    }

    /**
     * Whether a catalogue file exists for the locale (or for its language, `fr` for `fr_CA`). Reads nothing.
     */
    public function has(string $directory, string $domain, string $locale): bool
    {
        return $this->locate($directory, $domain, $locale) !== null;
    }

    /**
     * @return array<string, string> source string => translation; empty translations are left out
     */
    public function load(string $directory, string $domain, string $locale): array
    {
        $file = $this->locate($directory, $domain, $locale);
        if ($file === null) {
            return [];
        }
        $stat = @stat($file);
        $key = $file . '|' . ($stat['size'] ?? 0) . '|' . ($stat['mtime'] ?? 0);
        if (isset(self::$loaded[$key])) {
            return self::$loaded[$key];
        }

        return self::$loaded[$key] = $this->read($file, $domain);
    }

    /**
     * @return array<string, string>
     */
    private function read(string $file, string $domain): array
    {
        try {
            $size = filesize($file);
            if ($size === false || $size > self::MAX_BYTES) {
                throw new \UnexpectedValueException('not a catalogue');
            }
            $content = file_get_contents($file);
            if ($content === false) {
                throw new \UnexpectedValueException('unreadable');
            }

            return str_ends_with($file, '.po') ? self::parsePo($content) : self::parseMo($content);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read the translation file ' . $this->describe($file) . ': ' . get_class($e));

            return [];
        }
    }

    /**
     * @return array<string, string> source => translation, without the header, fuzzy entries and empty translations
     */
    private static function parsePo(string $content): array
    {
        $strings = [];
        $entry = null;
        $fuzzy = false;
        $target = null;
        $flush = static function () use (&$strings, &$entry, &$fuzzy, &$target): void {
            if ($entry !== null && !$fuzzy && $entry['id'] !== null) {
                $id = stripcslashes($entry['id']);
                $translation = stripcslashes($entry['str'][0] ?? '');
                if ($id !== '' && $translation !== '') {
                    $strings[$id] = $translation;
                }
            }
            $entry = null;
            $fuzzy = false;
            $target = null;
        };
        $quoted = static fn (string $text): string => strlen($text) >= 2 && $text[0] === '"' && str_ends_with($text, '"') ? substr($text, 1, -1) : '';

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line, " \t\xEF\xBB\xBF");
            if ($line === '') {
                $flush();
            } elseif (str_starts_with($line, '#~')) {
                continue; // an obsolete entry
            } elseif ($line[0] === '#') {
                if ($entry !== null && $entry['str'] !== []) {
                    $flush(); // a comment after a translation starts the next entry
                }
                if (str_starts_with($line, '#,') && in_array('fuzzy', array_map('trim', explode(',', substr($line, 2))), true)) {
                    $fuzzy = true;
                }
            } elseif (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(".*)$/s', $line, $m) === 1) {
                $keyword = $m[1];
                if (($keyword === 'msgid' || $keyword === 'msgctxt') && $entry !== null && $entry['id'] !== null) {
                    $flush(); // the next entry begins
                }
                $entry ??= ['id' => null, 'str' => []];
                $text = $quoted($m[3]);
                if ($keyword === 'msgid') {
                    $entry['id'] = $text;
                    $target = 'id';
                } elseif ($keyword === 'msgstr') {
                    $index = (int) $m[2];
                    $entry['str'][$index] = $text;
                    $target = $index;
                } else {
                    $target = null; // msgctxt and msgid_plural: read past, never kept
                }
            } elseif ($line[0] === '"' && $entry !== null && $target !== null) {
                $text = $quoted($line);
                if ($target === 'id') {
                    $entry['id'] .= $text;
                } else {
                    $entry['str'][$target] .= $text;
                }
            }
        }
        $flush();

        return $strings;
    }

    /**
     * @return array<string, string> source => translation: the first form of a plural, empty ones left out
     */
    private static function parseMo(string $content): array
    {
        if (strlen($content) < 28) {
            throw new \UnexpectedValueException('too short');
        }
        $magic = unpack('V', substr($content, 0, 4))[1];
        if ($magic === 0x950412de) {
            $format = 'V';
        } elseif ($magic === 0xde120495) {
            $format = 'N';
        } else {
            throw new \UnexpectedValueException('bad magic');
        }
        [, $revision, $count, $idTable, $translationTable] = array_values(unpack($format . '5', substr($content, 0, 20)));
        if (($revision >> 16) !== 0 || $count < 0 || $count > 1000000 || $idTable + 8 * $count > strlen($content) || $translationTable + 8 * $count > strlen($content)) {
            throw new \UnexpectedValueException('bad header');
        }
        $string = static function (int $table, int $i) use ($content, $format): string {
            [$length, $offset] = array_values(unpack($format . '2', substr($content, $table + 8 * $i, 8)));
            if ($offset + $length > strlen($content)) {
                throw new \UnexpectedValueException('bad offset');
            }

            return substr($content, $offset, $length);
        };
        $strings = [];
        for ($i = 0; $i < $count; $i++) {
            $id = explode("\0", $string($idTable, $i), 2)[0];
            if (($context = strpos($id, "\4")) !== false) {
                $id = substr($id, $context + 1); // msgctxt and the id are joined by \4
            }
            $translation = explode("\0", $string($translationTable, $i), 2)[0];
            if ($id !== '' && $translation !== '') {
                $strings[$id] = $translation;
            }
        }

        return $strings;
    }

    /**
     * The file for the locale: the exact locale, then its language, then the first `<language>_*` directory
     * of the catalogue (sorted) that has the domain; in each, a `.po` before a `.mo`.
     */
    private function locate(string $directory, string $domain, string $locale): ?string
    {
        if (preg_match(LocaleNegotiator::PATTERN, $locale) !== 1 || strlen($locale) > 35 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $domain) !== 1 || $directory === '') {
            return null;
        }
        $base = rtrim($directory, '/');
        $language = explode('_', $locale, 2)[0];
        $candidates = array_values(array_unique([$locale, $language]));
        $found = $this->inLocaleDirectory($base, $domain, $candidates);
        if ($found !== null) {
            return $found;
        }

        return $this->inLocaleDirectory($base, $domain, $this->regions($base, $language));
    }

    /**
     * @param list<string> $locales
     */
    private function inLocaleDirectory(string $base, string $domain, array $locales): ?string
    {
        foreach ($locales as $locale) {
            foreach (['po', 'mo'] as $extension) {
                $file = $base . '/' . $locale . '/LC_MESSAGES/' . $domain . '.' . $extension;
                if (is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * The directories of the catalogue named `<language>_*` that are locale identifiers, sorted. Listed once
     * per directory and language for the process.
     *
     * @return list<string>
     */
    private function regions(string $base, string $language): array
    {
        $key = $base . '|' . $language;
        if (isset(self::$regions[$key])) {
            return self::$regions[$key];
        }
        $names = [];
        foreach (@scandir($base) ?: [] as $name) {
            if (str_starts_with($name, $language . '_') && strlen($name) <= 35 && preg_match(LocaleNegotiator::PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);

        return self::$regions[$key] = $names;
    }

    /** The file as a message may name it: the domain and locale directory, never the server's path. */
    private function describe(string $file): string
    {
        $parts = explode('/', $file);

        return implode('/', array_slice($parts, -3));
    }
}
