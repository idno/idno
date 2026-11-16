<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * A class that cannot be loaded (its parent does not exist): the finder skips its file.
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_unloadable')]
final class Unloadable extends ParentThatDoesNotExist
{
}
