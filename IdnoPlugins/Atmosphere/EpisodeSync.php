<?php

namespace IdnoPlugins\Atmosphere;

use Idno\Common\Entity;
use Idno\Entities\User;

/**
 * Writes an audio post to the member's PDS as an episode of their series (see
 * EpisodeRecord), and removes it again. When the member has Bluesky posts made, PostSync
 * writes that post first, so that the episode can name it.
 *
 * What is remembered: on the entity, standardsitesync_episode_uri, _cid, _hash and
 * _gone (as PostSync does for the post); on the member, standardsitesync_series_uri,
 * _cid and _hash for their series record.
 *
 * Neither the entity nor the member is saved here: the caller saves both.
 */
class EpisodeSync
{
    /// Part of every fingerprint: raise it when EpisodeRecord builds a different record from the same values
    const VERSION = 1;

    /// The fields this class keeps on an entity
    const FIELDS = [
        'standardsitesync_episode_uri',
        'standardsitesync_episode_cid',
        'standardsitesync_episode_hash',
        'standardsitesync_episode_gone',
    ];

    private $client;

    public function __construct(ATProtoClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param  mixed $entity
     * @return bool Whether this entity is an audio post
     */
    public static function handles($entity): bool
    {
        return PostSource::kind($entity) === 'media' && !empty(PostSource::fromEntity($entity)['audio']);
    }

    /**
     * @param  mixed $entity
     * @return bool Whether an episode record has been written for this entity
     */
    public static function hasRecord($entity): bool
    {
        return $entity instanceof Entity
            && is_string($entity->standardsitesync_episode_uri)
            && preg_match(PostRecord::AT_URI_PATTERN, $entity->standardsitesync_episode_uri) === 1;
    }

    /**
     * Write the episode record of an audio post, and the member's series record when
     * it is not there yet or has changed.
     *
     * @param  Entity $entity A public, published audio post
     * @param  User   $user   Its owner
     * @return string PostSync::CREATED, UPDATED or GONE (the entity was changed), UNCHANGED or SKIPPED
     */
    public function sync(Entity $entity, User $user): string
    {
        $source = PostSource::fromEntity($entity);
        $did = $this->client->getDid();
        if (!$source || empty($source['audio']) || $did === '') {
            return PostSync::SKIPPED;
        }

        $rkey = $this->storedKey($entity->standardsitesync_episode_uri, EpisodeRecord::EPISODE);
        if ($rkey === null && !empty($entity->standardsitesync_episode_gone)) {
            return PostSync::SKIPPED;
        }

        $source['series'] = $this->series($user);
        $source['updated'] = (int) $entity->updated;
        $post = ['uri' => $entity->standardsitesync_post_uri, 'cid' => $entity->standardsitesync_post_cid];
        if (PostRecord::isStrongRef($post)) {
            $source['post'] = $post;
        }
        // When the post was last saved is not a change to it
        $fingerprint = hash('sha256', json_encode([self::VERSION, $did, array_diff_key($source, ['updated' => 1])]));
        if ($rkey !== null && $entity->standardsitesync_episode_hash === $fingerprint) {
            return PostSync::UNCHANGED;
        }

        $created = $rkey === null;
        $known = !$created && is_string($entity->standardsitesync_episode_cid) ? $entity->standardsitesync_episode_cid : null;
        $rkey = $rkey ?? Tid::forEntity((int) $entity->created, (string) $entity->getID());

        $cid = RecordWriter::put($this->client, EpisodeRecord::EPISODE, $rkey, EpisodeRecord::episode($source), $known);
        if ($cid === null) {
            // The member deleted the episode on their PDS: it is not put back
            $this->forget($entity);
            $entity->standardsitesync_episode_gone = true;

            return PostSync::GONE;
        }

        $entity->standardsitesync_episode_uri = 'at://' . $did . '/' . EpisodeRecord::EPISODE . '/' . $rkey;
        $entity->standardsitesync_episode_cid = $cid;
        $entity->standardsitesync_episode_hash = $fingerprint;

        return $created ? PostSync::CREATED : PostSync::UPDATED;
    }

    /**
     * Delete the episode record of an entity from the PDS and forget it. The series
     * stays: it is the member's, and may have other episodes.
     *
     * @param  Entity $entity
     * @return bool Whether the entity was changed (it had an episode record)
     */
    public function remove(Entity $entity): bool
    {
        if (!self::hasRecord($entity)) {
            return false;
        }
        if (($rkey = $this->storedKey($entity->standardsitesync_episode_uri, EpisodeRecord::EPISODE)) !== null) {
            $this->client->deleteRecord(EpisodeRecord::EPISODE, $rkey);
        }
        $this->forget($entity);

        return true;
    }

    /**
     * The member's series record, written when it is not on the PDS or what it says has changed.
     *
     * @return string Its AT-URI
     */
    private function series(User $user): string
    {
        $did = $this->client->getDid();
        $site = \Idno\Core\Idno::site();

        $name = trim((string) $user->getName()) ?: (string) $user->getHandle();
        $record = EpisodeRecord::series([
            'handle'   => (string) $user->getHandle(),
            'title'    => $name . ' on ' . $site->config()->getTitle(),
            'author'   => $name,
            'url'      => (string) $user->getURL(),
            'language' => (string) $site->language()->getLanguage(),
            'created'  => (int) $user->created,
        ]);
        $fingerprint = hash('sha256', json_encode([self::VERSION, $did, $record]));

        $rkey = $this->storedKey($user->standardsitesync_series_uri, EpisodeRecord::SERIES);
        if ($rkey !== null && $user->standardsitesync_series_hash === $fingerprint) {
            return $user->standardsitesync_series_uri;
        }

        $known = $rkey !== null && is_string($user->standardsitesync_series_cid) ? $user->standardsitesync_series_cid : null;
        $rkey = $rkey ?? Tid::forEntity((int) $user->created, (string) $user->getID());

        // An episode needs its series, so one that was deleted on the PDS is written again
        $cid = RecordWriter::put($this->client, EpisodeRecord::SERIES, $rkey, $record, $known)
            ?? RecordWriter::put($this->client, EpisodeRecord::SERIES, $rkey, $record, null);

        $user->standardsitesync_series_uri = 'at://' . $did . '/' . EpisodeRecord::SERIES . '/' . $rkey;
        $user->standardsitesync_series_cid = $cid;
        $user->standardsitesync_series_hash = $fingerprint;

        return $user->standardsitesync_series_uri;
    }

    /**
     * The key of a record this site wrote, if it is in the repository of the account
     * that is connected now.
     *
     * @param mixed  $uri
     * @param string $collection
     */
    private function storedKey($uri, string $collection): ?string
    {
        $prefix = 'at://' . $this->client->getDid() . '/' . $collection . '/';
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
