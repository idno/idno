<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * The value a store keeps in a record's `type` column for its class (the record's `entity_subtype`),
 * and the values a query for a class matches there. The document itself always keeps the class name.
 */
interface TypeMap
{
    /**
     * The `type` column's value for a record of this class.
     */
    public function typeFor(string $class): string;

    /**
     * Every `type` column value a query for this class matches (an alias, the class name, names the
     * class had before); never empty.
     *
     * @return list<string>
     */
    public function typesFor(string $class): array;
}
