<?php

declare(strict_types=1);

namespace Idno\Queue;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Triggers a queued event as the user it was queued for. A user that no longer exists is an error
 * and nothing is triggered. Whatever is thrown (\Error included) is thrown on, so that Messenger
 * moves the message to the failure transport; nobody is left logged on either way.
 */
#[AsMessageHandler]
final class QueuedEventHandler
{
    public function __construct(
        private readonly EventTrigger $events,
        private readonly UserContext $users,
    )
    {
    }

    public function __invoke(QueuedEvent $message): mixed
    {
        return $this->users->runAs($message->runAs, fn (): mixed => $this->events->trigger($message->event, $message->data));
    }
}
