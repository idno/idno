<?php

namespace IdnoPlugins\StandardSiteSync;

use Idno\Common\Entity;

/**
 * Reads from an entity what PostRecord::build() (and, for audio, EpisodeRecord::episode()) needs. Nothing is asked of any server
 * here; what has to be looked up is named ("reply_to", "quote_of", "thumb_url") for
 * PostSync to resolve.
 */
class PostSource
{
    /// The kinds of entity that are written as app.bsky.feed.post records
    const KINDS = [
        'IdnoPlugins\Status\Status' => 'status',
        'IdnoPlugins\Video\Video'   => 'video',
        'IdnoPlugins\Media\Media'   => 'media',
    ];

    /**
     * @param  mixed $entity
     * @return string|null "status", "video" or "media"; null for anything that is not written as a post
     */
    public static function kind($entity): ?string
    {
        foreach (self::KINDS as $class => $kind) {
            if ($entity instanceof $class) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param  Entity $entity
     * @return array|null The source of the entity's record; null when it is not a kind written as a post
     */
    public static function fromEntity(Entity $entity): ?array
    {
        $kind = self::kind($entity);
        if ($kind === null) {
            return null;
        }

        $source = [
            'kind'    => $kind,
            'url'     => (string) $entity->getURL(),
            'created' => (int) $entity->created,
            'tags'    => self::tags($entity->tags),
            'langs'   => self::langs(),
        ];

        if ($kind === 'status') {
            $source['text'] = is_string($entity->body) ? $entity->body : '';
            $source['reply_to'] = self::addresses($entity->inreplyto);

            return $source;
        }

        $source['title'] = is_string($entity->title) ? $entity->title : '';
        $source['fallback_title'] = (string) $entity->getTitle();
        $source['html'] = is_string($entity->body) ? $entity->body : '';

        if ($kind === 'media' && ($file = self::file($entity))) {
            $source['file'] = $file;
            if (strpos($file['type'], 'audio/') === 0) {
                $source['audio'] = $file;
            }
        }

        if ($kind === 'video') {
            $source['video_url'] = (string) $entity->getVideoAddress();
            if ($video = $entity->getVideo()) {
                $source['provider'] = $video->getProvider();
                if ($video->getProvider() === 'bluesky' && $video->getDid() !== '') {
                    $source['quote_of'] = 'at://' . $video->getDid() . '/' . PostRecord::COLLECTION . '/' . $video->getId();
                }
                if ($video->getProvider() === 'youtube') {
                    // The id has been checked against the pattern of a YouTube id
                    $source['thumb_url'] = 'https://i.ytimg.com/vi/' . $video->getId() . '/hqdefault.jpg';
                }
            }
        }

        return $source;
    }

    /**
     * The file of a Media post: its address on this site, its type and its size.
     *
     * @return array|null 'url', 'type' and 'length'; null when the post has no file
     */
    private static function file(Entity $entity): ?array
    {
        $attachment = $entity->getAttachments()[0] ?? null;
        if (!is_array($attachment) || !is_string($attachment['url'] ?? null)) {
            return null;
        }
        $config = \Idno\Core\Idno::site()->config();
        $url = (string) preg_replace('/^(https?:\/\/\/)/', $config->getDisplayURL(), $attachment['url']);
        $url = \Idno\Core\UrlValidator::httpUrl($config->sanitizeAttachmentURL($url));
        if ($url === '') {
            return null;
        }

        return ['url' => $url, 'type' => is_string($entity->media_type) ? $entity->media_type : '', 'length' => (int) ($attachment['length'] ?? 0)];
    }

    /**
     * The tags an entity was given, without "#".
     *
     * @param  mixed $tags A list, or text with the tags in it
     * @return string[]
     */
    private static function tags($tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[\s,]+/u', $tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($tags)) {
            return [];
        }

        $kept = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && ($tag = ltrim(trim($tag), '#＃')) !== '') {
                $kept[] = $tag;
            }
        }

        return array_values(array_unique($kept));
    }

    /**
     * The addresses a status update is a reply to.
     *
     * @param  mixed $inreplyto
     * @return string[]
     */
    private static function addresses($inreplyto): array
    {
        $kept = [];
        foreach ((array) $inreplyto as $address) {
            if (is_string($address) && trim($address) !== '') {
                $kept[] = trim($address);
            }
        }

        return $kept;
    }

    /**
     * The language the site is written in, if it says.
     *
     * @return string[]
     */
    private static function langs(): array
    {
        try {
            $language = \Idno\Core\Idno::site()->language()->getLanguage();
        } catch (\Throwable $e) {
            $language = null;
        }

        return is_string($language) && $language !== '' ? [str_replace('_', '-', $language)] : [];
    }
}
