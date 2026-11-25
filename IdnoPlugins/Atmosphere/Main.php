<?php

namespace IdnoPlugins\Atmosphere;

use Idno\Common\Plugin;
use Idno\Entities\User;

/**
 * Atmosphere plugin for Idno (it was called StandardSiteSync until it wrote more than
 * standard.site documents).
 *
 * Writes a member's posts to their own AT Protocol PDS: OAuth authentication,
 * automatic sync of new, edited and deleted posts, and one-click backfill of all
 * existing posts.
 *
 * Site admins enable the feature site-wide; each user connects their own
 * PDS account and manages sync from their account settings.
 */
class Main extends Plugin
{
    /** @var array Entity IDs currently being synced (re-entrancy guard) */
    private static $syncingEntities = [];

    public function registerTranslations()
    {
    }

    public function registerPages()
    {
        // Admin settings (site-wide enable/disable)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/admin/atmosphere/?',
            '\IdnoPlugins\Atmosphere\Pages\Admin'
        );

        // User account settings (per-user PDS connection)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/atmosphere/?',
            '\IdnoPlugins\Atmosphere\Pages\UserSettings'
        );

        // OAuth callback (per-user)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/atmosphere/callback/?',
            '\IdnoPlugins\Atmosphere\Pages\OAuthCallback'
        );

        // Disconnect (per-user)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/atmosphere/disconnect/?',
            '\IdnoPlugins\Atmosphere\Pages\Disconnect'
        );

        // Backfill (per-user)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/atmosphere/backfill/?',
            '\IdnoPlugins\Atmosphere\Pages\Backfill'
        );

        // Client metadata endpoint (public, required by AT Protocol OAuth)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/atmosphere/client-metadata\\.json',
            '\IdnoPlugins\Atmosphere\Pages\ClientMetadata',
            true
        );

        // .well-known/site.standard.publication endpoint (public)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/.well-known/site\\.standard\\.publication',
            '\IdnoPlugins\Atmosphere\Pages\WellKnownPublication',
            true
        );

        // Admin menu item
        \Idno\Core\Idno::site()->template()->extendTemplate(
            'admin/menu/items',
            'atmosphere/admin/menu'
        );

        // Account settings menu item
        \Idno\Core\Idno::site()->template()->extendTemplate(
            'account/menu/items',
            'atmosphere/account/menu'
        );

        // Add <link rel="site.standard.document"> to entity permalink pages
        \Idno\Core\Idno::site()->template()->extendTemplate(
            'shell/head',
            'atmosphere/head'
        );
    }

    public function registerEventHooks()
    {
        $events = \Idno\Core\Idno::site()->events();

        // A post was saved for the first time, or changed: write it to the PDS, or take
        // it away from there when it is no longer public and published (via the queue)
        $saved = function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if ($action = self::actionFor($object)) {
                \Idno\Core\Idno::site()->queue()->enqueue('default', 'atmosphere/' . $action, ['object' => $object]);
            }
        };
        $events->addListener('saved', $saved);
        $events->addListener('updated', $saved);

        // A post is being deleted: delete its record (via the queue)
        $events->addListener('delete', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if (self::isContent($object) && self::isEnabled() && (self::shouldSync($object) || self::hasRemoteRecord($object)) && self::sessionFor($object)) {
                \Idno\Core\Idno::site()->queue()->enqueue('default', 'atmosphere/delete', ['object' => $object]);
            }
        });

        // Queued: backfill one post
        $events->addListener('atmosphere/backfill', function (\Idno\Core\Event $event) {
            $data = $event->data();
            if (empty($data['entity_id'])) {
                \Idno\Core\Idno::site()->logging()->warning('Atmosphere: Backfill event missing entity_id');
                return;
            }
            $entity = \Idno\Common\Entity::getByID($data['entity_id']);
            if (!$entity) {
                \Idno\Core\Idno::site()->logging()->warning('Atmosphere: Entity not found for backfill: ' . $data['entity_id']);
                return;
            }
            if (self::isEnabled() && self::shouldSync($entity)) {
                self::syncEntity($entity, true);
            }
        });

        // Queued: write one post
        $events->addListener('atmosphere/sync', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if (!($object instanceof \Idno\Common\Entity)) {
                \Idno\Core\Idno::site()->logging()->warning('Atmosphere: Sync event missing valid object');
                return;
            }
            self::syncQueued($object);
        });

        // Queued: take one post away that is no longer public and published
        $events->addListener('atmosphere/unsync', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if ($object instanceof \Idno\Common\Entity) {
                self::syncQueued($object);
            }
        });

        // Queued: delete the record of a post that has been deleted
        $events->addListener('atmosphere/delete', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if (!($object instanceof \Idno\Common\Entity)) {
                \Idno\Core\Idno::site()->logging()->warning('Atmosphere: Delete event missing valid object');
                return;
            }
            self::removeEntity($object, false);
        });
    }

    /**
     * What saving an entity asks of this plugin.
     *
     * @param  mixed $object The entity that was saved
     * @return string|null "sync" (write it to the owner's PDS), "unsync" (it has a record there and is
     *                     no longer public and published: take it away) or null (nothing)
     */
    public static function actionFor($object): ?string
    {
        if (!self::isContent($object) || !self::isEnabled()) {
            return null;
        }
        // Writing a post saves its record's address on the entity, which is not a change to write
        if (in_array($object->getID(), self::$syncingEntities)) {
            return null;
        }
        if (!self::sessionFor($object)) {
            return null;
        }
        if (self::shouldSync($object)) {
            return 'sync';
        }

        return self::hasRemoteRecord($object) ? 'unsync' : null;
    }

    /**
     * Whether an object is a post: an entity with a content type.
     *
     * @param  mixed $object
     * @return bool
     */
    private static function isContent($object): bool
    {
        if (!($object instanceof \Idno\Common\Entity) || $object instanceof User || $object instanceof \Idno\Entities\AsynchronousQueuedEvent) {
            return false;
        }
        $type = $object->getActivityStreamsObjectType();

        return !empty($type) && $type !== 'entity';
    }

    /**
     * Whether an administrator has turned syncing on for the site.
     */
    public static function isEnabled(): bool
    {
        $config = \Idno\Core\Idno::site()->config();

        // A site that turned syncing on when the plugin was called StandardSiteSync still has it on
        $enabled = $config->atmosphere_enabled;

        return !empty($enabled !== null ? $enabled : $config->standardsitesync_enabled);
    }

    /**
     * Whether a post may be on the PDS: only one that is public and published. A draft,
     * a private post or a post for members only is never written there.
     *
     * @param  mixed $object
     * @return bool
     */
    public static function shouldSync($object): bool
    {
        if (!self::isContent($object)) {
            return false;
        }
        if (!$object->isPublic()) {
            return false;
        }

        return $object->getPublishStatus() === 'published';
    }

    /**
     * Whether a record has been written to a PDS for an entity (a post or a document).
     *
     * @param  mixed $object
     * @return bool
     */
    public static function hasRemoteRecord($object): bool
    {
        return $object instanceof \Idno\Common\Entity
            && (FeedSync::hasRecord($object) || PostSync::hasRecord($object) || EpisodeSync::hasRecord($object) || !empty($object->standardsitesync_uri));
    }

    /**
     * The owner of an entity, when they have connected an account.
     *
     * @return User|null
     */
    private static function sessionFor(\Idno\Common\Entity $entity): ?User
    {
        $user = $entity->getOwner();

        return ($user instanceof User && !empty(self::getATProtoSessionForUser($user))) ? $user : null;
    }

    /**
     * Handle a queued write. The queue may run long after the post was saved, and with a
     * copy of it made then, so the post is read again: one that has been deleted or
     * hidden since is not written, and one that is no longer public is taken away.
     */
    private static function syncQueued(\Idno\Common\Entity $object): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $entity = \Idno\Common\Entity::getByID($object->getID());
        if (!$entity) {
            // Deleted since, or hidden from whoever runs the queue. A post that was to be taken
            // away still is, from the copy the queue was handed; nothing is ever written from it.
            if (!self::shouldSync($object)) {
                self::removeEntity($object, false);
            }

            return;
        }
        $changed = self::shouldSync($entity) ? self::syncEntity($entity) : self::removeEntity($entity, true);

        // The request that saved the post may save its own copy again: keep that copy in step
        if ($changed && $entity !== $object) {
            foreach (array_merge(FeedSync::FIELDS, PostSync::FIELDS, EpisodeSync::FIELDS, ['standardsitesync_uri', 'posse']) as $field) {
                $object->$field = $entity->$field;
            }
        }
    }

    /**
     * Write a single entity to its owner's PDS: a status update, a video or an audio
     * post as a record of Idno's own lexicons (see FeedRecord), an audio post as a podcast
     * episode too (see EpisodeRecord), and, for a member who has asked for it, each of them
     * as a Bluesky post (see PostRecord); anything else as a standard.site document.
     * Nothing that goes wrong is passed on: it is logged, without what the server
     * answered and without any token, and noted for the member's settings page.
     *
     * @param  \Idno\Common\Entity $entity       A public, published post
     * @param  bool                $skipExisting If true, leave a post alone that already has its record
     * @return bool Whether the entity was changed and saved
     */
    public static function syncEntity(\Idno\Common\Entity $entity, bool $skipExisting = false): bool
    {
        $entityId = $entity->getID();
        $user = self::sessionFor($entity);
        if (!$user) {
            return false;
        }

        try {
            $client = new ATProtoClient(self::getATProtoSessionForUser($user));

            if (PostSync::handles($entity)) {
                $bluesky = self::postsToBluesky($user);
                $audio = EpisodeSync::handles($entity);
                if (
                    $skipExisting && FeedSync::hasRecord($entity)
                    && (!$audio || EpisodeSync::hasRecord($entity))
                    && (!$bluesky || PostSync::hasRecord($entity))
                ) {
                    return false;
                }
                $changes = [PostSync::CREATED, PostSync::UPDATED, PostSync::GONE];
                $changed = false;

                // Only for a member who asked for it: a Bluesky post, which their followers there see
                if ($bluesky && in_array((new PostSync($client))->sync($entity), $changes, true)) {
                    $changed = true;
                }

                // The record of the post itself, in Idno's own lexicons, which Bluesky does not show
                $outcome = (new FeedSync($client))->sync($entity);
                $changed = $changed || in_array($outcome, $changes, true);

                // An audio post is also an episode of the member's podcast series
                if ($audio && in_array((new EpisodeSync($client))->sync($entity, $user), $changes, true)) {
                    $changed = true;
                }

                // Earlier versions wrote these posts as standard.site documents: that record is replaced
                if ($outcome !== PostSync::SKIPPED && !empty($entity->standardsitesync_uri)) {
                    $client->deleteDocument($entity);
                    $entity->standardsitesync_uri = null;
                    $changed = true;
                }
                self::saveAfterSuccess($user, $client);
                if ($changed) {
                    self::saveQuietly($entity);
                }
                \Idno\Core\Idno::site()->logging()->debug('Atmosphere: Post for entity ' . $entityId . ': ' . $outcome);

                return $changed;
            }

            if ($skipExisting && $client->documentExists($entity)) {
                \Idno\Core\Idno::site()->logging()->debug('Atmosphere: Skipping already-synced entity ' . $entityId);
                self::saveAfterSuccess($user, $client);

                return false;
            }

            $result = $client->putDocument($entity);
            self::saveAfterSuccess($user, $client);

            // Store the AT-URI on the entity so we can emit a <link> tag.
            $changed = false;
            if (!empty($result['uri']) && ($entity->standardsitesync_uri ?? null) !== $result['uri']) {
                $entity->standardsitesync_uri = $result['uri'];
                self::saveQuietly($entity);
                $changed = true;
            }
            \Idno\Core\Idno::site()->logging()->debug('Atmosphere: Synced entity ' . $entityId . ' -> ' . ($result['uri'] ?? 'unknown'));

            return $changed;
        } catch (\Exception $e) {
            self::logFailure('Error syncing entity ' . $entityId, $e, $user, $client ?? null);

            return false;
        }
    }

    /**
     * Delete the record of an entity from its owner's PDS.
     *
     * @param  \Idno\Common\Entity $entity
     * @param  bool                $save Whether the entity still exists and is to be saved without its record
     * @return bool Whether the entity was changed (and saved, if asked)
     */
    public static function removeEntity(\Idno\Common\Entity $entity, bool $save): bool
    {
        $user = self::sessionFor($entity);
        if (!$user || (PostSync::handles($entity) && !self::hasRemoteRecord($entity))) {
            return false;
        }

        try {
            $client = new ATProtoClient(self::getATProtoSessionForUser($user));

            $changed = PostSync::handles($entity) && (new PostSync($client))->remove($entity);
            if ((new FeedSync($client))->remove($entity)) {
                $changed = true;
            }
            if ((new EpisodeSync($client))->remove($entity)) {
                $changed = true;
            }
            if (!PostSync::handles($entity) || !empty($entity->standardsitesync_uri)) {
                $client->deleteDocument($entity);
                if (!empty($entity->standardsitesync_uri)) {
                    $entity->standardsitesync_uri = null;
                    $changed = true;
                }
            }
            self::saveAfterSuccess($user, $client);
            if ($changed && $save) {
                self::saveQuietly($entity);
            }
            \Idno\Core\Idno::site()->logging()->debug('Atmosphere: Deleted record for entity ' . $entity->getID());

            return $changed;
        } catch (\Exception $e) {
            self::logFailure('Error deleting record for entity ' . $entity->getID(), $e, $user, $client ?? null);

            return false;
        }
    }

    /**
     * Save an entity without that save being taken for a change to the post.
     */
    private static function saveQuietly(\Idno\Common\Entity $entity): void
    {
        $entityId = $entity->getID();
        self::$syncingEntities[] = $entityId;
        try {
            $entity->save(true);
        } finally {
            self::$syncingEntities = array_diff(self::$syncingEntities, [$entityId]);
        }
    }

    /**
     * Log a failure. A refusal by the server is logged as its status and the name of the
     * error (XrpcException); anything else as its message, which this plugin wrote.
     */
    private static function logFailure(string $what, \Exception $e, User $user, ?ATProtoClient $client): void
    {
        \Idno\Core\Idno::site()->logging()->error('Atmosphere: ' . $what . ': ' . $e->getMessage());

        try {
            // Tell the member on their settings page, and keep what the client learned
            // (a refreshed token, a new nonce)
            $user->standardsitesync_failure = self::describeFailure($e) + ['at' => time()];
            if ($client) {
                self::saveATProtoSessionForUser(
                    $user,
                    $client->getSession()
                );
            } else {
                $user->save();
            }
        } catch (\Exception $ignored) {
            // Nothing more can be done here
        }
    }

    /**
     * What a failure means for the member: that their connection is no longer accepted
     * and they must connect again ("expired"), or that one attempt failed ("failed").
     * The detail is the HTTP status and the name of the error, never the server's answer.
     *
     * @param  \Exception $e
     * @return array 'kind' ("expired" or "failed") and 'detail'
     */
    public static function describeFailure(\Exception $e): array
    {
        $detail = '';
        $expired = in_array($e->getMessage(), ['No refresh token available', 'Not authenticated with AT Protocol PDS'], true);

        if ($e instanceof XrpcException) {
            $detail = trim($e->getStatus() . ' ' . $e->getErrorName());
            $expired = strpos($e->getMessage(), 'Token refresh failed') === 0
                || $e->getStatus() === 401
                || in_array(strtolower($e->getErrorName()), ['invalid_grant', 'invalid_token', 'invalidtoken', 'expiredtoken', 'authmissing'], true);
        }

        return ['kind' => $expired ? 'expired' : 'failed', 'detail' => $detail];
    }

    /**
     * The last failure to write to a member's PDS, if nothing has been written since.
     *
     * @return array|null 'kind' ("expired" or "failed"), 'detail' and 'at' (when, seconds since the UNIX epoch)
     */
    public static function getSyncFailure(User $user): ?array
    {
        $failure = $user->standardsitesync_failure;
        if (!is_array($failure) || !in_array($failure['kind'] ?? null, ['expired', 'failed'], true)) {
            return null;
        }

        return [
            'kind'   => $failure['kind'],
            'detail' => is_string($failure['detail'] ?? null) ? $failure['detail'] : '',
            'at'     => (int) ($failure['at'] ?? 0),
        ];
    }

    /**
     * Keep the session after something was written (or deleted) on the PDS; an earlier
     * failure is over.
     */
    private static function saveAfterSuccess(User $user, ATProtoClient $client): void
    {
        $user->standardsitesync_failure = null;
        self::saveATProtoSessionForUser(
            $user,
            $client->getSession()
        );
    }

    /**
     * Whether a member has asked for their status updates, videos and audio to be posted
     * to Bluesky as well. Nobody has unless they turned it on in their settings.
     */
    public static function postsToBluesky(User $user): bool
    {
        return !empty($user->standardsitesync_bluesky);
    }

    /**
     * Get the stored AT Protocol session for a specific user.
     */
    public static function getATProtoSessionForUser(User $user): array
    {
        return $user->standardsitesync_session ?? [];
    }

    /**
     * Save the AT Protocol session for a specific user.
     */
    public static function saveATProtoSessionForUser(User $user, array $session): void
    {
        $user->standardsitesync_session = $session;
        $user->save();
    }

    /**
     * Check if a user has an active AT Protocol connection.
     */
    public static function isUserConnected(User $user): bool
    {
        $session = self::getATProtoSessionForUser($user);
        return !empty($session['access_token']) && !empty($session['did']);
    }

    function getAdminIcon()
    {
        return 'refresh-cw';
    }
}
