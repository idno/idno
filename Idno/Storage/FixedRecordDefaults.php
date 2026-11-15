<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * RecordDefaults with fixed values, for tests: a given site URL and site id, and ids that are
 * UUID-shaped strings counted up from 1 (or whatever the given generator returns).
 *
 * A value/helper class, not a container service (see config/services.php).
 */
final class FixedRecordDefaults implements RecordDefaults
{
    private int $counter = 0;

    /**
     * @param (\Closure(): string)|null $idGenerator replaces the counter when given
     */
    public function __construct(
        private readonly string $siteUrl = 'http://example.test/',
        private readonly ?string $siteId = 'fixed-site',
        private readonly ?\Closure $idGenerator = null,
    )
    {
    }

    public function generateId(): string
    {
        if ($this->idGenerator !== null) {
            return ($this->idGenerator)();
        }

        return sprintf('00000000-0000-4000-8000-%012d', ++$this->counter);
    }

    public function siteUrl(): string
    {
        return $this->siteUrl;
    }

    public function currentSiteId(): ?string
    {
        return $this->siteId;
    }
}
