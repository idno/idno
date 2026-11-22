<?php

declare(strict_types=1);

namespace Tests\Portability;

/**
 * A stream wrapper that stands in for PHP's own http, https, ftp and ftps wrappers while a test runs, so
 * that code which opens a URL with fopen() or file_get_contents() reaches no network: each URL opened is
 * recorded and the bytes served are the ones the test chose. Migration::importImagesFromBodyHTML() fetches
 * images that way, outside the site's HTTP client.
 *
 * install() before the code under test, restore() after it (in tearDown()).
 */
final class RecordingStreamWrapper
{
    private const SCHEMES = ['http', 'https', 'ftp', 'ftps'];

    /** The smallest valid PNG: one transparent pixel. */
    public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    /** @var list<string> the URLs opened, in order */
    public static array $opened = [];

    /** @var int|null when set, serve this many bytes of the letter A instead of the PNG */
    public static ?int $size = null;

    /** @var resource|null */
    public $context;

    private int $position = 0;

    private int $length = 0;

    private string $body = '';

    public static function install(): void
    {
        self::$opened = [];
        self::$size = null;
        foreach (self::SCHEMES as $scheme) {
            if (in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($scheme);
            }
            stream_wrapper_register($scheme, self::class);
        }
    }

    public static function restore(): void
    {
        foreach (self::SCHEMES as $scheme) {
            if (in_array($scheme, stream_get_wrappers(), true)) {
                @stream_wrapper_unregister($scheme);
            }
            @stream_wrapper_restore($scheme);
        }
        self::$opened = [];
        self::$size = null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened[] = $path;
        if (self::$size !== null) {
            $this->length = self::$size;
        } else {
            $this->body = (string) base64_decode(self::PNG_BASE64, true);
            $this->length = strlen($this->body);
        }

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $count = min($count, $this->length - $this->position);
        if ($count <= 0) {
            return '';
        }
        $chunk = self::$size !== null ? str_repeat('A', $count) : substr($this->body, $this->position, $count);
        $this->position += $count;

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->length;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->length];
    }

    public function stream_close(): void
    {
    }
}
