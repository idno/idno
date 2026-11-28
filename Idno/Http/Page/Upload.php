<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * A file field of a request, as `PageContext::file()` reads it. Only a file PHP itself received through HTTP is `received()`.
 */
final class Upload
{
    /**
     * @param string $path the temporary file PHP made ('' unless received); the page moves or reads it, and PHP removes it when the request ends
     * @param string $name the client's name for the file reduced to a base name with control characters removed. It is a label only, never a
     *                     path: a page that stores a file under a name checks that name itself.
     * @param int $size the size of the file on disk (never the client's claim)
     */
    public function __construct(
        public readonly UploadState $state,
        public readonly string $path = '',
        public readonly string $name = '',
        public readonly int $size = 0,
    )
    {
    }

    public static function missing(): self
    {
        return new self(UploadState::Missing);
    }

    public function received(): bool
    {
        return $this->state === UploadState::Received;
    }
}
