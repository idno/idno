<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * The one rule for which settings are secrets: the admin diagnostics page and the export archive both
 * apply it, so what one hides the other hides. It works on any tree of values (the settings, the server's
 * variables):
 *  - a key that names a password, secret, token, key, credential, signature, salt, DSN, JWT, HMAC,
 *    authorization, authentication, cookie, session or user name for a service, or "private", whatever the
 *    spelling (`smtp_password`, `dbpass`, `IDNO_DBPASS`, `site_secret`, `aws_key`, `apikey`, `consumerKey`,
 *    `HTTP_COOKIE`), has its value replaced (or its key removed), at any depth;
 *  - a key that names a webhook keeps its entries, with each address reduced to its scheme and host (the path of
 *    a Slack or Discord webhook is its secret);
 *  - a value that is a payment provider's secret key (`sk_live_...`, `rk_test_...`) is removed whatever its key;
 *  - anywhere else, a string that is an address with a user name or password in it (`https://user:pass@host/`)
 *    has them taken out.
 *
 * It is a denylist of key names: a secret kept under a name that does not look like one is not found, so
 * whoever shares an archive reviews `config.json` first.
 *
 * A static helper, not a service.
 */
final class SettingsRedactor
{
    public const MARKER = '** REDACTED **';

    private const SECRET = '/pass(?:word|wd|phrase)|[A-Za-z0-9]pass$|secret|token|api_?key|(?:^|[_.\-])private(?:$|[_.\-])|credential|signature|jwt|hmac|authoriz|bearer|oauth|indieauth|cookie|salt|dsn$|^dbuser$|(?:^|[_.\-])(?:pass|pw|key|keys|auth|sig|username)(?:$|[_.\-])|(?-i:[A-Za-z0-9](?:Key|Pass|Auth|Salt)$)/i';

    private const WEBHOOK = '/webhook/i';

    /** A key that names how long something lasts: with a number as its value it is no secret (`form_token_expiry`). */
    /** An OAuth client's identifier (`oauth_client_id`) is public: it names the application, it does not sign in. */
    private const PUBLIC_IDENTIFIER = '/client[_\-]?id$/i';

    /** A value that is a payment provider's secret key, whatever key it is stored under (`sk_live_...`, `rk_test_...`). */
    private const SECRET_VALUE = '/^(?:sk|rk)_(?:live|test)_/';

    private const DURATION = '/(?:expiry|expires?|lifetime|ttl|timeout|duration)$/i';

    /**
     * @param mixed $value the key's value, when known: a number under a key that names a duration is not a secret
     */
    public static function isSecretKey(string $key, mixed $value = null): bool
    {
        if (preg_match(self::SECRET, $key) !== 1 || preg_match(self::PUBLIC_IDENTIFIER, $key) === 1) {
            return false;
        }

        return !(is_numeric($value) && preg_match(self::DURATION, $key) === 1);
    }

    /**
     * The tree with the value of every secret key replaced by $replacement, or the key removed when
     * $replacement is null.
     *
     * @param array<array-key, mixed> $settings
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $settings, ?string $replacement = self::MARKER): array
    {
        $result = [];
        foreach ($settings as $key => $value) {
            if ((is_string($key) && self::isSecretKey($key, $value)) || (is_string($value) && preg_match(self::SECRET_VALUE, $value) === 1)) {
                if ($replacement !== null) {
                    $result[$key] = $replacement;
                }

                continue;
            }
            if (is_string($key) && preg_match(self::WEBHOOK, $key) === 1) {
                $result[$key] = self::reduceAddresses($value);

                continue;
            }
            $result[$key] = is_array($value) ? self::redact($value, $replacement) : (is_string($value) ? self::withoutUserInfo($value) : $value);
        }

        return $result;
    }

    /** An address without the user name and password in it; anything else as it is. */
    public static function withoutUserInfo(string $value): string
    {
        if (!str_contains($value, '@') || !str_contains($value, '://')) {
            return $value;
        }

        return (string) preg_replace('~(?<=://)[^/?#@\s]*@~', '', $value);
    }

    /**
     * Every address in the value reduced to its scheme and host (the rest of the entries kept).
     */
    private static function reduceAddresses(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $entry): mixed => self::reduceAddresses($entry), $value);
        }
        if (is_string($value) && preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value) === 1) {
            $parts = parse_url($value);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return $parts['scheme'] . '://' . $parts['host'];
            }

            return '';
        }

        return $value;
    }
}
