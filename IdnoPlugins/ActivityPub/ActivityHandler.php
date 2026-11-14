<?php

namespace IdnoPlugins\ActivityPub;

use Idno\Entities\User;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * Processes incoming ActivityPub activities received at inbox endpoints.
 * All processing is synchronous as per the requirements.
 */
class ActivityHandler
{

    /**
     * Handle an incoming activity.
     *
     * @param string      $rawBody   Raw JSON request body
     * @param array       $headers   Normalized request headers (lowercase keys)
     * @param string      $method    HTTP method
     * @param string      $path      Request path
     * @param string|null $targetHandle Optional: the handle of the target user (for per-user inbox)
     * @return array ['status' => HTTP status code, 'body' => response body]
     */
    public static function handle(string $rawBody, array $headers, string $method, string $path, ?string $targetHandle = null): array
    {
        // Parse the activity
        $activity = json_decode($rawBody, true);
        if (empty($activity) || empty($activity['type'])) {
            \Idno\Core\Idno::site()->logging()->warning(
                'ActivityPub: Invalid activity body (length=' . strlen($rawBody) . '): ' .
                substr($rawBody, 0, 200)
            );
            return ['status' => 400, 'body' => json_encode(['error' => 'Invalid activity'])];
        }

        \Idno\Core\Idno::site()->logging()->info(
            'ActivityPub: Received ' . $activity['type'] . ' activity from ' .
            ($activity['actor'] ?? 'unknown') . ' id=' . ($activity['id'] ?? 'none')
        );

        // Verify HTTP signature
        if (!self::verifySignature($headers, $rawBody, $method, $path, $activity)) {
            \Idno\Core\Idno::site()->logging()->warning(
                'ActivityPub: Signature verification FAILED for ' . $activity['type'] .
                ' from ' . ($activity['actor'] ?? 'unknown')
            );
            return ['status' => 401, 'body' => json_encode(['error' => 'Invalid signature'])];
        }

        \Idno\Core\Idno::site()->logging()->info(
            'ActivityPub: Signature verified for ' . $activity['type'] .
            ' from ' . ($activity['actor'] ?? 'unknown')
        );

        // Route by activity type
        $type = $activity['type'];
        switch ($type) {
            case 'Follow':
                return self::handleFollow($activity, $targetHandle);

            case 'Undo':
                return self::handleUndo($activity);

            case 'Delete':
                return self::handleDelete($activity);

            case 'QuoteRequest':
                return self::handleQuoteRequest($activity);

            case 'Accept':
                // We don't currently initiate follows, so just acknowledge
                return ['status' => 200, 'body' => ''];

            case 'Reject':
                // Acknowledged but not acted on for now
                return ['status' => 200, 'body' => ''];

            default:
                \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Unhandled activity type: ' . $type);
                return ['status' => 202, 'body' => ''];
        }
    }

    /**
     * Verify the HTTP signature on an incoming request.
     *
     * @param array  $headers  Normalized request headers
     * @param string $body     Raw request body
     * @param string $method   HTTP method
     * @param string $path     Request path
     * @param array  $activity Parsed activity (for actor fallback)
     * @return bool
     */
    private static function verifySignature(array $headers, string $body, string $method, string $path, array $activity): bool
    {
        if (empty($headers['signature'])) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: No signature on incoming request');
            return false;
        }

        // Parse the signature to get keyId
        $sigParams = HTTPSignature::parseSignatureHeader($headers['signature']);
        if (!$sigParams || empty($sigParams['keyId'])) {
            return false;
        }

        // Try to resolve the local target user for signed key fetching.
        // This is needed for remote instances that require authorized fetch.
        $localUser = null;
        $object = $activity['object'] ?? '';
        $targetActorId = is_string($object) ? $object : ($object['id'] ?? '');
        if (!empty($targetActorId)) {
            $localUser = self::resolveLocalUser($targetActorId);
            if ($localUser === false) {
                $localUser = null;
            }
        }

