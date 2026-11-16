<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFields;

/**
 * Mentions the attributes only in its docblock, as an example of their use:
 *
 * #[\Idno\Storage\Attribute\AsEntity('example')]
 * #[IndexedField('example')]
 *
 * so the finder never loads it (EntityClassFinder reads attributes at the start of a line only).
 */
final class DocblockOnly
{
}
