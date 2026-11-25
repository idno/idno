<?php

namespace IdnoPlugins\ActivityPub;

use Idno\Common\Plugin;
use Idno\Entities\User;
use IdnoPlugins\ActivityPub\Entities\ActivityPubFollower;

/**
 * ActivityPub federation plugin for Known.
 *
 * Enables outbound ActivityPub federation:
 * - Profiles can be followed from Mastodon and other compatible platforms
 * - Published content is delivered to followers' inboxes
 * - Deletions and updates propagate to followers
 * - Incoming Follow/Undo/Delete activities are handled synchronously
 * - Outgoing deliveries are handled via the async queue
 */
class Main extends Plugin
{

    function registerTranslations()
    {
        // Future: register translation files
    }

    function registerPages()
    {
        // A private site does not federate: every one of these addresses answers 404 there
        // (see Pages\NotFederating). Which it is is decided on each request.
        $federates = self::federates();
        $routes = [
            // Per-user endpoints. Sub-path routes must be registered before the actor profile route
            '/actor/([^\/]+)/inbox/?'      => 'Inbox',
            '/actor/([^\/]+)/outbox/?'     => 'Outbox',
            '/actor/([^\/]+)/followers/?'  => 'Followers',
            '/actor/([^\/]+)/following/?'  => 'Following',
            '/actor/([^\/]+)/?'            => 'Actor',
            // Shared inbox
            '/inbox/?'                     => 'SharedInbox',
            // Quote authorization stamp endpoint (FEP-044f): the quoted post and the quoting post
            // as base64url, and an HMAC. The second route is the address stamps had before.
            '/activitypub/quote-stamp/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/([0-9a-f]+)' => 'QuoteStamp',
            '/activitypub/quote-stamp/?'   => 'QuoteStamp',
            // NodeInfo (public discovery endpoints)
            '/.well-known/nodeinfo/?'      => 'NodeInfoIndex',
            '/nodeinfo/2\\.0/?'             => 'NodeInfo',
        ];
        foreach ($routes as $pattern => $page) {
            // Marked public: other servers do not log in, and the gatekeeper of a private
            // site would answer them 403
            \Idno\Core\Idno::site()->routes()->addRoute($pattern, '\IdnoPlugins\ActivityPub\Pages\\' . ($federates ? $page : 'NotFederating'), true);
        }

        // Admin
        \Idno\Core\Idno::site()->routes()->addRoute('/admin/activitypub/?', '\IdnoPlugins\ActivityPub\Pages\Admin');

        // Admin menu item
        \Idno\Core\Idno::site()->template()->extendTemplate('admin/menu/items', 'activitypub/admin/menu');
    }

    function registerEventHooks()
    {

        // -------------------------------------------------------
        // Require asynchronous queue for ActivityPub federation
        // -------------------------------------------------------
        $queue = \Idno\Core\Idno::site()->queue();
        if (!($queue instanceof \Idno\Core\AsynchronousQueue)) {
            \Idno\Core\Idno::site()->logging()->warning(
                'ActivityPub requires the asynchronous event queue. Federation is disabled.'
            );
        }

        // The inboxes take POSTs that have no form token. Pages\Inbox and Pages\SharedInbox
        // see to that themselves, by overriding post(). Nothing here may mark a request as an
        // API request: that turns off the form token check for whichever page the request
        // reaches, and ends the session of a logged-in visitor.

        // -------------------------------------------------------
        // Eager key generation for new users
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('saved', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            if ($object instanceof User && empty($object->publicKeyPem)) {
                $object->generateKeyPair();
                \Idno\Core\Idno::site()->logging()->debug('ActivityPub: Generated key pair for new user ' . $object->getHandle());
            }
        });

