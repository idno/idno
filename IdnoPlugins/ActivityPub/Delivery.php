<?php

namespace IdnoPlugins\ActivityPub;

use Idno\Core\Webservice;
use Idno\Entities\User;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * Handles delivery of ActivityPub activities to remote inboxes.
 */
class Delivery
{

    /**
     * Deliver a signed activity to a specific remote inbox.
     *
     * @param User   $user     The local user sending the activity (used for signing)
     * @param array  $activity The activity to deliver
     * @param string $inboxUrl Target inbox URL
     * @return bool True if delivery succeeded (HTTP 2xx response)
     */
    public static function deliver(User $user, array $activity, string $inboxUrl): bool
    {
        if (empty($inboxUrl) || !filter_var($inboxUrl, FILTER_VALIDATE_URL)) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Invalid inbox URL for delivery: ' . $inboxUrl);
            return false;
        }

        $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: Failed to JSON-encode activity for delivery');
            return false;
        }

        $keyId = $user->getPublicKey()['id'];
        $privateKey = $user->getPrivateKey();

        if (empty($privateKey)) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: No private key available for user ' . $user->getHandle());
            return false;
        }

        $headers = HTTPSignature::sign($privateKey, $keyId, $inboxUrl, $body);
        if (empty($headers)) {
            return false;
        }

        try {
            $response = Webservice::safeSend('post', $inboxUrl, $body, $headers);

            $httpCode = $response['response'] ?? 0;

            if ($httpCode >= 200 && $httpCode < 300) {
                \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Successfully delivered to ' . $inboxUrl . ' (HTTP ' . $httpCode . ')');
                return true;
            }

            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Delivery to ' . $inboxUrl . ' returned HTTP ' . $httpCode);
            return false;

        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: Exception during delivery to ' . $inboxUrl . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enqueue delivery of an activity to all of a user's followers' inboxes.
     * Uses the async queue for background processing.
     *
     * @param User  $user     The local user whose followers should receive the activity
     * @param array $activity The activity to deliver
     */
    public static function deliverToFollowers(User $user, array $activity): void
    {
        $inboxes = ActivityPubFollower::getUniqueInboxesForUser($user->getUUID());

        if (empty($inboxes)) {
            \Idno\Core\Idno::site()->logging()->debug('ActivityPub: No follower inboxes for user ' . $user->getHandle());
            return;
        }

        \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Queueing delivery to ' . count($inboxes) . ' inbox(es) for user ' . $user->getHandle());

        foreach ($inboxes as $inbox) {
            \Idno\Core\Idno::site()->queue()->enqueue('default', 'activitypub/deliver', [
                'user_uuid' => $user->getUUID(),
                'activity'  => $activity,
                'inbox'     => $inbox,
            ], $user->getUUID());
        }
    }

    /**
     * Deliver an activity to a single inbox immediately (synchronous).
     * Used for Accept responses to Follow requests where timely delivery matters.
     *
     * @param User   $user     The local user
     * @param array  $activity The activity to deliver
     * @param string $inboxUrl Target inbox URL
     * @return bool
     */
    public static function deliverNow(User $user, array $activity, string $inboxUrl): bool
    {
        return self::deliver($user, $activity, $inboxUrl);
    }
}
