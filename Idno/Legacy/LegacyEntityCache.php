<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Queue\EntityCache;

/**
 * The queue's EntityCache over the legacy entity cache (Entity::clearEntityCache()).
 */
final class LegacyEntityCache implements EntityCache
{
    public function clear(): void
    {
        Entity::clearEntityCache();
    }
}
