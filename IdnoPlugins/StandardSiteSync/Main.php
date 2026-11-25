<?php

namespace IdnoPlugins\StandardSiteSync;

use Idno\Common\Plugin;
use Idno\Entities\User;

/**
 * StandardSiteSync plugin for Known.
 *
 * Syncs content to standard.site records on an AT Protocol PDS.
 * Supports OAuth authentication, automatic sync of new/edited content,
 * and one-click backfill of all existing posts.
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
            '/admin/standardsitesync/?',
            '\IdnoPlugins\StandardSiteSync\Pages\Admin'
        );

        // User account settings (per-user PDS connection)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/standardsitesync/?',
            '\IdnoPlugins\StandardSiteSync\Pages\UserSettings'
        );

        // OAuth callback (per-user)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/standardsitesync/callback/?',
            '\IdnoPlugins\StandardSiteSync\Pages\OAuthCallback'
        );

        // Disconnect (per-user)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/standardsitesync/disconnect/?',
            '\IdnoPlugins\StandardSiteSync\Pages\Disconnect'
        );

        // Backfill (per-user)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/account/settings/standardsitesync/backfill/?',
            '\IdnoPlugins\StandardSiteSync\Pages\Backfill'
        );

        // Client metadata endpoint (public, required by AT Protocol OAuth)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/standardsitesync/client-metadata\\.json',
            '\IdnoPlugins\StandardSiteSync\Pages\ClientMetadata',
            true
        );

        // .well-known/site.standard.publication endpoint (public)
        \Idno\Core\Idno::site()->routes()->addRoute(
            '/.well-known/site\\.standard\\.publication',
            '\IdnoPlugins\StandardSiteSync\Pages\WellKnownPublication',
            true
        );

        // Admin menu item
        \Idno\Core\Idno::site()->template()->extendTemplate(
            'admin/menu/items',
            'standardsitesync/admin/menu'
        );

        // Account settings menu item
        \Idno\Core\Idno::site()->template()->extendTemplate(
            'account/menu/items',
            'standardsitesync/account/menu'
        );

        // Add <link rel="site.standard.document"> to entity permalink pages
        \Idno\Core\Idno::site()->template()->extendTemplate(
            'shell/head',
            'standardsitesync/head'
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
                \Idno\Core\Idno::site()->queue()->enqueue('default', 'standardsitesync/' . $action, ['object' => $object]);
            }
        };
        $events->addListener('saved', $saved);
        $events->addListener('updated', $saved);

        // A post is being deleted: delete its record (via the queue)
        $events->addListener('delete', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if (self::isContent($object) && self::isEnabled() && (self::shouldSync($object) || self::hasRemoteRecord($object)) && self::sessionFor($object)) {
                \Idno\Core\Idno::site()->queue()->enqueue('default', 'standardsitesync/delete', ['object' => $object]);
            }
        });

        // Queued: backfill one post
        $events->addListener('standardsitesync/backfill', function (\Idno\Core\Event $event) {
            $data = $event->data();
            if (empty($data['entity_id'])) {
                \Idno\Core\Idno::site()->logging()->warning('StandardSiteSync: Backfill event missing entity_id');
                return;
            }
            $entity = \Idno\Common\Entity::getByID($data['entity_id']);
            if (!$entity) {
                \Idno\Core\Idno::site()->logging()->warning('StandardSiteSync: Entity not found for backfill: ' . $data['entity_id']);
                return;
            }
            if (self::isEnabled() && self::shouldSync($entity)) {
                self::syncEntity($entity, true);
            }
        });

        // Queued: write one post
        $events->addListener('standardsitesync/sync', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if (!($object instanceof \Idno\Common\Entity)) {
                \Idno\Core\Idno::site()->logging()->warning('StandardSiteSync: Sync event missing valid object');
                return;
            }
            self::syncQueued($object);
        });

        // Queued: take one post away that is no longer public and published
        $events->addListener('standardsitesync/unsync', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if ($object instanceof \Idno\Common\Entity) {
                self::syncQueued($object);
            }
        });

        // Queued: delete the record of a post that has been deleted
        $events->addListener('standardsitesync/delete', function (\Idno\Core\Event $event) {
            $object = $event->data()['object'] ?? null;
            if (!($object instanceof \Idno\Common\Entity)) {
                \Idno\Core\Idno::site()->logging()->warning('StandardSiteSync: Delete event missing valid object');
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
    private static function isEnabled(): bool
    {
        return !empty(\Idno\Core\Idno::site()->config()->standardsitesync_enabled);
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
            && (PostSync::hasRecord($object) || !empty($object->standardsitesync_uri));
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
            foreach (array_merge(PostSync::FIELDS, ['standardsitesync_uri', 'posse']) as $field) {
                $object->$field = $entity->$field;
            }
        }
    }

    /**
     * Write a single entity to its owner's PDS: a status update, a video or an audio
     * post as a Bluesky post (see PostRecord), anything else as a standard.site document.
     * Nothing that goes wrong is passed on: it is logged, without what the server
     * answered and without any token.
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
                if ($skipExisting && PostSync::hasRecord($entity)) {
                    return false;
                }
                $outcome = (new PostSync($client))->sync($entity);
                $changed = in_array($outcome, [PostSync::CREATED, PostSync::UPDATED, PostSync::GONE], true);

                // Earlier versions wrote these posts as standard.site documents: that record is replaced
                if ($outcome !== PostSync::SKIPPED && !empty($entity->standardsitesync_uri)) {
                    $client->deleteDocument($entity);
                    $entity->standardsitesync_uri = null;
                    $changed = true;
                }
                self::saveATProtoSessionForUser($user, $client->getSession());
                if ($changed) {
                    self::saveQuietly($entity);
                }
                \Idno\Core\Idno::site()->logging()->debug('StandardSiteSync: Post for entity ' . $entityId . ': ' . $outcome);

                return $changed;
            }

            if ($skipExisting && $client->documentExists($entity)) {
                \Idno\Core\Idno::site()->logging()->debug('StandardSiteSync: Skipping already-synced entity ' . $entityId);
                self::saveATProtoSessionForUser($user, $client->getSession());

                return false;
            }

            $result = $client->putDocument($entity);
            self::saveATProtoSessionForUser($user, $client->getSession());

            // Store the AT-URI on the entity so we can emit a <link> tag.
            $changed = false;
            if (!empty($result['uri']) && ($entity->standardsitesync_uri ?? null) !== $result['uri']) {
                $entity->standardsitesync_uri = $result['uri'];
                self::saveQuietly($entity);
                $changed = true;
            }
            \Idno\Core\Idno::site()->logging()->debug('StandardSiteSync: Synced entity ' . $entityId . ' -> ' . ($result['uri'] ?? 'unknown'));

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
            if (!PostSync::handles($entity) || !empty($entity->standardsitesync_uri)) {
                $client->deleteDocument($entity);
                if (!empty($entity->standardsitesync_uri)) {
                    $entity->standardsitesync_uri = null;
                    $changed = true;
                }
            }
            self::saveATProtoSessionForUser($user, $client->getSession());
            if ($changed && $save) {
                self::saveQuietly($entity);
            }
            \Idno\Core\Idno::site()->logging()->debug('StandardSiteSync: Deleted record for entity ' . $entity->getID());

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
        if ($client) {
            try {
                // Keep what the client learned (a refreshed token, a new nonce)
                self::saveATProtoSessionForUser($user, $client->getSession());
            } catch (\Exception $ignored) {
                // Nothing more can be done here
            }
        }
        \Idno\Core\Idno::site()->logging()->error('StandardSiteSync: ' . $what . ': ' . $e->getMessage());
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
