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
        // A private site sends nothing, whenever the delivery was queued
        if (!Main::federates()) {
            \Idno\Core\Idno::site()->logging()->debug('ActivityPub: This site is private; nothing is delivered');
            return false;
        }

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
            // The inbox URL comes from the remote actor's profile: safeSend() refuses it, and
            // anything it redirects to, if it is on this machine or its private network
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
        // Nothing new goes out in the name of a banned member, or of an account that is being deleted
        if ($user->isBanned() || $user->isClosed()) {
            return;
        }

        // Nothing leaves a private site
        if (!Main::federates()) {
            return;
        }

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
     * Tell the servers of a local account's followers that the actor is gone: ONE Delete
     * whose object is the actor (ActivityBuilder::buildActorDelete()), for each follower's
     * inbox (a shared inbox once for each server). Called when the account is closed
     * (the account/closed event), while it still has its key. The deliveries are SIGNED
     * HERE and queued as they are to be sent (the inbox, the body and the signed headers:
     * the event activitypub/deliver-signed, run as nobody), so the worker needs neither the
     * account nor its key, which are gone by then. Once every delivery is handed over the
     * account's follower records are removed; if one could not be queued they are kept.
     * Without a queue worker the deliveries are made after the response.
     *
     * Nothing is sent from a private site, or for an account with no key or no followers.
     * A signature is dated when it is made, and servers accept a date within twelve hours:
     * a queue that is not worked for longer has these deliveries refused.
     *
     * @param User $user The account that is being deleted
     * @return int How many deliveries were signed
     */
    public static function announceActorDeleted(User $user): int
    {
        $send = [];
        $privateKey = (string) $user->privateKey; // (as stored: an account that never federated has none, and is given none now)
        $inboxes = Main::federates() ? ActivityPubFollower::getUniqueInboxesForUser($user->getUUID()) : [];
        if (!empty($inboxes) && $privateKey !== '') {
            $keyId = $user->getPublicKey()['id'];
            $body = json_encode(ActivityBuilder::buildActorDelete($user), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($inboxes as $inbox) {
                if ($body === false || !filter_var($inbox, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $headers = HTTPSignature::sign($privateKey, $keyId, $inbox, $body);
                if (!empty($headers)) {
                    $send[] = ['inbox' => $inbox, 'body' => $body, 'headers' => $headers];
                }
            }
        }

        $userUuid = $user->getUUID();
        $handOver = static function () use ($send, $userUuid) {
            $all = true;
            foreach ($send as $delivery) {
                if (\Idno\Core\Idno::site()->queue()->enqueue('default', 'activitypub/deliver-signed', $delivery, false) === false) {
                    $all = false;
                }
            }
            if ($all) {
                ActivityPubFollower::removeForUser($userUuid);
            }
        };
        if (\Idno\Core\Idno::site()->queue() instanceof \Idno\Core\AsynchronousQueue) {
            $handOver();
        } else {
            // With no worker a queued event runs at once: not inside the administrator's request
            \Idno\Core\AfterResponse::later($handOver);
        }

        return count($send);
    }

    /**
     * Send a body that was signed already (see announceActorDeleted()) to an inbox.
     *
     * @param string $inboxUrl
     * @param string $body
     * @param array  $headers The signed header lines
     * @return bool Did the server take it (HTTP 2xx)?
     */
    public static function deliverSigned(string $inboxUrl, string $body, array $headers): bool
    {
        if (!Main::federates()) {
            return false;
        }
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
