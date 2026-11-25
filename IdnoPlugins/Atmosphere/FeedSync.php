<?php

namespace IdnoPlugins\Atmosphere;

use Idno\Common\Entity;

/**
 * Writes a status update, a video or an audio post to the member's PDS as a record of
 * Idno's own lexicons (see FeedRecord), and removes it again.
 *
 * What is remembered on the entity: standardsitesync_record_uri, _cid, _hash and _gone,
 * as PostSync does for a Bluesky post. The key of the record comes from the entity
 * (Tid::forEntity), so writing a post twice writes one record.
 *
 * The entity is not saved here: the caller saves it when the answer says it changed.
 */
class FeedSync
{
    /// Part of every fingerprint: raise it when FeedRecord builds a different record from the same post
    const VERSION = 1;

    /// The fields this class keeps on an entity
    const FIELDS = [
        'standardsitesync_record_uri',
        'standardsitesync_record_cid',
        'standardsitesync_record_hash',
        'standardsitesync_record_gone',
    ];

    private $client;

    public function __construct(ATProtoClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param  mixed $entity
     * @return bool Whether a record has been written for this entity
     */
    public static function hasRecord($entity): bool
    {
        return $entity instanceof Entity
            && is_string($entity->standardsitesync_record_uri)
            && preg_match(PostRecord::AT_URI_PATTERN, $entity->standardsitesync_record_uri) === 1;
    }

    /**
     * Write the record of an entity: create it, or replace it when the post has changed.
     *
     * @param  Entity $entity A public, published status update, video or audio post
     * @return string PostSync::CREATED, UPDATED or GONE (the entity was changed), UNCHANGED or SKIPPED
     */
    public function sync(Entity $entity): string
    {
        $source = PostSource::fromEntity($entity);
        $did = $this->client->getDid();
        $collection = $source ? FeedRecord::collection($source) : null;
        if ($collection === null || $did === '') {
            return PostSync::SKIPPED;
        }

        // A record in another collection (the post was audio and is now a video, say) is not this one
        $rkey = $this->storedKey($entity, $collection);
        if ($rkey === null && self::hasRecord($entity)) {
            $this->remove($entity);
        }
        if ($rkey === null && !empty($entity->standardsitesync_record_gone)) {
            return PostSync::SKIPPED;
        }

        $post = ['uri' => $entity->standardsitesync_post_uri, 'cid' => $entity->standardsitesync_post_cid];
        if (PostRecord::isStrongRef($post)) {
            $source['post'] = $post;
        }
        // When the post was last saved is not a change to it
        $fingerprint = hash('sha256', json_encode([self::VERSION, $did, $source]));
        if ($rkey !== null && $entity->standardsitesync_record_hash === $fingerprint) {
            return PostSync::UNCHANGED;
        }
        $source['updated'] = (int) $entity->updated;

        $created = $rkey === null;
        $known = !$created && is_string($entity->standardsitesync_record_cid) ? $entity->standardsitesync_record_cid : null;
        $rkey = $rkey ?? Tid::forEntity((int) $entity->created, (string) $entity->getID());

        $cid = RecordWriter::put($this->client, $collection, $rkey, FeedRecord::build($source), $known);
        if ($cid === null) {
            // The member deleted the record on their PDS: it is not put back
            $this->forget($entity);
            $entity->standardsitesync_record_gone = true;

            return PostSync::GONE;
        }

        $entity->standardsitesync_record_uri = 'at://' . $did . '/' . $collection . '/' . $rkey;
        $entity->standardsitesync_record_cid = $cid;
        $entity->standardsitesync_record_hash = $fingerprint;

        return $created ? PostSync::CREATED : PostSync::UPDATED;
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
        $prefix = 'at://' . $this->client->getDid() . '/';
        $uri = $entity->standardsitesync_record_uri;
        if (strpos($uri, $prefix) === 0) {
            [$collection, $rkey] = explode('/', substr($uri, strlen($prefix)), 2) + [1 => ''];
            if (in_array($collection, [FeedRecord::STATUS, FeedRecord::VIDEO, FeedRecord::AUDIO], true) && Tid::isValid($rkey)) {
                $this->client->deleteRecord($collection, $rkey);
            }
        }
        $this->forget($entity);

        return true;
    }

    /**
     * The key of the record written for an entity, if it is in this collection of the
     * repository of the account that is connected now.
     */
    private function storedKey(Entity $entity, string $collection): ?string
    {
        $prefix = 'at://' . $this->client->getDid() . '/' . $collection . '/';
        $uri = $entity->standardsitesync_record_uri;
        if (!is_string($uri) || strpos($uri, $prefix) !== 0) {
            return null;
        }
        $rkey = substr($uri, strlen($prefix));

        return Tid::isValid($rkey) ? $rkey : null;
    }

    private function forget(Entity $entity): void
    {
        foreach (self::FIELDS as $field) {
            $entity->$field = null;
        }
    }
}
