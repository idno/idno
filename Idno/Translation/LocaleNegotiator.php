<?php

declare(strict_types=1);

namespace Idno\Translation;

/**
 * Decides the locale a request or a console run asks for. The value comes from a request header or the
 * environment, and is later used to build a file path, so it is accepted only in the form of a locale
 * identifier (`fr`, `fr_FR`, `zh_Hant_TW`) and is otherwise the default. Pure: nothing is read from a
 * superglobal here.
 */
final class LocaleNegotiator
{
    /** What a usable locale looks like. */
    public const PATTERN = '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/';

    /** No real locale is longer; a value over it is not one. */
    private const MAX_LENGTH = 35;

    /**
     * The locale of the first language of an Accept-Language header (`fr-FR,fr;q=0.9,en;q=0.8` is `fr_FR`).
     */
    public static function fromAcceptLanguage(?string $header, string $default = 'en_US'): string
    {
        if ($header === null || strlen($header) > 1000) {
            return $default;
        }
        $first = explode(',', $header, 2)[0];

        return self::normalise($first, $default);
    }

    /**
     * The locale of a console run: LANGUAGE (its first entry), then LANG, without an encoding or a modifier
     * (`fr_FR.UTF-8` is `fr_FR`). `C` and `POSIX` are no locale.
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env, string $default): string
    {
        foreach (['LANGUAGE', 'LANG'] as $name) {
            $value = $env[$name] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $first = explode(':', $value, 2)[0];
            $first = preg_replace('/[.@].*$/s', '', $first) ?? '';
            $locale = self::normalise($first, '');
            if ($locale !== '') {
                return $locale;
            }
        }

        return $default;
    }

    /**
     * One locale value in the form a catalogue directory has: any `;q=` removed, `-` as `_`, the language
     * lower-cased; $default when it is not a locale.
     */
    public static function normalise(string $value, string $default): string
    {
        $value = trim(explode(';', $value, 2)[0]);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return $default;
        }
        $value = str_replace('-', '_', $value);
        $parts = explode('_', $value, 2);
        $value = strtolower($parts[0]) . (isset($parts[1]) ? '_' . $parts[1] : '');

        return preg_match(self::PATTERN, $value) === 1 ? $value : $default;
    }
}
