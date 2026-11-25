<?php

namespace IdnoPlugins\StandardSiteSync;

/**
 * Builds the records of Idno's own lexicons (the files in lexicons/), which a member's
 * status updates, videos and audio are written to their PDS as:
 *
 * | Post                                  | Collection          |
 * |---------------------------------------|---------------------|
 * | a status update                       | co.idno.feed.status |
 * | a video by its address (Video plugin) | co.idno.feed.video  |
 * | a video file (Media plugin)           | co.idno.feed.video  |
 * | an audio file (Media plugin)          | co.idno.feed.audio  |
 *
 * These are not Bluesky records: nothing written with them appears in Bluesky. A member
 * who wants their posts there as well turns that on, and PostRecord builds those.
 *
 * Everything here works on the values it is given and asks nothing of anybody.
 */
class FeedRecord
{
    const STATUS = 'co.idno.feed.status';
    const VIDEO = 'co.idno.feed.video';
    const AUDIO = 'co.idno.feed.audio';

    /// What the lexicons allow
    const MAX_TEXT_GRAPHEMES = 10000;
    const MAX_TEXT_BYTES = 100000;
    const MAX_TITLE_GRAPHEMES = 300;
    const MAX_TITLE_BYTES = 3000;
    const MAX_TAGS = 50;
    const MAX_REPLIES = 10;

    /**
     * The collection a post is written to.
     *
     * @param  array $source What PostSource::fromEntity() returned
     * @return string|null null when the post has nothing a record can be made of
     */
    public static function collection(array $source): ?string
    {
        if (!RichText::isHttpUri($source['url'] ?? null)) {
            return null;
        }
        switch ($source['kind'] ?? null) {
            case 'status':
                return RichText::fromText($source['text'] ?? '')['text'] !== '' ? self::STATUS : null;
            case 'video':
                return RichText::isHttpUri($source['video_url'] ?? null) ? self::VIDEO : null;
            case 'media':
                if (!RichText::isHttpUri($source['file']['url'] ?? null)) {
                    return null;
                }

                return empty($source['audio']) ? self::VIDEO : self::AUDIO;
            default:
                return null;
        }
    }

    /**
     * Build the record of a post.
     *
     * @param array $source What PostSource::fromEntity() returned, and
     *   - updated: when the post was last changed (seconds since the UNIX epoch)
     *   - post: the Bluesky post made for it, if the member has one made: ['uri', 'cid']
     * @return array|null The record; null when collection() is null
     */
    public static function build(array $source): ?array
    {
        $collection = self::collection($source);
        if ($collection === null) {
            return null;
        }

        $record = ['$type' => $collection];
        $file = is_array($source['file'] ?? null) ? $source['file'] : [];

        if ($collection === self::VIDEO) {
            if (($source['kind'] ?? null) === 'video') {
                $record['video'] = $source['video_url'];
                if (is_string($source['provider'] ?? null) && preg_match('/\A[a-z0-9_-]{1,64}\z/', $source['provider'])) {
                    $record['provider'] = $source['provider'];
                }
                if (is_string($source['quote_of'] ?? null) && preg_match(PostRecord::AT_URI_PATTERN, $source['quote_of'])) {
                    $record['videoRecord'] = $source['quote_of'];
                }
            } else {
                $record['video'] = $file['url'];
                $record += self::fileFacts($file);
            }
        } elseif ($collection === self::AUDIO) {
            $record['audio'] = $file['url'];
            $record += self::fileFacts($file);
        }

        if ($collection !== self::STATUS) {
            $title = RichText::truncate(PostRecord::plain($source['title'] ?? ''), self::MAX_TITLE_GRAPHEMES, self::MAX_TITLE_BYTES);
            if ($title !== '') {
                $record['title'] = $title;
            }
        }

        $body = array_key_exists('html', $source) ? RichText::fromHtml($source['html']) : RichText::fromText($source['text'] ?? '');
        $fitted = RichText::fit($body['text'], RichText::facets($body['text'], $body['links']), '', self::MAX_TEXT_GRAPHEMES, self::MAX_TEXT_BYTES);
        if ($fitted['text'] !== '' || $collection === self::STATUS) {
            $record['text'] = $fitted['text'];
        }
        if ($fitted['facets']) {
            $record['facets'] = $fitted['facets'];
        }

        $record['url'] = $source['url'];

        if ($collection === self::STATUS) {
            $replies = array_values(array_filter((array) ($source['reply_to'] ?? []), [RichText::class, 'isHttpUri']));
            if ($replies) {
                $record['inReplyTo'] = array_slice($replies, 0, self::MAX_REPLIES);
            }
        }

        if ($tags = PostRecord::tags($source['tags'] ?? [], $fitted['facets'], self::MAX_TAGS)) {
            $record['tags'] = $tags;
        }
        if ($langs = PostRecord::langs($source['langs'] ?? [])) {
            $record['langs'] = $langs;
        }
        if (PostRecord::isStrongRef($source['post'] ?? null)) {
            $record['bskyPostRef'] = ['uri' => $source['post']['uri'], 'cid' => $source['post']['cid']];
        }

        $created = $source['created'] ?? null;
        $record['createdAt'] = PostRecord::datetime($created);
        if (is_numeric($source['updated'] ?? null) && is_numeric($created) && (int) $source['updated'] > (int) $created) {
            $record['updatedAt'] = PostRecord::datetime($source['updated']);
        }

        return $record;
    }

    /**
     * The type and the size of a file, where they are known.
     */
    private static function fileFacts(array $file): array
    {
        $facts = [];
        $type = is_string($file['type'] ?? null) ? strtolower(trim($file['type'])) : '';
        $type = EpisodeRecord::AUDIO_TYPES[$type] ?? $type;
        if (preg_match('~\A[a-z]+/[a-z0-9.+-]{1,100}\z~', $type)) {
            $facts['mimeType'] = $type;
        }
        if (is_numeric($file['length'] ?? null) && (int) $file['length'] > 0) {
            $facts['size'] = (int) $file['length'];
        }

        return $facts;
    }
}
