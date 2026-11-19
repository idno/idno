<?php

declare(strict_types=1);

namespace Idno\Upgrade\Mongo;

/**
 * A read-only PHP stream over an iterable of strings (the chunks of a GridFS file, or the pieces of
 * another stream on their way to a hash): each read returns at most the rest of the current piece,
 * and the next piece is asked for only when the current one is used up, so one piece is in memory
 * at a time. An exception the iterable throws reaches the code reading the stream.
 *
 * Registered as the stream wrapper `idno-mongo-import` the first time open() is called.
 * A helper built by the import, not a container service.
 */
final class ChunkStream
{
    public const PROTOCOL = 'idno-mongo-import';

    /** @var resource|null set by PHP for a stream opened with a context */
    public $context;

    private ?\Iterator $pieces = null;

    private bool $started = false;

    private bool $ended = false;

    private string $piece = '';

    private int $offset = 0;

    /**
     * @param iterable<string> $pieces
     *
     * @return resource
     */
    public static function open(iterable $pieces)
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $iterator = $pieces instanceof \Iterator ? $pieces : (static fn (iterable $all): \Generator => yield from $all)($pieces);
        $stream = fopen(self::PROTOCOL . '://stream', 'rb', false, stream_context_create([self::PROTOCOL => ['pieces' => $iterator]]));
        if ($stream === false) {
            throw new \RuntimeException('The import could not open a stream.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $pieces = is_resource($this->context) ? (stream_context_get_options($this->context)[self::PROTOCOL]['pieces'] ?? null) : null;
        if (!$pieces instanceof \Iterator || !str_contains($mode, 'r')) {
            return false;
        }
        $this->pieces = $pieces;

        return true;
    }

    public function stream_read(int $count): string
    {
        while ($this->offset >= strlen($this->piece)) {
            if (!$this->next()) {
                return '';
            }
        }
        $read = substr($this->piece, $this->offset, $count);
        $this->offset += strlen($read);

        return $read;
    }

    public function stream_eof(): bool
    {
        return $this->ended && $this->offset >= strlen($this->piece);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        $this->pieces = null;
        $this->piece = '';
    }

    private function next(): bool
    {
        if ($this->ended || $this->pieces === null) {
            $this->ended = true;

            return false;
        }
        if ($this->started) {
            $this->pieces->next();
        } else {
            $this->pieces->rewind();
            $this->started = true;
        }
        if (!$this->pieces->valid()) {
            $this->ended = true;
            $this->piece = '';
            $this->offset = 0;

            return false;
        }
        $this->piece = (string) $this->pieces->current();
        $this->offset = 0;

        return true;
    }
}