        // Fetch the remote actor's public key (using signed GET when possible)
        \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Fetching public key for ' . $sigParams['keyId']);
        $publicKey = RemoteActor::fetchPublicKey($sigParams['keyId'], $localUser);
        if (!$publicKey) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Could not fetch public key for ' . $sigParams['keyId']);
            return false;
        }

        $result = HTTPSignature::verify($publicKey, $headers, $body, $method, $path);
        if (!$result) {
            \Idno\Core\Idno::site()->logging()->warning(
                'ActivityPub: Signature crypto verification failed for keyId=' . $sigParams['keyId'] .
                ' method=' . $method . ' path=' . $path
            );
            return false;
        }

        // Verify that the signing key's actor matches the actor claimed in the activity body.
        // This prevents actor spoofing where attacker signs with their key but claims a different identity.
        $keyActorUri = preg_replace('/#.*$/', '', $sigParams['keyId']);
        $bodyActorUri = $activity['actor'] ?? '';
        if (!empty($bodyActorUri) && $keyActorUri !== $bodyActorUri) {
            // Allow if the key and body actors are on the same domain (covers some legitimate delegation patterns)
            $keyHost = parse_url($keyActorUri, PHP_URL_HOST);
            $bodyHost = parse_url($bodyActorUri, PHP_URL_HOST);
            if ($keyHost !== $bodyHost) {
                \Idno\Core\Idno::site()->logging()->warning(
                    'ActivityPub: Actor spoofing attempt - keyId actor ' . $keyActorUri .
                    ' does not match body actor ' . $bodyActorUri
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Handle an incoming Follow activity.
     *
     * @param array       $activity     The Follow activity
     * @param string|null $targetHandle Expected target user handle (from URL)
     * @return array
     */
    private static function handleFollow(array $activity, ?string $targetHandle = null): array
    {
        $actorUri = $activity['actor'] ?? '';
        $object = $activity['object'] ?? '';

        // The object of a Follow is the actor being followed (our user)
        if (empty($actorUri) || empty($object)) {
            return ['status' => 400, 'body' => json_encode(['error' => 'Missing actor or object'])];
        }

        // Resolve the local user being followed
        $targetActorId = is_string($object) ? $object : ($object['id'] ?? '');
        $user = self::resolveLocalUser($targetActorId);

        if (!$user) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Follow target not found: ' . $targetActorId);
            return ['status' => 404, 'body' => json_encode(['error' => 'User not found'])];
        }

        // Validate against expected handle if provided
        if ($targetHandle && strtolower($user->getHandle()) !== strtolower($targetHandle)) {
            return ['status' => 400, 'body' => json_encode(['error' => 'Target mismatch'])];
        }

        // Fetch remote actor data (needed for both new and duplicate follows)
        $actorData = RemoteActor::fetch($actorUri);
        if (!$actorData) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Could not resolve remote actor: ' . $actorUri);
            return ['status' => 400, 'body' => json_encode(['error' => 'Could not resolve actor'])];
        }

        // Check for existing follower record (idempotent).
        // Re-send the Accept in case the remote server never received it
        // (e.g. the original delivery failed or was lost).
        $existing = ActivityPubFollower::getByActorAndUser($actorUri, $user->getUUID());
        if ($existing) {
            \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Duplicate Follow from ' . $actorUri . ' — re-sending Accept');
            self::sendAccept($activity, $user, $actorData['inbox']);
            return ['status' => 200, 'body' => ''];
        }

        // Create follower record
        $follower = new ActivityPubFollower();
        $follower->actor_uri = $actorData['id'];
        $follower->actor_inbox = $actorData['inbox'];
        $follower->actor_shared_inbox = $actorData['sharedInbox'];
        $follower->user_uuid = $user->getUUID();
        $follower->actor_handle = $actorData['handle'];
        $follower->actor_name = $actorData['name'];
        $follower->actor_icon = $actorData['icon'];
        $follower->actor_public_key = $actorData['publicKey']['publicKeyPem'] ?? null;
        $follower->follow_activity_id = $activity['id'] ?? '';
        $follower->setAccess('PUBLIC');

        if (!$follower->save(true)) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: Failed to save follower record for ' . $actorUri);
            return ['status' => 500, 'body' => json_encode(['error' => 'Internal error'])];
        }

        \Idno\Core\Idno::site()->logging()->info('ActivityPub: New follower ' . $actorData['handle'] . ' for user ' . $user->getHandle());

        // Auto-accept: deliver Accept to the remote actor's inbox
        self::sendAccept($activity, $user, $actorData['inbox']);

        return ['status' => 202, 'body' => ''];
    }

    /**
     * Send an Accept activity in response to a Follow.
     * Delivers synchronously so the remote server receives the Accept
     * promptly, then also enqueues a backup delivery via the async pipeline.
     *
     * @param array  $followActivity The original Follow activity
     * @param User   $user           The local user accepting the follow
     * @param string $targetInbox    The remote actor's inbox URL
     */
    private static function sendAccept(array $followActivity, User $user, string $targetInbox): void
    {
        $acceptActivity = ActivityBuilder::buildAccept($followActivity, $user);

        // Deliver synchronously first — timely Accept delivery is important
        // for implementations like Ghost that check follow state on reload.
        $delivered = Delivery::deliverNow($user, $acceptActivity, $targetInbox);

        if ($delivered) {
            \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Accept delivered synchronously to ' . $targetInbox);
        } else {
            // Synchronous delivery failed — fall back to async queue.
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Synchronous Accept delivery failed, falling back to queue for ' . $targetInbox);
            \Idno\Core\Idno::site()->queue()->enqueue('default', 'activitypub/deliver', [
                'user_uuid' => $user->getUUID(),
                'activity'  => $acceptActivity,
                'inbox'     => $targetInbox,
            ], $user->getUUID());
        }
    }

    /**
     * Handle an incoming Undo activity.
     *
     * @param array $activity The Undo activity
     * @return array
     */
    private static function handleUndo(array $activity): array
    {
        $actorUri = $activity['actor'] ?? '';
        $object = $activity['object'] ?? [];

        if (empty($actorUri)) {
            return ['status' => 400, 'body' => json_encode(['error' => 'Missing actor'])];
        }

        // Determine what's being undone
        $objectType = '';
        if (is_string($object)) {
            // Object is just an ID - we need to figure out what it is
            // Most commonly this is an Undo of a Follow
            $objectType = 'Follow'; // Assume Follow for now
        } elseif (is_array($object)) {
            $objectType = $object['type'] ?? '';
        }

        if ($objectType === 'Follow') {
            return self::handleUndoFollow($actorUri, $object);
        }

        \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Unhandled Undo type: ' . $objectType);
        return ['status' => 200, 'body' => ''];
    }

    /**
     * Handle an Undo Follow (unfollow).
     *
     * @param string       $actorUri The actor who is unfollowing
     * @param array|string $object   The Follow activity being undone
     * @return array
     */
    private static function handleUndoFollow(string $actorUri, $object): array
    {
        // Determine the target user
        $targetActorId = '';
        if (is_array($object) && !empty($object['object'])) {
            $targetActorId = is_string($object['object']) ? $object['object'] : ($object['object']['id'] ?? '');
        }

        if (!empty($targetActorId)) {
            $user = self::resolveLocalUser($targetActorId);
            if ($user) {
                $follower = ActivityPubFollower::getByActorAndUser($actorUri, $user->getUUID());
                if ($follower) {
                    $follower->delete();
                    \Idno\Core\Idno::site()->logging()->info('ActivityPub: Unfollowed by ' . $actorUri . ' on user ' . $user->getHandle());
                }
            }
        } else {
            // No target specified - remove all follower records for this actor
            $count = ActivityPubFollower::removeByActor($actorUri);
            \Idno\Core\Idno::site()->logging()->info('ActivityPub: Removed ' . $count . ' follower record(s) for actor ' . $actorUri);
        }

        return ['status' => 200, 'body' => ''];
    }

    /**
     * Handle an incoming Delete activity.
     *
     * @param array $activity The Delete activity
     * @return array
     */
    private static function handleDelete(array $activity): array
    {
        $actorUri = $activity['actor'] ?? '';

        if (empty($actorUri)) {
            return ['status' => 400, 'body' => json_encode(['error' => 'Missing actor'])];
        }

        $object = $activity['object'] ?? '';
        $objectId = is_string($object) ? $object : ($object['id'] ?? '');

        // If the actor is deleting themselves
        if ($objectId === $actorUri) {
            $count = ActivityPubFollower::removeByActor($actorUri);
            \Idno\Core\Idno::site()->logging()->info('ActivityPub: Actor ' . $actorUri . ' deleted themselves, removed ' . $count . ' follower record(s)');
        }

        return ['status' => 200, 'body' => ''];
    }

    /**
     * Handle an incoming QuoteRequest activity (FEP-044f).
     * Auto-accepts all quote requests and sends back an Accept with a stamp URL.
     *
     * @param array $activity The QuoteRequest activity
     * @return array
     */
    private static function handleQuoteRequest(array $activity): array
    {
        $actorUri = $activity['actor'] ?? '';
        $quotedPostUri = $activity['object'] ?? '';
        $instrument = $activity['instrument'] ?? [];

        // The object is the URI of our post being quoted
        if (empty($actorUri) || empty($quotedPostUri)) {
            return ['status' => 400, 'body' => json_encode(['error' => 'Missing actor or object'])];
        }

        // The instrument contains the quoting post
        $quotingPostUri = '';
        if (is_array($instrument)) {
            $quotingPostUri = $instrument['id'] ?? '';
        } elseif (is_string($instrument)) {
            $quotingPostUri = $instrument;
        }

        if (empty($quotingPostUri)) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: QuoteRequest missing instrument (quoting post URI)');
            return ['status' => 400, 'body' => json_encode(['error' => 'Missing instrument'])];
        }

        // Resolve the quoted post URI to find the local post owner
        $quotedPostId = is_string($quotedPostUri) ? $quotedPostUri : ($quotedPostUri['id'] ?? '');

        // Try to find the local entity by UUID/URL
        $entity = \Idno\Common\Entity::getByUUID($quotedPostId);
        if (!$entity) {
            // Try by URL as fallback
            $entity = \Idno\Common\Entity::getBySlug($quotedPostId);
        }

        if (!$entity) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: QuoteRequest for unknown post: ' . $quotedPostId);
            return ['status' => 404, 'body' => json_encode(['error' => 'Quoted post not found'])];
        }

        $user = $entity->getOwner();
        if (!$user || !($user instanceof User)) {
            return ['status' => 404, 'body' => json_encode(['error' => 'Post owner not found'])];
        }

        \Idno\Core\Idno::site()->logging()->info(
            'ActivityPub: QuoteRequest from ' . $actorUri .
            ' to quote ' . $quotedPostId .
            ' with post ' . $quotingPostUri . ' — auto-approving'
        );

        // Generate the stamp URL
        $stampUrl = ActivityBuilder::buildQuoteStampUrl($quotedPostId, $quotingPostUri);

        // Build and deliver the Accept
        $acceptActivity = ActivityBuilder::buildQuoteAccept($activity, $user, $stampUrl);

        // Fetch the remote actor's inbox for delivery
        $remoteActor = RemoteActor::fetch($actorUri, $user);
        if (!$remoteActor) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Could not resolve QuoteRequest actor: ' . $actorUri);
            return ['status' => 202, 'body' => ''];
        }

        $inbox = $remoteActor['inbox'] ?? '';
        if (!empty($inbox)) {
            \Idno\Core\Idno::site()->queue()->enqueue('default', 'activitypub/deliver', [
                'user_uuid' => $user->getUUID(),
                'activity'  => $acceptActivity,
                'inbox'     => $inbox,
            ], $user->getUUID());
        }

        return ['status' => 202, 'body' => ''];
    }

    /**
     * Resolve a local user from an ActivityPub actor ID.
     *
     * @param string $actorId ActivityPub actor ID (e.g., https://example.com/actor/username)
     * @return User|false
     */
    private static function resolveLocalUser(string $actorId)
    {
        $siteUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();

        // Try to extract handle from actor URL pattern: {siteUrl}actor/{handle}
        $actorPrefix = $siteUrl . 'actor/';
        if (strpos($actorId, $actorPrefix) === 0) {
            $handle = substr($actorId, strlen($actorPrefix));
            $handle = rtrim($handle, '/');
            return User::getByHandle($handle);
        }

        // Fallback: try to find user by iterating (less efficient)
        // This handles custom actor URLs
        if ($results = User::get([], [], PHP_INT_MAX)) {
            foreach ($results as $user) {
                if ($user->getActivityPubActorID() === $actorId) {
                    return $user;
                }
            }
        }

        return false;
    }
}
