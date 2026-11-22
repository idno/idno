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
 * No extension is needed: the files are parsed by symfony/translation's loaders.
 *
 * A catalogue is read the first time it is asked for and kept for the life of the process (keyed by the
 * file, its size and its modification time). A file is trusted input from a plugin's own directory, but a
 * file that cannot be parsed never throws: it is an empty catalogue and one warning that names the file.
 * The locale and the domain are validated before they build a path.
 */
final class CatalogueLoader
{
    /** @var array<string, array<string, string>> */
    private static array $loaded = [];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Forget every catalogue read so far (for tests).
     */
    public static function forget(): void
    {
        self::$loaded = [];
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
            $loader = str_ends_with($file, '.po') ? new PoFileLoader() : new MoFileLoader();
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
     * The file for the locale: the exact locale first, then its language; a `.po` before a `.mo`.
     */
    private function locate(string $directory, string $domain, string $locale): ?string
    {
        if (preg_match(LocaleNegotiator::PATTERN, $locale) !== 1 || strlen($locale) > 35 || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $domain) !== 1 || $directory === '') {
            return null;
        }
        $candidates = [$locale];
        $language = explode('_', $locale, 2)[0];
        if ($language !== $locale) {
            $candidates[] = $language;
        }
        foreach ($candidates as $candidate) {
            foreach (['po', 'mo'] as $extension) {
                $file = rtrim($directory, '/') . '/' . $candidate . '/LC_MESSAGES/' . $domain . '.' . $extension;
                if (is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /** The file as a message may name it: the domain and locale directory, never the server's path. */
    private function describe(string $file): string
    {
        $parts = explode('/', $file);

        return implode('/', array_slice($parts, -3));
    }
}
