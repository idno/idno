<?php

declare(strict_types=1);

namespace Idno\Queue;

/**
 * What the application remembers between reads in one process (entities it has loaded). A worker runs
 * for hours, so it empties this after every message: an event must not see what an earlier one loaded
 * (Idno\Legacy\LegacyEntityCache is the adapter over the application's entity cache).
 */
interface EntityCache
{
    /**
     * Forgets every cached entity; the next read goes to the database.
     */
    public function clear(): void;
}
