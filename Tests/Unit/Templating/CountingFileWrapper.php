<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

/**
 * Stands in for PHP's `file://` stream wrapper for a short stretch of a test and records the path of every stat() of a file
 * (every stat-like call that reaches the wrapper: PHP's own stat cache is not used for a user wrapper), so that a test can count
 * how often one file was looked at. Everything else is passed to the real wrapper. Always call stop() in a `finally`.
 */
final class CountingFileWrapper
{
    /** @var list<string> */
    public static array $stats = [];

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    public static function start(): void
    {
        self::$stats = [];
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function stop(): void
    {
        @stream_wrapper_unregister('file');
        stream_wrapper_restore('file');
    }

    /**
     * How many times the file was stat'ed since start().
     */
    public static function statsOf(string $path): int
    {
        return count(array_filter(self::$stats, static fn (string $seen): bool => $seen === $path));
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$stats[] = $path;
        stream_wrapper_restore('file');
        try {
            $result = ($flags & STREAM_URL_STAT_LINK) !== 0 ? @lstat($path) : @stat($path);
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }

        return $result;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        stream_wrapper_restore('file');
        try {
            $handle = @fopen($path, $mode);
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
        $this->handle = $handle === false ? null : $handle;

        return $this->handle !== null;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }
}
