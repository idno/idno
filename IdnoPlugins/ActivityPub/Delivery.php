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
     * Tell the servers of a local account's followers that the actor is gone: ONE Delete whose object is the actor, signed with the account's key and
     * sent to each follower's inbox (a shared inbox once for each server). The deliveries are SIGNED HERE and queued as they are to be sent (the inbox, the
     * body and the signed headers), so they need neither the account nor its key when a worker runs them: the account is deleted by then. Then the
     * account's follower records are removed (before anything is sent). With the synchronous queue the deliveries are made after the response. Nothing is sent when the account has no key (it never federated) or no followers.
     *
     * A signature is dated when it is made, and the servers that receive it accept a date within twelve hours: a queue that is not worked for longer than
     * that has these deliveries refused.
     *
     * @param User $user The account that is being deleted
     * @return int How many deliveries were queued
     */
    public static function announceActorDeleted(User $user): int
    {
        $queued  = 0;
        $inboxes = ActivityPubFollower::getUniqueInboxesForUser($user->getUUID());
        $send    = [];
        if (!empty($inboxes) && !empty($user->privateKey)) {
            $actorId  = $user->getActivityPubActorID();
            $keyId    = $actorId . '#main-key';
            $body     = json_encode(ActivityBuilder::buildActorDelete($user), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($inboxes as $inbox) {
                if ($body === false || !filter_var($inbox, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $headers = HTTPSignature::sign((string) $user->privateKey, $keyId, $inbox, $body);
                if (!empty($headers)) {
                    $send[] = ['inbox' => $inbox, 'body' => $body, 'headers' => $headers];
                }
            }
        }
        // The follower records go first: what is signed is all that is needed to deliver
        ActivityPubFollower::removeForUser($user->getUUID());
        $enqueue = static function () use ($send, &$queued): void {
            foreach ($send as $delivery) {
                if (\Idno\Core\Idno::site()->queue()->enqueue('default', 'activitypub/deliver-signed', $delivery, false) !== false) {
                    $queued++;
                }
            }
        };
        if (\Idno\Core\Idno::site()->queue()->isAsynchronous()) {
            $enqueue();
        } else {
            // With no worker the deliveries are made at once: not inside the administrator's request, which has other things to do
            \Idno\Core\AfterResponse::later($enqueue);
        }

        return $queued;
    }

    /**
     * Send a body that has been signed already (see announceActorDeleted()) to an inbox. Whether the server took it (HTTP 2xx).
     *
     * @param string $inboxUrl
     * @param string $body
     * @param array  $headers The signed header lines
     * @return bool
     */
    public static function deliverSigned(string $inboxUrl, string $body, array $headers): bool
    {
        if (!filter_var($inboxUrl, FILTER_VALIDATE_URL)) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Invalid inbox URL for delivery: ' . $inboxUrl);
            return false;
        }
        try {
            $response = Webservice::safeSend('post', $inboxUrl, $body, $headers);
            $httpCode = $response['response'] ?? 0;
            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Delivery to ' . $inboxUrl . ' returned HTTP ' . $httpCode);
        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: Exception during delivery to ' . $inboxUrl . ': ' . $e->getMessage());
        }

        return false;
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
