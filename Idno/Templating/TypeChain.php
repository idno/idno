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
     * @return list<string> empty when $type is not a template type (one segment: see TemplateName::isType())
     */
    public static function for(string $type): array
    {
        if (!TemplateName::isType($type)) {
            return [];
        }
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
        return TemplateName::isType($type) && strtolower($type) !== 'classic';
    }

    /**
     * Whether a REQUEST (`?_t=`) may select the type: what code may select (selectable()), except the mail types. A mail type is set
     * by the mail sender; a plain-text mail (`email-text`) is not escaped, so a web page drawn from it would print text as markup.
     */
    public static function requestable(string $type): bool
    {
        return self::selectable($type) && !in_array(strtolower($type), ['email', 'email-text'], true);
    }
}
