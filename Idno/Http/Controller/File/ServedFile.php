<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * A stored file a viewer may be sent: what the page needs to answer for it (its size, type and last change, which caching it is given) and how to read it.
 * A value object: not a service.
 */
final class ServedFile
{
    /**
     * @param int                 $size        The size in bytes
     * @param string              $mimeType    The stored type, with any line break cut
     * @param int                 $lastChange  When it last changed, as a timestamp
     * @param \Closure(): mixed   $open        A stream of its bytes, or false when it cannot be read
     * @param (\Closure(): mixed)|null $passThrough Writes the whole file to the output (ending the output buffers first), or null when the file cannot
     */
    public function __construct(
        public readonly int $size,
        public readonly string $mimeType,
        public readonly int $lastChange,
        public readonly FileCaching $caching,
        private readonly \Closure $open,
        private readonly ?\Closure $passThrough,
    )
    {
    }

    /**
     * A stream of the file's bytes, or false.
     *
     * @return resource|false
     */
    public function open(): mixed
    {
        return ($this->open)();
    }

    /**
     * Write the whole file to the output. False, and nothing written, when the file cannot do it itself.
     */
    public function passThrough(): bool
    {
        if ($this->passThrough === null) {
            return false;
        }
        ($this->passThrough)();

        return true;
    }
}
