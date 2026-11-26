<?php

/**
 * Event dispatcher class
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Core {

    /**
     * Event dispatcher implementation.
     */
    class EventDispatcher
    {

        /**
         * Events that were renamed: the earlier name => the name the event has now.
         *
         * Both names are one event. A listener added under the earlier name is added to the
         * event under its name of today, and triggering the earlier name (which code written
         * before the change does, and so does an event that was queued before it) triggers
         * that event. A listener added under both names is kept once, so it runs once.
         * The earlier names are deprecated.
         */
        const RENAMED = [
            'pubsubhubbub/ping' => 'websub/ping',
        ];

        /// Event dispatcher (currently symfony)
        private $dispatcher;

        /**
         * The name an event has now: the name given, unless that event was renamed.
         *
         * @param  string $event
         * @return string
         */
        static function currentName($event)
        {
            return self::RENAMED[$event] ?? $event;
        }

        public function __construct()
        {
            $this->dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        }

        /**
         * Tells the system that callable $listener wants to be notified when
         * event $event is triggered. $priority is an optional integer
         * that specifies order priority; the higher the number, the earlier
         * in the chain $listener will be notified.
         *
         * @param string   $event
         * @param callable $listener
         * @param int      $priority
         */
        function addListener($event, $listener, $priority = 0)
        {
            if (is_callable($listener)) {
                $name = self::currentName($event);
                if (in_array($name, self::RENAMED, true) && in_array($listener, $this->dispatcher->getListeners($name), true)) {
                    return;
                }
                $this->dispatcher->addListener($name, $listener, $priority);
            }
        }

        /**
         * Stops notifying $listener of $event (the callable that was given to addListener()).
         *
         * @param string   $event
         * @param callable $listener
         */
        function removeListener($event, $listener)
        {
            $this->dispatcher->removeListener(self::currentName($event), $listener);
        }

        /**
         * Shortcut to trigger an event: supply the event name and
         * (optionally) an array of data, and get a variable back.
         *
         * @param  string $eventName The name of the event to trigger
         * @param  array  $data      Data to pass to the event
         * @param  mixed  $default   Default response (if not forwarding)
         * @param  \Closure|null $keepResponse A rule for the response the event keeps (`Event::keepResponseWhen()`): the first answer it
         *                                     accepts stays, whatever a later listener sets
         * @return mixed
         */
        function triggerEvent(string $eventName, $data = array(), $default = true, ?\Closure $keepResponse = null)
        {
            $eventName = self::currentName($eventName);

            $stats = \Idno\Core\Idno::site()->statistics();
            if (!empty($stats)) {
                $stats->increment("event.$eventName");
            }

            $event = new Event($data);
            $event->setResponse($default);
            if ($keepResponse !== null) {
                $event->keepResponseWhen($keepResponse);
            }
            $event = $this->dispatcher->dispatch($event, $eventName);
            if (!$event->forward()) {
                return $event->response();
            } else {
                header('Location: ' . $event->forward());
                exit;
            }
        }

        /**
         * Low level event dispatcher for an already existing Event
         *
         * @param string           $eventName
         * @param \Idno\Core\Event $event
         */
        function dispatch(string $eventName, Event $event = null)
        {
            return $this->dispatcher->dispatch($event, self::currentName($eventName));
        }
    }
}

