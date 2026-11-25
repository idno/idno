<?php

    /**
     * Event class to handle data transport during event triggering
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Event extends \Symfony\Contracts\EventDispatcher\Event
    {

        protected $data = array();
        protected $dispatcher = null;
        protected $response = true;
        protected $forward = '';
        protected ?\Closure $keepResponse = null;

        function __construct($data = array())
        {
            $this->data       = $data;
            $this->dispatcher = site()->events();
        }

        /**
         * Retrieve data associated with an event
         *
         * @return mixed
         */
        function &data()
        {
            return $this->data;
        }

        /**
         * Retrieve the response variable associated with this event
         *
         * @return type
         */
        function &response()
        {
            return $this->response;
        }

        /**
         * Set the response variable associated with this event
         *
         * @param  $value
         * @return true|false
         */
        function setResponse($value)
        {
            // an event triggered with a rule for the response it keeps (`keepResponseWhen()`): the first answer the rule accepts stays
            if ($this->keepResponse !== null && ($this->keepResponse)($this->response)) {
                return $this->response;
            }

            return $this->response = $value;
        }

        /**
         * Sets the rule for the response this event keeps: while the response set so far is one the rule accepts, `setResponse()`
         * leaves it alone, so the first listener to give an acceptable answer wins and a later one cannot replace or erase it.
         *
         * @param \Closure(mixed): bool $keep Given the response so far
         */
        function keepResponseWhen(\Closure $keep): void
        {
            $this->keepResponse = $keep;
        }

        /**
         * Retrieve the variable associated with the URL to forward to
         * (if any) after this event
         *
         * @return type
         */
        function &forward()
        {
            return $this->forward;
        }

        /**
         * Overloading the entity property isset check, so that
         * isset($entity->property) and empty($entity->property)
         * work as expected.
         */

        function __isset($name)
        {
            if (!empty($this->attributes[$name])) {
                return true;
            }

            return false;
        }

    }

}

