<?php

namespace IdnoPlugins\StandardSiteSync;

/**
 * Builds the records of VoxPort's podcast lexicons, which an audio post is written as
 * beside its Bluesky post (Bluesky has no record for audio):
 *
 * - live.voxport.podcast.series: one for each member, the series their audio posts
 *   on this site are the episodes of
 * - live.voxport.podcast.episode: one for each audio post, with the address, type and
 *   size of the audio file (the file stays on this site) and, in blueskyPostRef, the
 *   Bluesky post that was written for it
 *
 * Both methods work on the values they are given and ask nothing of anybody. The
 * lengths these lexicons give are in bytes.
 */
class EpisodeRecord
{
    const SERIES = 'live.voxport.podcast.series';
    const EPISODE = 'live.voxport.podcast.episode';

    const MAX_TITLE = 256;
    const MAX_SUMMARY = 10000;
    const MAX_HANDLE = 64;
    const MAX_TAGS = 20;
    const MAX_TAG = 64;

    /// What this site calls a type of audio, and what the lexicon calls it
    const AUDIO_TYPES = [
        'audio/x-m4a' => 'audio/mp4',
        'audio/m4a'   => 'audio/mp4',
        'audio/mp3'   => 'audio/mpeg',
        'audio/x-wav' => 'audio/wav',
    ];

    /**
     * The series of a member.
     *
     * @param array $source
     *   - handle: the member's handle on this site
     *   - title: what the series is called
     *   - author: the member's name
     *   - url: the address of the member's profile
     *   - language: the language of the site (BCP 47)
     *   - created: when the member joined (seconds since the UNIX epoch)
     * @return array The record
     */
    public static function series(array $source): array
    {
        $handle = substr((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($source['handle'] ?? '')), 0, self::MAX_HANDLE);
        $handle = trim($handle, '-');

        $record = [
            '$type'     => self::SERIES,
            'handle'    => $handle !== '' ? $handle : 'audio',
            'title'     => self::bytes(PostRecord::plain($source['title'] ?? ''), self::MAX_TITLE),
            'createdAt' => PostRecord::datetime($source['created'] ?? null),
        ];
        if (($author = self::bytes(PostRecord::plain($source['author'] ?? ''), self::MAX_TITLE)) !== '') {
            $record['author'] = $author;
        }
        if (RichText::isHttpUri($source['url'] ?? null)) {
            $record['websiteUrl'] = $source['url'];
        }
        $language = is_string($source['language'] ?? null) ? str_replace('_', '-', $source['language']) : '';
        if (preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $language)) {
            $record['language'] = $language;
        }

        return $record;
    }

    /**
     * An episode: an audio post.
     *
     * @param array $source What PostSource::fromEntity() returns for an audio post, and
     *   - series: the AT-URI of the member's series record
     *   - updated: when the post was last changed
     *   - post: the Bluesky post written for it: ['uri', 'cid']
     * @return array The record
     */
    public static function episode(array $source): array
    {
        $title = PostRecord::plain($source['title'] ?? '');
        $record = [
            '$type'  => self::EPISODE,
            'series' => ['uri' => (string) ($source['series'] ?? '')],
            'title'  => self::bytes($title !== '' ? $title : PostRecord::plain($source['fallback_title'] ?? ''), self::MAX_TITLE),
        ];

        $summary = self::bytes(RichText::fromHtml($source['html'] ?? '')['text'], self::MAX_SUMMARY);
        if ($summary !== '') {
            $record['summary'] = $summary;
        }

        $audio = is_array($source['audio'] ?? null) ? $source['audio'] : [];
        if (RichText::isHttpUri($audio['url'] ?? null)) {
            $record['audioUrl'] = $audio['url'];
            $type = is_string($audio['type'] ?? null) ? strtolower(trim($audio['type'])) : '';
            $type = self::AUDIO_TYPES[$type] ?? $type;
            if (preg_match('~\Aaudio/[a-z0-9.+-]{1,58}\z~', $type)) {
                $record['audioType'] = $type;
            }
            if (is_numeric($audio['length'] ?? null) && (int) $audio['length'] > 0) {
                $record['audioLength'] = (int) $audio['length'];
            }
        }

        $created = $source['created'] ?? null;
        $record['publishedAt'] = PostRecord::datetime($created);
        $record['createdAt'] = PostRecord::datetime($created);
        if (is_numeric($source['updated'] ?? null) && is_numeric($created) && (int) $source['updated'] > (int) $created) {
            $record['updatedAt'] = PostRecord::datetime($source['updated']);
        }

        $tags = [];
        foreach ((array) ($source['tags'] ?? []) as $tag) {
            $tag = is_string($tag) ? ltrim(trim(RichText::clean($tag)), '#＃') : '';
            if ($tag !== '' && strlen($tag) <= self::MAX_TAG && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
        if ($tags) {
            $record['tags'] = array_slice($tags, 0, self::MAX_TAGS);
        }

        if (PostRecord::isStrongRef($source['post'] ?? null)) {
            $record['blueskyPostRef'] = ['uri' => $source['post']['uri'], 'cid' => $source['post']['cid']];
        }

        return $record;
    }

    /**
     * A text of at most so many bytes, cut between two graphemes.
     */
    private static function bytes(string $text, int $max): string
    {
        return RichText::truncate($text, $max, $max);
    }
}
