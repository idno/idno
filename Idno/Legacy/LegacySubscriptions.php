<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Webservice;
use Idno\Entities\User;
use Idno\Http\Controller\WebSub\PendingList;
use Idno\Http\Controller\WebSub\Subscriptions;
use Idno\Storage\RecordStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * What the WebSub callback asks the site, over the legacy application: the finders, the user's stored pending list, the event dispatcher and the logger.
 */
final class LegacySubscriptions implements Subscriptions
{
    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application
     * @param \Closure(): RecordStore $records the record store, fetched when a list is saved
     */
    public function __construct(
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
        #[AutowireServiceClosure(RecordStore::class)]
        private readonly \Closure $records,
    )
    {
    }

    public function user(string $id): ?User
    {
        $entity = Entity::getByID($id);

        return $entity instanceof User ? $entity : null;
    }

    public function entity(string $id): ?Entity
    {
        $entity = Entity::getByID($id);

        return $entity instanceof Entity ? $entity : null;
    }

    public function topic(Entity $subscription): string
    {
        $topic = $subscription->pubsub_self;

        return is_string($topic) ? $topic : '';
    }

    public function isSubscribed(User $subscriber, User $subscription): bool
    {
        $hub = $subscription->pubsub_hub;

        return $subscriber->isFollowing($subscription) && is_string($hub) && $hub !== '';
    }

    public function secret(User $subscriber, User $subscription): string
    {
        $secrets = $subscriber->pubsub_secrets;
        $secret = is_array($secrets) ? ($secrets[$subscription->getID()] ?? null) : null;

        return is_string($secret) ? $secret : '';
    }

    public function now(): int
    {
        return time();
    }

    public function pending(User $subscriber): \stdClass
    {
        return PendingList::read($subscriber->pubsub_pending);
    }

    /**
     * Stores the list. The member's record is read again and written with the one field changed, not the whole member as this request loaded it: a profile
     * edit made while the hub was verifying is not written over. (The follow request that is still running holds its own copy of the member and can save its
     * list after this one: the same whole-record hazard every save of a member has.)
     */
    public function savePending(User $subscriber, \stdClass $pending): void
    {
        $stored = serialize($pending);
        $subscriber->pubsub_pending = $stored;
        $records = ($this->records)();
        $record = $records->findById('entities', (string) $subscriber->getID());
        if (is_array($record)) {
            $record['pubsub_pending'] = $stored;
            $records->save('entities', $record);

            return;
        }
        // The hub is no member: the list is the site's own bookkeeping
        $subscriber->save(true);
    }

    public function trigger(string $event, array $data): void
    {
        Idno::site()->events()->triggerEvent($event, $data);
    }

    public function maxBody(): int
    {
        $limit = (int) (Idno::site()->config()->webservice_max_download ?? 0);

        return $limit > 0 ? $limit : Webservice::DEFAULT_MAX_DOWNLOAD;
    }

    public function log(string $message): void
    {
        ($this->logger)()->debug($message);
    }
}
