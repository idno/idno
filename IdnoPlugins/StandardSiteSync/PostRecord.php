<?php

namespace IdnoPlugins\StandardSiteSync;

/**
 * Builds app.bsky.feed.post records (Bluesky posts) for the kinds of post that are
 * short updates rather than documents: status updates, videos and audio.
 *
 * | Kind   | Text                          | Embed                                              |
 * |--------|-------------------------------|----------------------------------------------------|
 * | status | the update                    | none; a reply carries `reply` (root and parent)     |
 * | video  | the title and the description | a video in a Bluesky post: that post, quoted        |
 * |        |                               | (app.bsky.embed.record); any other video: a card    |
 * |        |                               | for the video's address (app.bsky.embed.external)   |
 * | media  | the title and the description | a card for the post on this site (embed.external).  |
 * |        |                               | Bluesky has no record for audio.                    |
 *
 * app.bsky.embed.video is for a video file kept on the member's PDS, and is not used:
 * the site keeps no video files to give it.
 *
 * build() works on the values it is given and asks nothing of anybody; PostSource reads
 * those values from an entity, and PostSync looks up what has to be asked for (the DID of
 * a mentioned handle, the post replied to or quoted, a picture for a card).
 */
class PostRecord
{
    const COLLECTION = 'app.bsky.feed.post';

    /// A post may carry 8 tags besides the ones in its text, and name 3 languages
    const MAX_TAGS = 8;
    const MAX_LANGS = 3;

    /// How much of a title and a description goes on a card, in graphemes
    const CARD_TITLE = 200;
    const CARD_DESCRIPTION = 300;

    /// The largest picture a card may have, in bytes
    const MAX_THUMB = 1000000;

    const AT_URI_PATTERN = '#\Aat://did:[a-z]+:[a-zA-Z0-9._:%-]+/[a-zA-Z0-9.-]+/[a-zA-Z0-9._:~-]{1,512}\z#';
    const CID_PATTERN = '/\A[a-zA-Z0-9+=]{8,256}\z/';
    const LANGUAGE_PATTERN = '/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{1,8})*\z/';

    /**
     * Build the record for a post.
     *
     * @param array $source
     *   - kind: "status", "video" or "media"
     *   - text: the body as plain text (a status update), or
     *   - html: the body as HTML (a description)
     *   - title: the title the member gave, if any (not used for a status update)
     *   - fallback_title: what to call the post on a card when it has no title
     *   - url: the address of the post on this site
     *   - created: when the post was made (seconds since the UNIX epoch)
     *   - tags: tags of the post, without "#"
     *   - langs: language tags (BCP 47)
     *   - dids: the DID of each mentioned handle that has one (handle in lower case => DID)
     *   - reply: for a reply, ['root' => ['uri', 'cid'], 'parent' => ['uri', 'cid']]
     *   - quote: for a video in a Bluesky post, that post: ['uri', 'cid']
     *   - video_url: the address of the video
     *   - thumb: a picture for the card, as uploadBlob returned it
     * @return array The record
     */
    public static function build(array $source): array
    {
        $kind = $source['kind'] ?? 'status';
        $url = RichText::isHttpUri($source['url'] ?? null) ? $source['url'] : '';

        $body = array_key_exists('html', $source) ? RichText::fromHtml($source['html']) : RichText::fromText($source['text'] ?? '');
        $title = $kind === 'status' ? '' : self::plain($source['title'] ?? '');

        $text = $body['text'];
        $links = $body['links'];
        if ($title !== '' && strpos($text, $title) !== 0) {
            // The title is the first line of the post
            $prefix = $title . ($text === '' ? '' : "\n\n");
            $text = $prefix . $text;
            foreach ($links as $i => $link) {
                $links[$i]['start'] += strlen($prefix);
                $links[$i]['end'] += strlen($prefix);
            }
        }

        $dids = is_array($source['dids'] ?? null) ? $source['dids'] : [];
        $fitted = RichText::fit($text, RichText::facets($text, $links, $dids), $url);

        $record = [
            '$type'     => self::COLLECTION,
            'text'      => $fitted['text'],
            'createdAt' => self::datetime($source['created'] ?? null),
        ];
        if ($fitted['facets']) {
            $record['facets'] = $fitted['facets'];
        }

        $reply = $source['reply'] ?? null;
        if ($kind === 'status' && is_array($reply) && self::isStrongRef($reply['root'] ?? null) && self::isStrongRef($reply['parent'] ?? null)) {
            $record['reply'] = [
                'root'   => self::strongRef($reply['root']),
                'parent' => self::strongRef($reply['parent']),
            ];
        }

        if ($embed = self::embed($kind, $source, $title, $body['text'], $url)) {
            $record['embed'] = $embed;
        }

        if ($langs = self::langs($source['langs'] ?? [])) {
            $record['langs'] = $langs;
        }
        if ($tags = self::tags($source['tags'] ?? [], $fitted['facets'])) {
            $record['tags'] = $tags;
        }

        return $record;
    }

