<?php

declare(strict_types=1);

namespace Idno\Storage\Files;

/**
 * A stored file's identity, metadata and size. The bytes are read from the store as a stream.
 */
final class StoredFile
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly array $metadata,
        public readonly int $size,
    )
    {
    }
}
