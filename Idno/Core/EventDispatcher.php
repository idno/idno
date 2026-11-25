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

        /// Event dispatcher (currently symfony)
        private $dispatcher;

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
                $this->dispatcher->addListener($event, $listener, $priority);
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
            $this->dispatcher->removeListener($event, $listener);
        }

        /**
         * Shortcut to trigger an event: supply the event name and
         * (optionally) an array of data, and get a variable back.
         *
         * @param  string $eventName The name of the event to trigger
         * @param  array  $data      Data to pass to the event
         * @param  mixed  $default   Default response (if not forwarding)
         * @return mixed
         */
        function triggerEvent(string $eventName, $data = array(), $default = true)
        {
            $stats = \Idno\Core\Idno::site()->statistics();
            if (!empty($stats)) {
                $stats->increment("event.$eventName");
            }

            $event = new Event($data);
            $event->setResponse($default);
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
            return $this->dispatcher->dispatch($event, $eventName);
        }
    }
}

