<?php

declare(strict_types=1);

namespace Idno\Storage\Attribute;

/**
 * Gives an entity class the short, stable type its records are stored under (the `type` column),
 * so that stored data does not depend on the class name.
 *
 * Aliases are stored permanently: never change one. When a class is renamed or moved, keep its
 * alias and list its former class names in $legacy, so that rows stored under them are still found.
 * The attribute is not inherited: a subclass that is stored under its own alias declares its own.
 * The record's document keeps the class name (`entity_subtype`), which is what hydration reads.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEntity
{
    /**
     * @param string       $alias      the type: a lower-case letter, then up to 63 lower-case letters, digits or underscores
     * @param list<string> $legacy     former class names of this entity
     * @param string       $collection the collection the entity is stored in (its static $retrieve_collection)
     */
    public function __construct(
        public readonly string $alias,
        public readonly array $legacy = [],
        public readonly string $collection = 'entities',
    )
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $alias) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid entity alias: a lower-case letter, then up to 63 lower-case letters, digits or underscores.', $alias));
        }
    }
}
