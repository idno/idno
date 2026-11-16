<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * ActorRecord in a later version that no longer declares its indexed field (fully qualified attribute).
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_actor_v2')]
final class ActorRecordWithoutIndex
{
}
