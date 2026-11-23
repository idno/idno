<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * What a template name or a template type may be, in one place.
 *
 * A name comes from code and, through `?_t=`, from a request, and is joined to a path, so every name passes
 * through here before it does.
 */
final class TemplateName
{
    /**
     * A name a path may be built from: letters, digits, slashes, hyphens and underscores, and no `..`. (The
     * pattern ends in `$`, which also matches before a trailing newline: such a name is safe, and names no file.)
     */
    public static function isSafe(string $name): bool
    {
        return !str_contains($name, '..') && preg_match('/^[a-zA-Z0-9\/_-]+$/', $name) === 1;
    }

    /**
     * The name a template or a type is looked up by, or null when it is refused. A leading `_` followed by any
     * letters, digits and slashes is removed (so `_ABC/x` is nothing and `_ab-tail` is `-tail`), then a name
     * that holds `..` or a character outside `[a-zA-Z0-9/_-]`, or is empty, is refused.
     */
    public static function normalise(string $name): ?string
    {
        $name = preg_replace('/^_[A-Z0-9\/]+/i', '', $name);

        return is_string($name) && self::isSafe($name) ? $name : null;
    }

    /**
     * Whether $type is a template type: exactly one segment of letters, digits, hyphens and underscores. A type is a directory
     * name, so one with a slash (`classic/` is the directory `classic`) is not a type. (The pattern ends in `\z`: a trailing
     * newline is refused.)
     */
    public static function isType(string $type): bool
    {
        return preg_match('/\A[a-zA-Z0-9_-]+\z/', $type) === 1;
    }

    /**
     * The type a name asks for, by the rule of normalise(), or null when it is not a template type (see isType()).
     */
    public static function normaliseType(string $type): ?string
    {
        $type = self::normalise($type);

        return $type !== null && self::isType($type) ? $type : null;
    }
}
