<?php

namespace IdnoPlugins\StandardSiteSync;

use Idno\Common\Entity;

/**
 * Writes status updates, videos and audio to a member's PDS as app.bsky.feed.post
 * records (see PostRecord for what each becomes), and removes them again.
 *
 * What is remembered on the entity:
 *
 * - standardsitesync_post_uri: the at:// address of the record
 * - standardsitesync_post_cid: the CID of the version of the record that was written
 * - standardsitesync_post_hash: a fingerprint of what the record was built from, so that
 *   a post that has not changed is not written again
 * - standardsitesync_post_root: for a reply, the first post of its thread (uri and cid)
 * - standardsitesync_post_gone: set when the member has deleted the record on their PDS
 *   (in Bluesky, say); the post is then not written there again
 * - a "bluesky" syndication link (Entity::setPosseLink) to the post on bsky.app
 *
 * The key of the record comes from the entity (Tid::forEntity), so writing a post twice
 * writes one record: the first write and every later one is a putRecord under that key.
 *
 * Neither method saves the entity: the caller does, when the answer says it changed.
 * Both throw when the PDS refuses; the caller logs that.
 */
class PostSync
{
    const CREATED = 'created';
    const UPDATED = 'updated';
    const UNCHANGED = 'unchanged';
    const SKIPPED = 'skipped';
    const GONE = 'gone';

    /// Part of every fingerprint: raise it when PostRecord builds a different record from the same post
    const VERSION = 1;

    /// A reply to something that is not a Bluesky post reads as a post without its context, so it is not written
    const SEND_REPLIES_TO_OTHER_SITES = false;

    /// How many mentioned handles are looked up for one post
    const MAX_MENTIONS = 5;

    /// The fields this class keeps on an entity
    const FIELDS = [
        'standardsitesync_post_uri',
        'standardsitesync_post_cid',
        'standardsitesync_post_hash',
        'standardsitesync_post_root',
        'standardsitesync_post_gone',
    ];

    private $client;
    private $lookup;

    public function __construct(ATProtoClient $client, ?BlueskyLookup $lookup = null)
    {
        $this->client = $client;
        $this->lookup = $lookup ?: new BlueskyLookup();
    }

    /**
     * @param  mixed $entity
     * @return bool Whether this kind of entity is written as a post
     */
    public static function handles($entity): bool
    {
        return PostSource::kind($entity) !== null;
    }

    /**
     * @param  mixed $entity
     * @return bool Whether a post record has been written for this entity
     */
    public static function hasRecord($entity): bool
    {
        return $entity instanceof Entity
            && is_string($entity->standardsitesync_post_uri)
            && preg_match(PostRecord::AT_URI_PATTERN, $entity->standardsitesync_post_uri) === 1;
    }

