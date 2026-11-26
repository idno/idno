<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Webservice;
use Idno\Entities\User;
use Idno\Http\Controller\WebSub\Subscriptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * What the WebSub callback asks the site, over the legacy application: the finders, the user's stored pending list, the event dispatcher and the logger.
 */
final class LegacySubscriptions implements Subscriptions
{
    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application
     */
    public function __construct(
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
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

    public function pending(User $subscriber): \stdClass
    {
        $stored = $subscriber->pubsub_pending;
        if (!is_string($stored) || $stored === '') {
            return new \stdClass();
        }
        $pending = @unserialize($stored, ['allowed_classes' => [\stdClass::class]]);

        return $pending instanceof \stdClass ? $pending : new \stdClass();
    }

    public function savePending(User $subscriber, \stdClass $pending): void
    {
        $subscriber->pubsub_pending = serialize($pending);
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