    /**
     * What a post shows besides its text.
     */
    private static function embed(string $kind, array $source, string $title, string $description, string $url): ?array
    {
        if ($kind === 'status') {
            return null;
        }

        if ($kind === 'video' && self::isStrongRef($source['quote'] ?? null)) {
            return [
                '$type'  => 'app.bsky.embed.record',
                'record' => self::strongRef($source['quote']),
            ];
        }

        $uri = $url;
        if ($kind === 'video' && RichText::isHttpUri($source['video_url'] ?? null)) {
            $uri = $source['video_url'];
        }
        if ($uri === '') {
            return null;
        }

        $external = [
            'uri'         => $uri,
            'title'       => RichText::truncate($title !== '' ? $title : self::plain($source['fallback_title'] ?? ''), self::CARD_TITLE),
            'description' => RichText::truncate($description, self::CARD_DESCRIPTION),
        ];
        if (self::isImageBlob($source['thumb'] ?? null)) {
            $external['thumb'] = $source['thumb'];
        }

        return ['$type' => 'app.bsky.embed.external', 'external' => $external];
    }

    /**
     * A moment as the AT Protocol writes it: RFC 3339, in UTC, with milliseconds.
     *
     * @param  mixed $time Seconds since the UNIX epoch
     * @return string
     */
    public static function datetime($time): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', is_numeric($time) && $time > 0 ? (int) $time : time());
    }

    /**
     * @param  mixed $ref
     * @return bool Whether this names a record and its version (com.atproto.repo.strongRef)
     */
    public static function isStrongRef($ref): bool
    {
        return is_array($ref)
            && is_string($ref['uri'] ?? null) && preg_match(self::AT_URI_PATTERN, $ref['uri'])
            && is_string($ref['cid'] ?? null) && preg_match(self::CID_PATTERN, $ref['cid']);
    }

    private static function strongRef(array $ref): array
    {
        return ['uri' => $ref['uri'], 'cid' => $ref['cid']];
    }

    /**
     * @param  mixed $blob
     * @return bool Whether this is a picture a card can have, as uploadBlob returns one
     */
    public static function isImageBlob($blob): bool
    {
        return is_array($blob)
            && ($blob['$type'] ?? null) === 'blob'
            && is_string($blob['ref']['$link'] ?? null) && preg_match(self::CID_PATTERN, $blob['ref']['$link'])
            && is_string($blob['mimeType'] ?? null) && strpos($blob['mimeType'], 'image/') === 0
            && is_int($blob['size'] ?? null) && $blob['size'] > 0 && $blob['size'] <= self::MAX_THUMB;
    }

    /**
     * @param  mixed $langs
     * @return string[] At most three language tags
     */
    private static function langs($langs): array
    {
        $kept = [];
        foreach ((array) $langs as $lang) {
            $lang = is_string($lang) ? str_replace('_', '-', trim($lang)) : '';
            if (preg_match(self::LANGUAGE_PATTERN, $lang) && !in_array($lang, $kept, true)) {
                $kept[] = $lang;
            }
        }

        return array_slice($kept, 0, self::MAX_LANGS);
    }

    /**
     * The tags of the post that are not already hashtags in its text.
     *
     * @param  mixed $tags
     * @param  array $facets
     * @return string[]
     */
    private static function tags($tags, array $facets): array
    {
        $seen = [];
        foreach ($facets as $facet) {
            foreach ($facet['features'] as $feature) {
                if (isset($feature['tag'])) {
                    $seen[mb_strtolower($feature['tag'], 'UTF-8')] = true;
                }
            }
        }

        $kept = [];
        foreach ((array) $tags as $tag) {
            $tag = is_string($tag) ? ltrim(trim(RichText::clean($tag)), '#＃') : '';
            $key = mb_strtolower($tag, 'UTF-8');
            if (RichText::isTag($tag) && !preg_match('/\s/u', $tag) && !isset($seen[$key])) {
                $seen[$key] = true;
                $kept[] = $tag;
            }
        }

        return array_slice($kept, 0, self::MAX_TAGS);
    }

    /**
     * A title as plain text on one line.
     *
     * @param  mixed $text
     * @return string
     */
    public static function plain($text): string
    {
        $text = RichText::clean(html_entity_decode(strip_tags(RichText::clean($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
