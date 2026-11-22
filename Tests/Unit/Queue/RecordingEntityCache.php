<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Idno\Queue\EntityCache;

/**
 * An EntityCache that counts how often it was emptied (and can be made to fail).
 */
class RecordingEntityCache implements EntityCache
{
    public int $cleared = 0;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function clear(): void
    {
        ++$this->cleared;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
