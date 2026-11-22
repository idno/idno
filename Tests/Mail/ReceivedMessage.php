<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * A message as an SMTP server received it (CRLF, dots unstuffed), read just far enough for the tests:
 * its headers in order (names as sent, values unfolded and with encoded words decoded), and, for a
 * multipart message, its parts with their content type and decoded body.
 */
final class ReceivedMessage
{
    /** @var list<array{string, string}> */
    public array $headers = [];

    /** @var list<array{type: string, body: string}> */
    public array $parts = [];

    public string $body = '';

    public static function parse(string $raw): self
    {
        $message = new self();
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $message->headers = self::headers($head);
        $message->body = $body;
        $type = $message->header('Content-Type') ?? '';
        if (preg_match('/boundary="?([^";\s]+)"?/i', $type, $m) === 1) {
            foreach (explode('--' . $m[1], $body) as $section) {
                $section = ltrim($section, "\r\n");
                if ($section === '' || str_starts_with($section, '--')) {
                    continue;
                }
                [$partHead, $partBody] = array_pad(explode("\r\n\r\n", $section, 2), 2, '');
                $partHeaders = self::headers($partHead);
                $partType = '';
                $encoding = '';
                foreach ($partHeaders as [$name, $value]) {
                    if (strcasecmp($name, 'Content-Type') === 0) {
                        $partType = strtolower(trim(explode(';', $value)[0]));
                    }
                    if (strcasecmp($name, 'Content-Transfer-Encoding') === 0) {
                        $encoding = strtolower(trim($value));
                    }
                }
                $partBody = rtrim($partBody, "\r\n");
                $partBody = match ($encoding) {
                    'quoted-printable' => quoted_printable_decode($partBody),
                    'base64' => (string) base64_decode($partBody),
                    default => $partBody,
                };
                $message->parts[] = ['type' => $partType, 'body' => $partBody];
            }
        }

        return $message;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$headerName, $value]) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string> the header names as sent, lower-cased
     */
    public function headerNames(): array
    {
        return array_map(static fn (array $h): string => strtolower($h[0]), $this->headers);
    }

    public function part(string $type): ?string
    {
        foreach ($this->parts as $part) {
            if ($part['type'] === $type) {
                return $part['body'];
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function headers(string $head): array
    {
        $headers = [];
        $unfolded = preg_replace("/\r\n[ \t]+/", ' ', $head) ?? $head;
        foreach (explode("\r\n", $unfolded) as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[] = [$name, (string) iconv_mime_decode(trim($value), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')];
        }

        return $headers;
    }
}
