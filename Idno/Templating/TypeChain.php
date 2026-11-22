<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * The template types a draw tries, in order, for the type a template object has.
 *
 * A web page (`default`) tries `modern` first and then `default`. No core or bundled directory has a `modern` type any
 * more: it is there so that a third-party plugin's `templates/modern/` files, written for the Twenty26 theme that Idno 2026
 * replaced, still win on web pages for one release. Every other type (a feed, JSON, ActivityPub, KML, mail) is tried
 * first, then `classic`, which holds the entity templates of the look that Idno 2026 replaced and that feeds and mail
 * still draw, then `default`.
 */
final class TypeChain
{
    /**
     * @return list<string>
     */
    public static function for(string $type): array
    {
        // A type is a directory name, and on a file system that ignores case `DEFAULT` is the directory `default`: so the names are compared in lower case
        if (in_array(strtolower($type), ['default', 'modern'], true)) {
            return ['modern', 'default'];
        }

        return array_values(array_unique([$type, 'classic', 'default']));
    }

    /**
     * Whether a request or code may select the type (a type that has a directory is the caller's check). `classic` is
     * reached by fallback only: a page is never drawn from it.
     */
    public static function selectable(string $type): bool
    {
        return strtolower($type) !== 'classic';
    }
}
