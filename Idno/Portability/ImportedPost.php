<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * A post as an importer read it. A value object, not a service.
 */
final class ImportedPost
{
    public const ARTICLE = 'article';

    public const NOTE = 'note';

    /**
     * @param self::ARTICLE|self::NOTE $kind
     * @param list<string>             $fileIds the stored files its body shows, which belong to the post
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly int $created,
        public readonly bool $draft = false,
        public readonly bool $private = false,
        public readonly array $fileIds = [],
    )
    {
    }
}
