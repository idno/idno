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

        // -------------------------------------------------------
        // Sync new content to PDS on save (via queue)
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('saved', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            // An import brings in history, not news: nothing it imports is synced
            if (\Idno\Core\Idno::announcementsSuppressed()) {
                return;
            }

            if (!$this->shouldSync($object)) {
                return;
            }

            // Prevent infinite loop: syncEntity saves the AT-URI back
            // on the entity, which triggers 'saved' again.
            if (in_array($object->getID(), self::$syncingEntities)) {
                return;
            }

            $user = $object->getOwner();
            if (!$user || !($user instanceof User)) {
                return;
            }

            if (empty($this->getATProtoSessionForUser($user))) {
                return;
            }

            \Idno\Core\Idno::site()->queue()->enqueue(
                'default',
                'standardsitesync/sync',
                ['object' => $object]
            );
        });

        // -------------------------------------------------------
        // Sync updates to PDS (via queue)
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('updated', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            // An import brings in history, not news: nothing it imports is synced
            if (\Idno\Core\Idno::announcementsSuppressed()) {
                return;
            }

            // A like, a reply or a webmention is no change of the post
            if (!empty($eventdata['annotations_only'])) {
                return;
            }

            if (!$this->shouldSync($object)) {
                return;
            }

            if (in_array($object->getID(), self::$syncingEntities)) {
                return;
            }

            $user = $object->getOwner();
            if (!$user || !($user instanceof User)) {
                return;
            }

            if (empty($this->getATProtoSessionForUser($user))) {
                return;
            }

            \Idno\Core\Idno::site()->queue()->enqueue(
                'default',
                'standardsitesync/sync',
                ['object' => $object]
            );
        });

        // -------------------------------------------------------
        // Delete from PDS when content is deleted (via queue)
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('delete', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            // The posts of an account that is being deleted are not removed from its PDS one by one
            if (\Idno\Core\Idno::announcementsSuppressed()) {
                return;
            }

            if (!$this->shouldSync($object)) {
                return;
            }

            $user = $object->getOwner();
            if (!$user || !($user instanceof User)) {
                return;
            }

            if (empty($this->getATProtoSessionForUser($user))) {
                return;
            }

            \Idno\Core\Idno::site()->queue()->enqueue(
                'default',
                'standardsitesync/delete',
                ['object' => $object]
            );
        });

        // -------------------------------------------------------
        // Handle queued backfill events
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener(
            'standardsitesync/backfill',
            function (\Idno\Core\Event $event) {

                $data = $event->data();

                // A private site writes nothing to a PDS, whenever the event was queued
                if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
                    return;
                }

                if (empty($data['entity_id'])) {
                    \Idno\Core\Idno::site()->logging()->warning(
                        'StandardSiteSync: Backfill event missing entity_id'
                    );
                    return;
                }

                $entity = \Idno\Common\Entity::getByID($data['entity_id']);
                if (!$entity) {
                    \Idno\Core\Idno::site()->logging()->warning(
                        'StandardSiteSync: Entity not found for backfill: ' . $data['entity_id']
                    );
                    return;
                }

                $user = $entity->getOwner();
                if (!$user || !($user instanceof User)) {
                    return;
                }

                $session = $this->getATProtoSessionForUser($user);
                if (empty($session)) {
                    \Idno\Core\Idno::site()->logging()->warning(
                        'StandardSiteSync: No AT Protocol session for backfill user'
                    );
                    return;
                }

                $this->syncEntity($entity, $session, $user, true);
            }
        );

        // -------------------------------------------------------
        // Handle queued sync events
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener(
            'standardsitesync/sync',
            function (\Idno\Core\Event $event) {

                $data = $event->data();

                // A private site writes nothing to a PDS, whenever the event was queued
                if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
                    return;
                }

                $object = $data['object'] ?? null;

                if (empty($object) || !($object instanceof \Idno\Common\Entity)) {
                    \Idno\Core\Idno::site()->logging()->warning(
                        'StandardSiteSync: Sync event missing valid object'
                    );
                    return;
                }

                $user = $object->getOwner();
                if (!$user || !($user instanceof User)) {
                    return;
                }

                $session = $this->getATProtoSessionForUser($user);
                if (empty($session)) {
                    return;
                }

                $this->syncEntity($object, $session, $user);
            }
        );

        // -------------------------------------------------------
        // Handle queued delete events
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener(
            'standardsitesync/delete',
            function (\Idno\Core\Event $event) {

                $data = $event->data();

                // A private site writes nothing to a PDS, whenever the event was queued
                if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
                    return;
                }

                $object = $data['object'] ?? null;

                if (empty($object) || !($object instanceof \Idno\Common\Entity)) {
                    \Idno\Core\Idno::site()->logging()->warning(
                        'StandardSiteSync: Delete event missing valid object'
                    );
                    return;
                }

                $user = $object->getOwner();
                if (!$user || !($user instanceof User)) {
                    return;
                }

                $session = $this->getATProtoSessionForUser($user);
                if (empty($session)) {
                    return;
                }

                try {
                    $client = new ATProtoClient($session);
                    $client->deleteDocument($object);
                    $this->saveATProtoSessionForUser($user, $client->getSession());
                    \Idno\Core\Idno::site()->logging()->debug(
                        'StandardSiteSync: Deleted document for entity ' . $object->getID()
                    );
                } catch (\Exception $e) {
                    \Idno\Core\Idno::site()->logging()->error(
                        'StandardSiteSync: Error deleting document: ' . $e->getMessage()
                    );
                }
            }
        );
    }

    /**
     * Determine whether an entity should be synced to the PDS.
     */
    private function shouldSync($object): bool
    {
        // Skip non-content entities
        if ($object instanceof User) {
            return false;
        }
        if ($object instanceof \Idno\Entities\AsynchronousQueuedEvent) {
            return false;
        }

        // Must be a content entity
        if (!($object instanceof \Idno\Common\Entity)) {
            return false;
        }

        // Nothing posted on a private site is public, whatever the post's own access says: it is written to no PDS
        if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
            return false;
        }

        // Only sync public, published content
        if (method_exists($object, 'isPublic') && !$object->isPublic()) {
            return false;
        }
        if (method_exists($object, 'getPublishStatus') && $object->getPublishStatus() !== 'published') {
            return false;
        }

        // Must have a real content type
        $type = $object->getActivityStreamsObjectType();
        if (empty($type) || $type === 'entity') {
            return false;
        }

        // Check if sync is enabled site-wide
        if (empty(\Idno\Core\Idno::site()->config()->standardsitesync_enabled)) {
            return false;
        }

        return true;
    }

    /**
     * Sync a single entity to the PDS.
     *
     * @param \Idno\Common\Entity $entity
     * @param array               $session      AT Protocol session data
     * @param User                $user         The entity owner
     * @param bool                $skipExisting If true, skip entities already synced
     */
    private function syncEntity(
        \Idno\Common\Entity $entity,
        array $session,
        User $user,
        bool $skipExisting = false
    ): void
    {
        // Nothing is written to a PDS from a private site, whoever asks
        if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
            return;
        }

        $entityId = $entity->getID();

        try {
            $client = new ATProtoClient($session);

            if ($skipExisting && $client->documentExists($entity)) {
                \Idno\Core\Idno::site()->logging()->debug(
                    'StandardSiteSync: Skipping already-synced entity ' . $entityId
                );
                $this->saveATProtoSessionForUser($user, $client->getSession());
                return;
            }

            $result = $client->putDocument($entity);
            $this->saveATProtoSessionForUser($user, $client->getSession());

            // Store the AT-URI on the entity so we can emit a <link> tag.
            // Use a re-entrancy guard so the save() here does not trigger
            // another sync via the 'saved' event listener.
            if (!empty($result['uri']) && ($entity->standardsitesync_uri ?? null) !== $result['uri']) {
                self::$syncingEntities[] = $entityId;
                try {
                    $entity->standardsitesync_uri = $result['uri'];
                    $entity->save();
                } finally {
                    self::$syncingEntities = array_diff(self::$syncingEntities, [$entityId]);
                }
            }

            \Idno\Core\Idno::site()->logging()->debug(
                'StandardSiteSync: Synced entity ' . $entityId . ' -> ' . ($result['uri'] ?? 'unknown')
            );
        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error(
                'StandardSiteSync: Error syncing entity ' . $entityId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * A session message with a value that is not fixed text in it. Session messages are printed as markup, so a value from a
     * remote server, the request or an exception is escaped; the text around it is the plugin's own.
     *
     * @param string $format    fixed text with one %s
     * @param string $untrusted the value for it
     */
    public static function sessionMessage(string $format, string $untrusted): string
    {
        return str_replace('%s', htmlspecialchars($untrusted, ENT_QUOTES), $format);
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
