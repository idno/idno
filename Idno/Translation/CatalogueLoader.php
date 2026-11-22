<?php

declare(strict_types=1);

namespace Idno\Translation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\Loader\MoFileLoader;
use Symfony\Component\Translation\Loader\PoFileLoader;

/**
 * Reads a message catalogue (a gettext `.po` file, else its compiled `.mo`) in the layout the gettext
 * tools use, `<directory>/<locale>/LC_MESSAGES/<domain>.po`, into an array of source string to translation.
 * No extension is needed: the files are read by symfony/translation's `PoFileLoader` and `MoFileLoader`.
 *
 * Which locale directory is read, in order: the exact locale (`fr_CA`), the bare language (`fr`), then the
 * first directory of that catalogue named `<language>_*` in sorted order (`fr_FR`), so a visitor asking for
 * `fr` or `fr_CA` gets `fr_FR` when that is all there is. (gettext itself would not.) The directory names
 * come from the catalogue's own directory and each must be a locale identifier; the listing is made once
 * per catalogue directory and language (not per domain) for the process.
 *
 * Plural entries are NOT supported (Idno has no plural lookup). Symfony's loaders store a plural entry's forms
 * joined with `|` under `singular|plural`, and nothing here splits them: a `.po` file that holds a `msgid_plural`
 * line gets ONE warning per file per process saying so (found with a cheap match on the text, not a parse; a `.mo`
 * is not scanned). A bar in an ordinary string is untouched.
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
            $isPo = str_ends_with($file, '.po');
            if ($isPo && preg_match('/^msgid_plural\s/m', (string) file_get_contents($file)) === 1) {
                $this->logger->warning('The translation file ' . $this->describe($file) . ' has plural entries, which are not supported: they are shown with their forms joined by a bar');
            }
            if (!$isPo && !self::moHeaderIsSane($file, $size)) {
                throw new \UnexpectedValueException('bad header');
            }
            $loader = $isPo ? new PoFileLoader() : new MoFileLoader();
            $messages = $loader->load($file, 'en', $domain)->all($domain);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read the translation file ' . $this->describe($file) . ': ' . get_class($e));

            return [];
        }
        $strings = [];
        foreach ($messages as $source => $translation) {
            if (is_string($translation) && $translation !== '' && $source !== '') {
                $strings[(string) $source] = $translation;
            }
        }

        return $strings;
    }

    /**
     * Symfony's `.mo` loader trusts the header: a count or an offset that points outside the file makes it
     * allocate memory by a length it read from the file, which is a fatal error, not an exception. So the header
     * (magic, entry count, the two table offsets) is checked against the file's size first. Nothing else is parsed here.
     */
    private static function moHeaderIsSane(string $file, int $size): bool
    {
        $head = @file_get_contents($file, false, null, 0, 20);
        if ($head === false || strlen($head) < 20) {
            return false;
        }
        $magic = unpack('V', $head)[1];
        $format = $magic === 0x950412de ? 'V' : ($magic === 0xde120495 ? 'N' : null);
        if ($format === null) {
            return false;
        }
        [, , $count, $ids, $translations] = array_values(unpack($format . '5', $head));

        return $count <= intdiv($size, 16) && $ids + 8 * $count <= $size && $translations + 8 * $count <= $size;
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