    /**
     * Write the record of an entity: create it, or replace it when the post has changed.
     *
     * @param  Entity $entity A public, published status update, video or audio post
     * @return string CREATED, UPDATED or GONE (the entity was changed and is to be saved), UNCHANGED or SKIPPED
     */
    public function sync(Entity $entity): string
    {
        $source = PostSource::fromEntity($entity);
        $did = $this->client->getDid();
        if (!$source || $did === '') {
            return self::SKIPPED;
        }

        $rkey = $this->storedKey($entity);
        if ($rkey === null) {
            if (!empty($entity->standardsitesync_post_gone) || $this->isOnBlueskyAlready($entity)) {
                return self::SKIPPED;
            }
        }

        $fingerprint = hash('sha256', json_encode([self::VERSION, $did, $source]));
        if ($rkey !== null && $entity->standardsitesync_post_hash === $fingerprint) {
            return self::UNCHANGED;
        }

        if (!empty($source['reply_to'])) {
            $reply = $this->replyTo($source['reply_to']);
            if ($reply) {
                $source['reply'] = $reply;
            } elseif (!self::SEND_REPLIES_TO_OTHER_SITES) {
                return self::SKIPPED;
            }
        }
        $source['dids'] = $this->mentions($source);
        if (!empty($source['quote_of'])) {
            if ($quoted = $this->lookup->post($source['quote_of'])) {
                $source['quote'] = ['uri' => $quoted['uri'], 'cid' => $quoted['cid']];
            }
        } elseif (!empty($source['thumb_url'])) {
            $source['thumb'] = $this->thumb($source['thumb_url']);
        }

        $record = PostRecord::build($source);
        if ($record['text'] === '' && empty($record['embed'])) {
            return self::SKIPPED;
        }

        $known = $rkey !== null && is_string($entity->standardsitesync_post_cid) ? $entity->standardsitesync_post_cid : null;
        $created = $rkey === null;
        $rkey = $rkey ?? Tid::forEntity((int) $entity->created, (string) $entity->getID());

        $cid = $this->write($rkey, $record, $created ? null : $known);
        if ($cid === null) {
            // The member deleted the record on their PDS: it is not put back
            $this->forget($entity);
            $entity->standardsitesync_post_gone = true;

            return self::GONE;
        }

        $entity->standardsitesync_post_uri = 'at://' . $did . '/' . PostRecord::COLLECTION . '/' . $rkey;
        $entity->standardsitesync_post_cid = $cid;
        $entity->standardsitesync_post_hash = $fingerprint;
        $entity->standardsitesync_post_root = $source['reply']['root'] ?? null;
        $this->setSyndicationLink($entity, 'https://bsky.app/profile/' . $did . '/post/' . $rkey, $rkey, $did);

        return $created ? self::CREATED : self::UPDATED;
    }

    /**
     * Delete the record of an entity from the PDS and forget it.
     *
     * @param  Entity $entity
     * @return bool Whether the entity was changed (it had a record)
     */
    public function remove(Entity $entity): bool
    {
        if (!self::hasRecord($entity)) {
            return false;
        }
        if (($rkey = $this->storedKey($entity)) !== null) {
            $this->client->deleteRecord(PostRecord::COLLECTION, $rkey);
        }
        $this->forget($entity);

        return true;
    }

    /**
     * The key of the record written for an entity, if it is in the repository of the
     * account that is connected now.
     */
    private function storedKey(Entity $entity): ?string
    {
        if (!self::hasRecord($entity)) {
            return null;
        }
        $prefix = 'at://' . $this->client->getDid() . '/' . PostRecord::COLLECTION . '/';
        $uri = $entity->standardsitesync_post_uri;
        if (strpos($uri, $prefix) !== 0) {
            return null;
        }
        $rkey = substr($uri, strlen($prefix));

        return Tid::isValid($rkey) ? $rkey : null;
    }

    /**
     * Put the record under its key.
     *
     * @param  string      $rkey
     * @param  array       $record
     * @param  string|null $known The CID of the version this site wrote last, when it wrote one
     * @return string|null The CID of the record; null when the record this site wrote is no longer on
     *                     the PDS (the member deleted it there), in which case nothing is written
     */
    private function write(string $rkey, array $record, ?string $known): ?string
    {
        if ($known === null) {
            $result = $this->client->putRecord(PostRecord::COLLECTION, $rkey, $record);
        } else {
            try {
                // Only replace the version this site wrote
                $result = $this->client->putRecord(PostRecord::COLLECTION, $rkey, $record, $known);
            } catch (XrpcException $e) {
                if ($e->getStatus() !== 400 && $e->getStatus() !== 409) {
                    throw $e;
                }
                if ($this->client->getRecord(PostRecord::COLLECTION, $rkey) === null) {
                    return null;
                }
                // It was changed somewhere else; this site has the post, so its version is written
                $result = $this->client->putRecord(PostRecord::COLLECTION, $rkey, $record);
            }
        }

        $cid = $result['cid'] ?? null;
        if (!is_string($cid) || !preg_match(PostRecord::CID_PATTERN, $cid)) {
            throw new \Idno\Exceptions\TechnicalException('The PDS did not answer a write with the CID of the record');
        }

        return $cid;
    }

