<?php

namespace IdnoPlugins\ActivityPub\Entities;

/**
 * Represents an ActivityPub follower relationship.
 * Stored as a standard Idno entity, discriminated by entity_subtype.
 */
#[\Idno\Storage\Attribute\AsEntity('activitypub_follower')]
class ActivityPubFollower extends \Idno\Common\Entity
{

    function getTitle()
    {
        return $this->actor_name ?: $this->actor_handle ?: $this->actor_uri;
    }

    function getDescription()
    {
        return 'ActivityPub follower: ' . $this->actor_uri;
    }

    function getActivityStreamsObjectType()
    {
        return false; // Not a publishable content type
    }

    /**
     * Find a follower record by remote actor URI and local user UUID.
     *
     * @param string $actorUri Remote actor's ActivityPub ID
     * @param string $userUuid Local user's UUID
     * @return ActivityPubFollower|false
     */
    public static function getByActorAndUser(string $actorUri, string $userUuid)
    {
        $results = static::get([
            'entity_subtype' => 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower',
            'actor_uri'      => $actorUri,
            'user_uuid'      => $userUuid,
        ], [], 1);

        if (!empty($results) && is_array($results)) {
            return $results[0];
        }

        return false;
    }

    /**
     * Get all followers for a local user, paginated.
     *
     * @param string $userUuid Local user's UUID
     * @param int    $limit    Max results
     * @param int    $offset   Offset for pagination
     * @return array Array of ActivityPubFollower entities
     */
    public static function getFollowersForUser(string $userUuid, int $limit = 40, int $offset = 0): array
    {
        $results = static::get([
            'entity_subtype' => 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower',
            'user_uuid'      => $userUuid,
        ], [], $limit, $offset);

        return is_array($results) ? $results : [];
    }

    /**
     * Count the number of ActivityPub followers for a user.
     *
     * @param string $userUuid Local user's UUID
     * @return int
     */
    public static function getFollowerCount(string $userUuid): int
    {
        $results = static::countFromAll([
            'entity_subtype' => 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower',
            'user_uuid'      => $userUuid,
        ]);

        return (int)$results;
    }

    /**
     * Get deduplicated inbox URLs for all of a user's followers.
     * Prefers shared inboxes for efficiency (one delivery per server).
     *
     * @param string $userUuid Local user's UUID
     * @return array Array of unique inbox URLs
     */
    public static function getUniqueInboxesForUser(string $userUuid): array
    {
        $followers = static::get([
            'entity_subtype' => 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower',
            'user_uuid'      => $userUuid,
        ], [], PHP_INT_MAX);

        if (empty($followers) || !is_array($followers)) {
            return [];
        }

        $inboxes = [];
        $sharedInboxServers = []; // Track which servers we've added a shared inbox for

        foreach ($followers as $follower) {
            $sharedInbox = $follower->actor_shared_inbox;
            $personalInbox = $follower->actor_inbox;

            if (!empty($sharedInbox)) {
                // Use shared inbox - deduplicate by inbox URL
                $parsed = parse_url($sharedInbox);
                $serverKey = ($parsed['host'] ?? '') . ':' . ($parsed['port'] ?? '');
                if (!isset($sharedInboxServers[$serverKey])) {
                    $sharedInboxServers[$serverKey] = true;
                    $inboxes[] = $sharedInbox;
                }
            } elseif (!empty($personalInbox)) {
                // Fall back to personal inbox
                $inboxes[] = $personalInbox;
            }
        }

        return array_unique($inboxes);
    }

    /**
     * Remove all follower records for a given remote actor.
     *
     * @param string $actorUri Remote actor's ActivityPub ID
     * @return int Number of records deleted
     */
    public static function removeByActor(string $actorUri): int
    {
        $followers = static::get([
            'entity_subtype' => 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower',
            'actor_uri'      => $actorUri,
        ], [], PHP_INT_MAX);

        $count = 0;
        if (is_array($followers)) {
            foreach ($followers as $follower) {
                if ($follower->delete()) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
