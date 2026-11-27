<?php

declare(strict_types=1);

namespace Idno\Mail;

/**
 * Works out why a mail was not sent, from what the mailer threw. Pure: it reads an exception and nothing of the site.
 *
 * An exception of a transport can quote the user name (a failed login does) and the server's own reply, so nothing here
 * hands the exception's text on as it is: classify() answers a KIND and a VARIANT (fixed vocabularies, which the mail
 * adapter turns into one sentence of its own), and reply() is the server's own words with the credentials taken out and
 * cut short, for an administrator to read beside that sentence.
 */
final class MailFailure
{
    /** The longest reply of the mail server that is kept */
    public const REPLY_LENGTH = 300;

    /**
     * @return array{kind: string, variant: string, code: int}
     *   kind: auth, tls, connect, sender, rejected, temporary or unknown;
     *   variant: for tls 'certificate', 'starttls' or 'handshake'; for connect 'unknown-host', 'refused' or 'no-answer'; else ''
     */
    public static function classify(\Throwable $e, string $security): array
    {
        $raw = $e->getMessage();
        $code = self::code($e, $raw);
        $reply = self::rawReply($raw);

        if ($code === 530 && stripos($reply, 'STARTTLS') !== false) {
            // "Must issue a STARTTLS command first": the login was never looked at
            return ['kind' => 'tls', 'variant' => 'starttls', 'code' => $code];
        }
        if (stripos($raw, 'Failed to authenticate') !== false || in_array($code, [530, 534, 535], true)) {
            return ['kind' => 'auth', 'variant' => '', 'code' => $code];
        }
        if (preg_match('/certificate|verify failed/i', $raw)) {
            return ['kind' => 'tls', 'variant' => 'certificate', 'code' => $code];
        }
        if (stripos($raw, 'STARTTLS') !== false || stripos($raw, 'TLS required') !== false) {
            return ['kind' => 'tls', 'variant' => 'starttls', 'code' => $code];
        }
        if (preg_match('/SSL operation failed|wrong version number|Unable to connect with TLS|crypto/i', $raw)) {
            return ['kind' => 'tls', 'variant' => $security === 'ssl' ? 'handshake' : 'starttls', 'code' => $code];
        }
        if (preg_match('/getaddrinfo|Name or service not known|nodename nor servname|No such host/i', $raw)) {
            return ['kind' => 'connect', 'variant' => 'unknown-host', 'code' => $code];
        }
        if (stripos($raw, 'Connection refused') !== false) {
            return ['kind' => 'connect', 'variant' => 'refused', 'code' => $code];
        }
        if (stripos($raw, 'Connection could not be established') !== false || preg_match('/timed out|closed unexpectedly/i', $raw)) {
            return ['kind' => 'connect', 'variant' => 'no-answer', 'code' => $code];
        }
        if ($code >= 500) {
            if (preg_match('/sender|from address|from header|not verified|not authori[sz]ed|identity|domain/i', $reply)) {
                return ['kind' => 'sender', 'variant' => '', 'code' => $code];
            }

            return ['kind' => 'rejected', 'variant' => '', 'code' => $code];
        }
        if ($code >= 400) {
            return ['kind' => 'temporary', 'variant' => '', 'code' => $code];
        }

        return ['kind' => 'unknown', 'variant' => '', 'code' => $code];
    }

    /**
     * What the mail server said, when it said something: its own words with the user name and password (in every form they
     * travel in) taken out, on one line and cut at REPLY_LENGTH characters. Empty when the exception holds no reply.
     */
    public static function reply(\Throwable $e, string $user, string $password): string
    {
        $reply = self::scrub(self::rawReply($e->getMessage()), $user, $password);
        // Direction controls and isolates would reorder the words the administrator reads around the reply
        $reply = preg_replace('/[\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $reply) ?? '';
        $reply = trim(preg_replace('/\s+/u', ' ', $reply) ?? '');
        if (mb_strlen($reply) > self::REPLY_LENGTH) {
            $reply = mb_substr($reply, 0, self::REPLY_LENGTH) . '...';
        }

        return $reply;
    }

    /**
     * Takes the user name and the password out of a piece of text, in every form they travel in: as they are, in base64 and
     * in the blob of an AUTH PLAIN. The longest first, so a secret that holds another is taken out whole.
     */
    public static function scrub(string $text, string $user, string $password): string
    {
        $secrets = [];
        foreach ([$password, $user] as $secret) {
            if ($secret !== '') {
                $secrets[] = $secret;
                $secrets[] = base64_encode($secret);
            }
        }
        if ($user !== '' || $password !== '') {
            $secrets[] = base64_encode("\0" . $user . "\0" . $password);
        }
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = str_replace($secrets, '[hidden]', $text);

        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '');
    }

    /** The three-digit reply code of the mail server: the exception's own code when it is one, else the one its message quotes */
    private static function code(\Throwable $e, string $raw): int
    {
        if (preg_match('/got code "(\d{3})"/', $raw, $match)) {
            return (int) $match[1];
        }

        return $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 0;
    }

    private static function rawReply(string $raw): string
    {
        return preg_match('/got code "\d{3}", with message "(.*?)"/s', $raw, $match) ? $match[1] : '';
    }
}
