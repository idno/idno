<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

/**
 * A webmention the endpoint has accepted for a target page: where it came from, what it is aimed at, the fetched source (`response`, `content`) and the
 * source's parsed microformats. A value object, not a service.
 */
final class ReceivedWebmention
{
    /**
     * @param array<mixed> $sourceResponse the fetched source, as `Webservice` answers (`response`, `content`, ...)
     * @param array<mixed> $sourceMf2      the source's microformats
     */
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly array $sourceResponse,
        public readonly array $sourceMf2,
    )
    {
    }
}
