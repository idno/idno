<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * The template types a draw tries, in order, for the type a template object has.
 */
final class TypeChain
{
    /**
     * `default` is tried alone; any other type is tried first and `default` after it.
     *
     * @return list<string>
     */
    public static function for(string $type): array
    {
        return $type === 'default' ? ['default'] : [$type, 'default'];
    }

    /**
     * Whether a request or code may select the type (a type that has a directory is the caller's check).
     */
    public static function selectable(string $type): bool
    {
        return true;
    }
}
