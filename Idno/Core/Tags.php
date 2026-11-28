<?php

declare(strict_types=1);

namespace Idno\Core;

/**
 * What the tags of a post may be, and how a stored value is read.
 *
 * An entity keeps its tags in its "tags" field, as one text ("#one #two") or as a list of texts (what a Micropub
 * request's categories become). The value is whatever the request that made the post sent, and it is shown to every
 * visitor of the post and handed to other servers in feeds.
 *
 * clean() is for the way in: it makes plain text of what was sent (a string, trimmed, control characters removed; it keeps `<`
 * and emoji joiners, since a tag is text, never markup). texts() is for the way out: it reads a stored value, which may be older than clean(), as a list of
 * texts. Neither escapes anything: every place a tag is printed escapes it. Pure: nothing here asks the site.
 */
final class Tags
{
    /** The most characters one tag, or the one text of several tags, may have. */
    public const MAX_LENGTH = 255;

    /** The most tags a list may have. */
    public const MAX_TAGS = 100;

    /**
     * What a request sent as tags, as an entity stores it: one line of plain text for a text or a number, a list of such
     * lines for an array (anything in it that is no text or number is left out), and null for anything else.
     *
     * @return string|list<string>|null
     */
    public static function clean(mixed $tags): string|array|null
    {
        if (is_array($tags)) {
            $kept = [];
            foreach ($tags as $tag) {
                if (self::isText($tag) && ($tag = self::cleanText((string) $tag)) !== '' && !in_array($tag, $kept, true)) {
                    $kept[] = $tag;
                }
            }

            return array_slice($kept, 0, self::MAX_TAGS);
        }

        return self::isText($tags) ? self::cleanText((string) $tags) : null;
    }

    /**
     * A stored "tags" value as a list of texts: the texts and numbers of a list, or the one text, trimmed, without the
     * empty ones. Anything else in the value is ignored. The texts are as they were stored and may hold markup: escape them.
     *
     * @return list<string>
     */
    public static function texts(mixed $tags): array
    {
        $texts = [];
        foreach (is_array($tags) ? $tags : [$tags] as $tag) {
            if (self::isText($tag) && ($tag = trim((string) $tag)) !== '') {
                $texts[] = $tag;
            }
        }

        return $texts;
    }

    /** The address of the page that lists the posts with a tag, on the site whose address is given (with its trailing slash); escape it for an attribute. */
    public static function url(string $tag, string $siteAddress): string
    {
        return $siteAddress . 'tag/' . urlencode($tag);
    }

    private static function isText(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }

    /** One line of plain text: control characters removed, white space made single spaces, at most MAX_LENGTH characters. Nothing else is changed. */
    private static function cleanText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $text = (string) preg_replace(['/[\p{Cc}\s]+/u'], ' ', $text);

        return trim(mb_substr(trim($text), 0, self::MAX_LENGTH, 'UTF-8'));
    }
}
