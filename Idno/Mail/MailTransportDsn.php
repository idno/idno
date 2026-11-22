<?php

declare(strict_types=1);

namespace Idno\Mail;

/**
 * The DSN of the SMTP transport (Idno\Mail\SmtpTransportFactory reads it), from the site's mail
 * settings. A pure function: it knows nothing of where the settings are stored. It keeps what the
 * settings meant when SwiftMailer read them:
 *
 * - the port is always in the DSN: smtp_port when it is a positive number, else 25 (465 with `ssl`);
 * - an empty host is the mail server on this machine (`localhost`), and port and security still apply;
 * - smtp_secure `ssl` is implicit TLS (`smtps://`), `tls` is STARTTLS that must succeed
 *   (`require_tls=true`), compared exactly; any other value sends in the clear (`auto_tls=false`: no
 *   STARTTLS, even if the server offers it);
 * - a host written `ssl://host` or `tls://host` (the old code passed the host to a stream URL) is the
 *   host with that security, unless smtp_secure says `ssl` or `tls` itself; any other scheme is refused;
 * - a password is used only with a username.
 *
 * A host or port that cannot be written in a DSN is refused with an InvalidMailSetting, whose message
 * names the setting and never its value, so it is safe to log and to show.
 */
final class MailTransportDsn
{
    /**
     * @param array<string, mixed> $settings smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure
     *
     * @throws InvalidMailSetting
     */
    public static function fromSettings(array $settings): string
    {
        [$host, $hostSecurity] = self::host($settings['smtp_host'] ?? '');

        $secure = $settings['smtp_secure'] ?? '';
        $security = ($secure === 'ssl' || $secure === 'tls') ? $secure : $hostSecurity;

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

        $port = self::port($settings['smtp_port'] ?? '') ?? ($security === 'ssl' ? 465 : 25);

        return ($security === 'ssl' ? 'smtps' : 'smtp') . '://' . $credentials . $host . ':' . $port
            . match ($security) {
                'ssl' => '',
                'tls' => '?require_tls=true',
                default => '?auto_tls=false',
            };
    }

    /**
     * @return array{string, ?string} the host, and the security its scheme asks for
     */
    private static function host(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return ['localhost', null];
        }
        if (!is_string($value)) {
            throw new InvalidMailSetting('smtp_host', 'is not a host name');
        }
        $host = trim($value);
        if ($host === '') {
            return ['localhost', null];
        }
        $security = null;
        if (preg_match('~\A([A-Za-z][A-Za-z0-9+.-]*)://(.*)\z~s', $host, $m) === 1) {
            $scheme = strtolower($m[1]);
            if ($scheme !== 'ssl' && $scheme !== 'tls') {
                throw new InvalidMailSetting('smtp_host', 'has a scheme other than ssl:// or tls://');
            }
            $security = $scheme;
            $host = $m[2];
        }
        $bare = (str_starts_with($host, '[') && str_ends_with($host, ']')) ? substr($host, 1, -1) : $host;
        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return ['[' . $bare . ']', $security];
        }
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $host) !== 1) {
            throw new InvalidMailSetting('smtp_host', 'is not a host name or an IP address');
        }

        return [$host, $security];
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
            throw new InvalidMailSetting('smtp_port', 'is not a port number');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidMailSetting('smtp_port', 'is not a port number');
        }

        return $port;
    }
}
