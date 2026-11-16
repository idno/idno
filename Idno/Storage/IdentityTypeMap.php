<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * The TypeMap without aliases: the `type` column holds the class name, and a query for a class
 * matches that name only.
 *
 * Built with new, not a container service (see config/services.php).
 */
final class IdentityTypeMap implements TypeMap
{
    public function typeFor(string $class): string
    {
        return $class;
    }

    public function typesFor(string $class): array
    {
        return [$class];
    }
}
