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
        // A private site does not federate: every one of these addresses answers 404 there (see Pages\NotFederating).
        // Which it is is decided on each request. They are marked public: other servers do not log in, and the gatekeeper of a
        // private site would answer them 403.
        $federates = self::federates();
        $page = static fn (string $name): string => '\IdnoPlugins\ActivityPub\Pages\\' . ($federates ? $name : 'NotFederating');
        $routes = \Idno\Core\Idno::site()->routes();

        // Per-user endpoints. Sub-path routes must be registered before the actor profile route
        $routes->addRoute('/actor/([^\/]+)/inbox/?', $page('Inbox'), true);
        $routes->addRoute('/actor/([^\/]+)/outbox/?', $page('Outbox'), true);
        $routes->addRoute('/actor/([^\/]+)/followers/?', $page('Followers'), true);
        $routes->addRoute('/actor/([^\/]+)/following/?', $page('Following'), true);
        $routes->addRoute('/actor/([^\/]+)/?', $page('Actor'), true);

        // Shared inbox
        $routes->addRoute('/inbox/?', $page('SharedInbox'), true);

        // Quote authorization stamp endpoint (FEP-044f): the quoted post and the quoting post as
        // base64url, and an HMAC. The second route is the address stamps had before.
        $routes->addRoute('/activitypub/quote-stamp/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/([0-9a-f]+)', $page('QuoteStamp'), true);
        $routes->addRoute('/activitypub/quote-stamp/?', $page('QuoteStamp'), true);

        // NodeInfo (public discovery endpoints)
        $routes->addRoute('/.well-known/nodeinfo/?', $page('NodeInfoIndex'), true);
        $routes->addRoute('/nodeinfo/2\\.0/?', $page('NodeInfo'), true);

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
        if (!$queue->isAsynchronous()) {
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

            // An import brings in history, not news: nothing it imports is federated
            if (\Idno\Core\Idno::announcementsSuppressed()) return;

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

            // An import brings in history, not news: nothing it imports is federated
            if (\Idno\Core\Idno::announcementsSuppressed()) return;

            // A like, a reply or a webmention is no change of the post (the activity does not carry them): followers are not told of an Update
            if (!empty($eventdata['annotations_only'])) return;

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

            // The posts of an account that is being deleted are not announced one by one: its followers are sent one Delete of the actor
            if (\Idno\Core\Idno::announcementsSuppressed()) return;

            if ($object instanceof User) return;
            if ($object instanceof ActivityPubFollower) return;
            if ($object instanceof \Idno\Entities\AsynchronousQueuedEvent) return;

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
        // An account that is being deleted: tell the followers' servers once that the actor is gone
        // (the account's posts are deleted without an activity each; see Delivery::announceActorDeleted())
        // -------------------------------------------------------
        \Idno\Core\Idno::site()->events()->addListener('account/closed', function (\Idno\Core\Event $event) {

            $object = $event->data()['object'] ?? null;
            if (!$object instanceof User) return;

            try {
                Delivery::announceActorDeleted($object);
            } catch (\Throwable $e) {
                \Idno\Core\Idno::site()->logging()->error('ActivityPub: Error telling followers an account was deleted: ' . get_class($e));
            }
        });

        // A body that was signed when it was queued (the Delete of an actor): it needs no user, so it runs as nobody
        \Idno\Core\Idno::site()->events()->addListener('activitypub/deliver-signed', function (\Idno\Core\Event $event) {

            $data = $event->data();
            if (empty($data['inbox']) || empty($data['body']) || empty($data['headers']) || !is_array($data['headers'])) {
                \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Incomplete signed delivery event data');
                return;
            }

            Delivery::deliverSigned((string) $data['inbox'], (string) $data['body'], $data['headers']);
        });

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
     * Does this site federate? Not when it is private (the "walled_garden" setting): nothing its members post may leave it, and it does not say
     * who they are. Then there are no actors, outboxes, collections, inboxes or NodeInfo, no Follow is accepted and nothing is delivered. This is
     * asked on every request and every delivery, so making the site private or public again takes effect at once, with nothing saved again.
     */
    public static function federates(): bool
    {
        return \Idno\Core\Idno::site()->config()->isPublicSite();
    }

    function getAdminIcon()
    {
        return 'globe';
    }
}
