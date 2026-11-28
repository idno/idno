<?php

namespace Idno\Core;

/**
 * Execute events asynchronously, using database as queue store.
 * In the future this may be replaced by a more flexible messaging / queue service
 */
class AsynchronousQueue extends EventQueue
{
    function registerPages()
    {
        \Idno\Core\Idno::site()->routes()->addRoute('/service/queue/list/?', '\Idno\Pages\Service\Queues\Queue');
        \Idno\Core\Idno::site()->routes()->addRoute('/service/queue/dispatch/([^\/]+)/?', '\Idno\Pages\Service\Queues\Dispatch');
        \Idno\Core\Idno::site()->routes()->addRoute('/service/queue/gc/?', '\Idno\Pages\Service\Queues\GC');
    }

    function enqueue($queueName, $eventName, array $eventData, $runAsUser = null)
    {
        if (empty($queueName)) {
            $queueName = 'default';
        }

        $queuedEvent = new \Idno\Entities\AsynchronousQueuedEvent();
        $queuedEvent->queue = $queueName;
        $queuedEvent->event = $eventName;
        $queuedEvent->eventData = serialize($eventData);
        // Use explicit user context if provided (needed for unauthenticated
        // callers like ActivityPub inbox handlers), otherwise fall back to
        // the currently logged-in user.
        // False runs the event as nobody, whoever is logged in (the removal of an account
        // must not depend on the administrator who started it still being there).
        $queuedEvent->runAsContext = $runAsUser === false ? '' : ($runAsUser ?: \Idno\Core\Idno::site()->session()->currentUserUUID());
        $queuedEvent->complete = false;
        $queuedEvent->queuedTs = time();

        // Use save(true) to bypass canEdit() — queue events are system-level
        // entities and must be saveable even when no user is logged in (e.g.
        // federation inbox requests from remote servers).
        $result = $queuedEvent->save(true);

        if ($result) {
            \Idno\Core\Idno::site()->logging()->debug("Enqueued asynchronous event $eventName on queue $queueName");
        } else {
            \Idno\Core\Idno::site()->logging()->error("Failed to enqueue asynchronous event $eventName on queue $queueName");
        }

        return $result;
    }

    function isComplete($id)
    {
        $event = \Idno\Entities\AsynchronousQueuedEvent::getByID($id);
        if (!empty($event)) {
            return $event->complete;
        }

        return false;
    }

    function getResult($id)
    {
        $event = \Idno\Entities\AsynchronousQueuedEvent::getByID($id);
        if (!empty($event)) {
            return unserialize($event->result);
        }
    }

    /**
     * Dispatch event.
     *
     * @param \Idno\Entities\AsynchronousQueuedEvent $event
     */
    function dispatch(\Idno\Entities\AsynchronousQueuedEvent &$event)
    {

        if (empty($event)) {
            throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_('No event passed'));
        }

        if (!($event instanceof \Idno\Entities\AsynchronousQueuedEvent)) {
            throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_('Event passed is not a queued event, and so can\'t be dispatched'));
        }

        if ($event->complete) {
            throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_('Sorry, this event has already been dispatched (but not yet cleaned up)'));
        }

        try {

            $username = "ANONYMOUS";

            if (!empty($event->runAsContext)) {
                $user = \Idno\Entities\User::getByUUID($event->runAsContext);
                if (empty($user)) {
                    throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_("Invalid user (%s) given for runAsContext, aborting", [$event->runAsContext]));
                }

                if ($user->isClosed()) {
                    // Queued as a member whose account has been closed since: dropped, not run as nobody
                    throw new \Idno\Exceptions\TechnicalException('The account this event was queued for is closed; the event is dropped');
                }

                \Idno\Core\Idno::site()->session()->logUserOn($user);

                $username = $user->getName();
            }

            \Idno\Core\Idno::site()->logging()->info("[".date('r')."] Dispatching event " . $event->getID() . ": {$event->event} as $username queued at " . date('r', $event->queuedTs));

            $result = \Idno\Core\Idno::site()->events()->triggerEvent($event->event, unserialize($event->eventData));

            $event->result = serialize($result);

        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error($e->getMessage());
            $event->error = $e->getMessage();
        }

        $event->complete = true;
        $event->completedTs = time();

        // Use save(true) to bypass canEdit() — queue events are system-level
        // entities.  When runAsContext is empty (e.g. events queued from an
        // unauthenticated federation request) no user is logged in, so a
        // normal save() would fail the canEdit() check and silently drop the
        // "complete" flag, causing the event to be re-dispatched forever.
        $saved = $event->save(true);

        \Idno\Core\Idno::site()->session()->logUserOff();

        return $saved;
    }

    /**
     * Garbage collect old completed event
     */
    function gc($timeago = 300, $queue = null)
    {

        \Idno\Core\Idno::site()->logging()->debug("[".date('r')."] Garbage collecting...");

        $search = [
            'completedTs' => [
                '$lt' => time() - $timeago
            ],
            'complete' => true,
        ];

        if (!empty($queue)) {
            $search['queue'] = $queue;
        }

        if ($events = \Idno\Entities\AsynchronousQueuedEvent::get($search)) {

            foreach ($events as $event) {

                \Idno\Core\Idno::site()->logging()->debug("AsynchronousQueue::gc($timeago) removing " . $event->getID() . " - {$event->event} in queue {$event->queue}, completed " . date('r', $event->completedTs));
                $event->delete();
            }
        }
    }

}
