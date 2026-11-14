<?php

namespace IdnoPlugins\ActivityPub;

use Idno\Core\Webservice;
use Idno\Entities\User;

/**
 * Fetches and parses remote ActivityPub actor profiles.
 */
class RemoteActor
{

    /**
     * Fetch a remote actor's profile by their ActivityPub ID.
     * Uses a signed GET when a local user is available, which is required by
     * instances that enable "authorized fetch" (secure mode).
     *
     * @param string    $actorUri The remote actor's ID URI
     * @param User|null $asUser   Optional local user whose key signs the request
     * @return array|false Structured actor data, or false on failure
     */
    public static function fetch(string $actorUri, ?User $asUser = null)
    {
        if (empty($actorUri) || !filter_var($actorUri, FILTER_VALIDATE_URL)) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Invalid actor URI: ' . $actorUri);
            return false;
        }

        // SSRF protection: block requests to private/reserved IP ranges
        if (!\Idno\Core\UrlValidator::isSafeUrl($actorUri)) {
            return false;
        }

        // Resolve a signing user: prefer the caller-supplied user, fall back to any local user
        if (!$asUser) {
            $asUser = self::getSigningUser();
        }

        try {
            $headers = [
                'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            ];

            // Use a signed GET when we have a user with keys (required for authorized fetch)
            if ($asUser && $asUser->getPrivateKey()) {
                $keyId = $asUser->getPublicKey()['id'];
                $signedHeaders = HTTPSignature::signGet($asUser->getPrivateKey(), $keyId, $actorUri);
                // signGet includes its own Accept header; replace it with our more comprehensive one
                $signedHeaders = array_filter($signedHeaders, function ($h) {
                    return stripos($h, 'Accept:') !== 0;
                });
                $headers = array_merge($signedHeaders, $headers);
            }

            $response = Webservice::get($actorUri, null, $headers);

            if (empty($response['content']) || $response['response'] < 200 || $response['response'] >= 300) {
                \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Failed to fetch actor ' . $actorUri . ' (HTTP ' . ($response['response'] ?? 'unknown') . ')');
                return false;
            }

            $data = json_decode($response['content'], true);
            if (empty($data) || empty($data['id'])) {
                \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Invalid actor document from ' . $actorUri);
                return false;
            }

            // Extract shared inbox from endpoints
            $sharedInbox = null;
            if (!empty($data['endpoints']['sharedInbox'])) {
                $sharedInbox = $data['endpoints']['sharedInbox'];
            }

            // Extract icon URL
            $icon = null;
            if (!empty($data['icon'])) {
                if (is_string($data['icon'])) {
                    $icon = $data['icon'];
                } elseif (is_array($data['icon']) && !empty($data['icon']['url'])) {
                    $icon = $data['icon']['url'];
                }
            }

            // Build preferred handle
            $handle = $data['preferredUsername'] ?? '';
            $parsedUri = parse_url($data['id']);
            if ($handle && !empty($parsedUri['host'])) {
                $handle = $handle . '@' . $parsedUri['host'];
            }

            return [
                'id'            => $data['id'],
                'inbox'         => $data['inbox'] ?? null,
                'sharedInbox'   => $sharedInbox,
                'outbox'        => $data['outbox'] ?? null,
                'preferredUsername' => $data['preferredUsername'] ?? null,
                'name'          => $data['name'] ?? $data['preferredUsername'] ?? null,
                'handle'        => $handle,
                'icon'          => $icon,
                'summary'       => $data['summary'] ?? null,
                'url'           => $data['url'] ?? $data['id'],
                'publicKey'     => $data['publicKey'] ?? null,
                'followers'     => $data['followers'] ?? null,
                'following'     => $data['following'] ?? null,
            ];

        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: Exception fetching actor ' . $actorUri . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch the public key for a remote actor given a key ID.
     * The key ID is typically in the format "actorUri#main-key".
     *
     * @param string    $keyId  The public key ID from the Signature header
     * @param User|null $asUser Optional local user whose key signs the request
     * @return string|false PEM-encoded public key, or false on failure
     */
    public static function fetchPublicKey(string $keyId, ?User $asUser = null)
    {
        // Strip the fragment to get the actor URI
        $actorUri = preg_replace('/#.*$/', '', $keyId);

        $actorData = self::fetch($actorUri, $asUser);
        if (!$actorData || empty($actorData['publicKey'])) {
            return false;
        }

        $publicKey = $actorData['publicKey'];

        // Verify the key ID matches
        if (!empty($publicKey['id']) && $publicKey['id'] !== $keyId) {
            // Key ID mismatch - the actor may have rotated keys.
            // Still return the key if the owner matches.
            if (empty($publicKey['owner']) || $publicKey['owner'] !== $actorUri) {
                \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Key owner mismatch for ' . $keyId);
                return false;
            }
        }

        return $publicKey['publicKeyPem'] ?? false;
    }

    /**
     * Get any local user with a keypair for signing outbound requests.
     *
     * @return User|null
     */
    private static function getSigningUser(): ?User
    {
        // Prefer the currently logged-in user
        if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
            return \Idno\Core\Idno::site()->session()->currentUser();
        }

        // Fall back to the first user with keys
        $users = User::get([], [], 1);
        if (!empty($users) && is_array($users)) {
            $user = $users[0];
            // Ensure the user has a keypair (generates one if needed)
            if ($user instanceof User && $user->getPrivateKey()) {
                return $user;
            }
        }

        return null;
    }
}