        // -------------------------------------------------------
        // Deliver Create activity when a new entity is first saved
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('saved', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            // Skip non-content entities
            if ($object instanceof User) return;
            if ($object instanceof ActivityPubFollower) return;
            if ($object instanceof \Idno\Entities\AsynchronousQueuedEvent) return;

            // Only federate public, published content
            if (!$object->isPublic()) return;
            if ($object->getPublishStatus() !== 'published') return;

            // Must have a real content type
            $type = $object->getActivityStreamsObjectType();
            if (empty($type) || $type === 'entity') return;

            $user = $object->getOwner();
            if (!$user || !($user instanceof User)) return;

            try {
                $activity = ActivityBuilder::buildCreate($object);
                Delivery::deliverToFollowers($user, $activity);
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error('ActivityPub: Error building Create activity: ' . $e->getMessage());
            }
        });

        // -------------------------------------------------------
        // Deliver Update activity when an existing entity is updated
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('updated', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            if ($object instanceof User) return;
            if ($object instanceof ActivityPubFollower) return;
            if ($object instanceof \Idno\Entities\AsynchronousQueuedEvent) return;

            if (!$object->isPublic()) return;
            if ($object->getPublishStatus() !== 'published') return;

            $type = $object->getActivityStreamsObjectType();
            if (empty($type) || $type === 'entity') return;

            $user = $object->getOwner();
            if (!$user || !($user instanceof User)) return;

            try {
                $activity = ActivityBuilder::buildUpdate($object);
                Delivery::deliverToFollowers($user, $activity);
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error('ActivityPub: Error building Update activity: ' . $e->getMessage());
            }
        });

        // -------------------------------------------------------
        // Deliver Delete activity when an entity is about to be deleted
        // (We hook 'delete' not 'deleted' so we still have entity data)
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('delete', function (\Idno\Core\Event $event) {

            $eventdata = $event->data();
            $object = $eventdata['object'];

            if ($object instanceof User) return;
            if ($object instanceof ActivityPubFollower) return;
            if ($object instanceof \Idno\Entities\AsynchronousQueuedEvent) return;

            // A draft was never sent to anyone, so there is nothing to take back
            if (!self::shouldFederateDeletion($object)) return;

            $type = $object->getActivityStreamsObjectType();
            if (empty($type) || $type === 'entity') return;

            $user = $object->getOwner();
            if (!$user || !($user instanceof User)) return;

            try {
                $activity = ActivityBuilder::buildDelete($object);
                Delivery::deliverToFollowers($user, $activity);
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error('ActivityPub: Error building Delete activity: ' . $e->getMessage());
            }
        });

        // -------------------------------------------------------
        // Moderation: a post that the moderators take down is deleted on other
        // servers, and announced again if it is put back. (Saving a post that is
        // hidden delivers nothing: see the 'updated' listener above.) Other
        // servers may not show a post again once they have deleted it.
        // -------------------------------------------------------
        foreach (['moderation/takedown' => 'buildDelete', 'moderation/restore' => 'buildCreate'] as $moderationEvent => $builder) {
            \Idno\Core\Idno::site()->events()->addListener($moderationEvent, function (\Idno\Core\Event $event) use ($builder) {

                $object = $event->data()['object'] ?? null;
                if (!($object instanceof \Idno\Common\Entity) || $object instanceof User) return;
                if (!$object->isPublic()) return;

                $type = $object->getActivityStreamsObjectType();
                if (empty($type) || $type === 'entity') return;

                $user = $object->getOwner();
                if (!$user || !($user instanceof User)) return;

                try {
                    Delivery::deliverToFollowers($user, ActivityBuilder::$builder($object));
                } catch (\Exception $e) {
                    \Idno\Core\Idno::site()->logging()->error('ActivityPub: Error building moderation activity: ' . $e->getMessage());
                }
            });
        }

        // -------------------------------------------------------
        // Handle queued delivery (dispatched by the async queue)
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('activitypub/deliver', function (\Idno\Core\Event $event) {

            $data = $event->data();

            if (empty($data['user_uuid']) || empty($data['activity']) || empty($data['inbox'])) {
                \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Incomplete delivery event data');
                return;
            }

            $user = User::getByUUID($data['user_uuid']);
            if (!$user) {
                \Idno\Core\Idno::site()->logging()->warning('ActivityPub: User not found for delivery: ' . $data['user_uuid']);
                return;
            }

            Delivery::deliver($user, $data['activity'], $data['inbox']);
        });
    }

    /**
     * Does this site federate? Not when it is private (the "walled_garden" setting): nothing
     * its members post may leave it. Then there are no actors, outboxes, collections, inboxes
     * or NodeInfo, no Follow is accepted and nothing is delivered. This is asked on every
     * request and every delivery, so making the site private or public again takes effect at
     * once, with nothing saved again.
     *
     * @return bool
     */
    public static function federates(): bool
    {
        return (bool) \Idno\Core\Idno::site()->config()->isPublicSite();
    }

    /**
     * Should followers be told that this post was deleted? Not when it was never published:
     * a post whose publish status is set and is not "published" (a draft, for example) has
     * not been delivered to anyone. A post with no publish status counts as published.
     *
     * @param \Idno\Common\Entity $object
     * @return bool
     */
    public static function shouldFederateDeletion(\Idno\Common\Entity $object): bool
    {
        $status = $object->getPublishStatus();

        return empty($status) || $status === 'published';
    }

    function getAdminIcon()
    {
        return 'globe';
    }
}
