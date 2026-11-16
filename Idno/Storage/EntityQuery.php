<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * A query for EntityRepository: the arguments of the legacy getObjects() / countObjects().
 *
 * - types: entity class names, exactly as stored (no leading backslash; a class does not match its
 *   subclasses). A leading '!' excludes a class. Empty: every type.
 * - criteria: further conditions. They are merged OVER the type and access conditions, as legacy
 *   array_merge() does: a condition on `entity_subtype` or `access` replaces that condition.
 * - limit, offset: the page, cut in the database before unpublished entities are dropped.
 * - readGroups: the access values to allow instead of the current user's (ignored for an admin).
 *
 * A value object, not a container service (see config/services.php).
 */
final class EntityQuery
{
    /**
     * @param list<string> $types      class names; a leading '!' excludes, as in legacy getObjects()
     * @param list<string> $readGroups
     */
    public function __construct(
        public readonly array $types = [],
        public readonly ?Criteria $criteria = null,
        public readonly int $limit = 10,
        public readonly int $offset = 0,
        public readonly string $collection = 'entities',
        public readonly array $readGroups = [],
    )
    {
    }
}
