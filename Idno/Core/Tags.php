<?php

    /**
     * The tags of a post
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    /**
     * What the tags of a post may be, and how the stored value is read.
     *
     * An entity keeps its tags in its "tags" field, as one text ("#one #two") or as a list of
     * texts (what a Micropub request's categories become). The value comes from whoever makes
     * the post, in a form field, an API request or a Micropub request, and it is shown to
     * every visitor of the post.
     *
     * clean() is for the way in: it makes plain text of what was sent. texts() is for the way
     * out: it reads a stored value, which may be older than clean(), as a list of texts.
     * Neither escapes anything: a template prints each text with htmlspecialchars().
     */
    class Tags
    {

        /// The most characters one tag, or the one text of several tags, may have
        const MAX_LENGTH = 255;

        /// The most tags a list may have
        const MAX_TAGS = 100;

        /**
         * Make of what a request sent as tags what an entity stores: one line of plain text
         * for a text or a number, a list of such lines for an array (anything in it that is
         * no text or number is left out), and null for anything else.
         *
         * @param  mixed $tags
         * @return string|string[]|null
         */
        public static function clean($tags)
        {
            if (is_array($tags)) {
                $kept = [];
                foreach ($tags as $tag) {
                    if (static::isText($tag) && ($tag = static::cleanText((string) $tag)) !== '' && !in_array($tag, $kept, true)) {
                        $kept[] = $tag;
                    }
                }

                return array_slice($kept, 0, static::MAX_TAGS);
            }
            if (static::isText($tags)) {
                return static::cleanText((string) $tags);
            }

            return null;
        }

        /**
         * A stored "tags" value as a list of texts: the texts and numbers of a list, or the
         * one text, trimmed, without the empty ones. Anything else in the value is ignored.
         * The texts are as they were stored, so they may hold markup: escape them.
         *
         * @param  mixed $tags
         * @return string[]
         */
        public static function texts($tags)
        {
            $texts = [];
            foreach (is_array($tags) ? $tags : [$tags] as $tag) {
                if (static::isText($tag) && ($tag = trim((string) $tag)) !== '') {
                    $texts[] = $tag;
                }
            }

            return $texts;
        }

        /**
         * The address of the page that lists the posts with a tag, ready for an href
         * attribute once it has been through htmlspecialchars().
         *
         * @param  string $tag
         * @return string
         */
        public static function url($tag)
        {
            return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'tag/' . urlencode((string) $tag);
        }

        private static function isText($value)
        {
            return is_string($value) || is_int($value) || is_float($value);
        }

        /**
         * One line of plain text: no elements, no angle brackets, no control characters,
         * single spaces, MAX_LENGTH characters at most.
         */
        private static function cleanText($text)
        {
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_scrub($text, 'UTF-8');
            }
            $text = str_replace(['<', '>'], ' ', strip_tags($text));
            $text = (string) preg_replace('/[\p{C}\s]+/u', ' ', $text);

            return trim(mb_substr(trim($text), 0, static::MAX_LENGTH, 'UTF-8'));
        }

    }

}
