<?php

declare(strict_types=1);

namespace Idno\Mail;

/**
 * The DSN Symfony Mailer reads, from the site's mail settings. A pure function: it knows nothing of
 * where the settings are stored.
 *
 * smtp_secure: `ssl` is implicit TLS (smtps), `tls` is STARTTLS that must succeed (require_tls), and
 * anything else is Symfony's default, STARTTLS when the server offers it. A password is used only with
 * a username. A host or port that cannot be written in a DSN is refused with a message that names the
 * setting and never its value, so the message is safe to log.
 */
final class MailTransportDsn
{
    private const DEFAULT = 'smtp://localhost:25';

    /**
     * @param array<string, mixed> $settings smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure
     *
     * @throws \InvalidArgumentException
     */
    public static function fromSettings(array $settings): string
    {
        $host = self::host($settings['smtp_host'] ?? '');
        if ($host === '') {
            return self::DEFAULT;
        }

        $secure = $settings['smtp_secure'] ?? '';
        $scheme = $secure === 'ssl' ? 'smtps' : 'smtp';

        $credentials = '';
        $username = $settings['smtp_username'] ?? '';
        if (is_string($username) && $username !== '') {
            $credentials = rawurlencode($username);
            $password = $settings['smtp_password'] ?? '';
            if (is_string($password) && $password !== '') {
                $credentials .= ':' . rawurlencode($password);
            }
            $credentials .= '@';
        }

        $port = self::port($settings['smtp_port'] ?? '');

        return $scheme . '://' . $credentials . $host . ($port !== null ? ':' . $port : '')
            . ($secure === 'tls' ? '?require_tls=true' : '');
    }

    private static function host(mixed $value): string
    {
        if ($value === null || $value === '' || $value === false) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('The setting smtp_host is not a host name.');
        }
        $host = trim($value);
        if ($host === '') {
            return '';
        }
        $bare = (str_starts_with($host, '[') && str_ends_with($host, ']')) ? substr($host, 1, -1) : $host;
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $bare . ']';
        }
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $host) !== 1) {
            throw new \InvalidArgumentException('The setting smtp_host is not a host name or an IP address.');
        }

        return $host;
    }

    private static function port(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]{1,5}\z/', trim($value)) === 1) {
            $port = (int) trim($value);
        } else {
            throw new \InvalidArgumentException('The setting smtp_port is not a port number.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The setting smtp_port is not a port number.');
        }

        return $port;
    }
}