    /**
     * Whether something else has put this post on Bluesky (a syndication link says so):
     * an imported post, or one another plugin syndicated. It is not posted a second time.
     */
    private function isOnBlueskyAlready(Entity $entity): bool
    {
        foreach ((array) $entity->getPosseLinks() as $service => $links) {
            if (in_array(strtolower((string) $service), ['bluesky', 'bsky', 'atproto'], true)) {
                return true;
            }
            foreach ((array) $links as $link) {
                $url = is_array($link) ? ($link['url'] ?? '') : $link;
                if (is_string($url) && preg_match('~\Ahttps?://(?:[a-z0-9-]+\.)?bsky\.app/~i', $url)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The post a status update replies to, from the addresses it names: a post on
     * Bluesky, or a post on this site that is on Bluesky.
     *
     * @param  string[] $addresses
     * @return array|null 'root' and 'parent', each with 'uri' and 'cid'
     */
    private function replyTo(array $addresses): ?array
    {
        foreach ($addresses as $address) {
            $local = null;
            try {
                $local = Entity::getByUUID($address) ?: Entity::getByURL($address);
            } catch (\Throwable $e) {
                $local = null;
            }
            if ($local instanceof Entity && self::hasRecord($local)) {
                $parent = ['uri' => $local->standardsitesync_post_uri, 'cid' => $local->standardsitesync_post_cid];
                if (PostRecord::isStrongRef($parent)) {
                    $root = $local->standardsitesync_post_root;

                    return ['root' => PostRecord::isStrongRef($root) ? ['uri' => $root['uri'], 'cid' => $root['cid']] : $parent, 'parent' => $parent];
                }
            }

            if (($uri = $this->lookup->postUri($address)) && ($post = $this->lookup->post($uri))) {
                return ['root' => $post['root'], 'parent' => ['uri' => $post['uri'], 'cid' => $post['cid']]];
            }
        }

        return null;
    }

    /**
     * The DID of each handle the post mentions.
     *
     * @return array handle (lower case) => DID
     */
    private function mentions(array $source): array
    {
        $text = array_key_exists('html', $source) ? RichText::fromHtml($source['html'])['text'] : RichText::fromText($source['text'] ?? '')['text'];

        $dids = [];
        foreach (array_slice(RichText::handles($text), 0, self::MAX_MENTIONS) as $handle) {
            if ($did = $this->lookup->resolveHandle($handle)) {
                $dids[$handle] = $did;
            }
        }

        return $dids;
    }

    /**
     * A picture for a card, uploaded to the PDS. A card without a picture is still a card,
     * so nothing that goes wrong here stops the post.
     */
    private function thumb(string $url): ?array
    {
        try {
            $image = $this->lookup->image($url);
            if (!$image) {
                return null;
            }
            $blob = $this->client->uploadBlob($image['bytes'], $image['type']);

            return PostRecord::isImageBlob($blob) ? $blob : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function forget(Entity $entity): void
    {
        $url = null;
        if (self::hasRecord($entity) && preg_match('~\Aat://([^/]+)/[^/]+/([^/]+)\z~', $entity->standardsitesync_post_uri, $found)) {
            $url = 'https://bsky.app/profile/' . $found[1] . '/post/' . $found[2];
        }
        foreach (self::FIELDS as $field) {
            $entity->$field = null;
        }
        if ($url !== null) {
            $this->setSyndicationLink($entity, $url, '', '', true);
        }
    }

    /**
     * Add (or take away) the link to the post on bsky.app, once.
     */
    private function setSyndicationLink(Entity $entity, string $url, string $rkey, string $did, bool $remove = false): void
    {
        $posse = $entity->posse;
        if (!is_array($posse)) {
            $posse = [];
        }
        $links = [];
        foreach ((array) ($posse['bluesky'] ?? []) as $link) {
            if (!is_array($link) || ($link['url'] ?? null) !== $url) {
                $links[] = $link;
            }
        }
        if (!$remove) {
            $links[] = ['url' => $url, 'identifier' => 'Bluesky', 'item_id' => $rkey, 'account_id' => $did];
        }
        if ($links) {
            $posse['bluesky'] = $links;
        } else {
            unset($posse['bluesky']);
        }
        $entity->posse = $posse;
    }
}
