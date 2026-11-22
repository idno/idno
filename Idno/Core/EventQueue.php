<?php

namespace Idno\Core;

use Idno\Common\Entity;

/**
 * Generic interface for queueing events that don't necessarily need
 * to be done synchronously, like sending or receiving webmentions.
 */
abstract class EventQueue extends \Idno\Common\Component
{

    /**
     * Enqueue an event for processing.
     *
     * @param  string $queueName the named queue to put this event on (currently unused)
     * @param  string $eventName the name of the event, e.g. "webmention/send"
     * @param  array  $eventData the data sent to the event when it is triggered.
     * @param  string|null $runAsUser the uuid of the user the event runs as (the logged-in user's when empty)
     * @return string|false an ID that can be used to query the job status; false when the event could not be queued
     */
    abstract function enqueue($queueName, $eventName, array $eventData, $runAsUser = null);

    /**
     * Whether events are queued for a worker to run later (true) or run at once, in the caller's
     * request (false). A plugin that needs the worker (ActivityPub) asks this.
     *
     * @return bool
     */
    function isAsynchronous()
    {
        return false;
    }

    /**
     * Check whether a previously enqueued job has completed.
     *
     * @param  string $jobId the ID of the job returned from enqueue()
     * @return boolean
     */
    abstract function isComplete($jobId);

    /**
     * Retrieve the result of a completed job by its ID.
     *
     * @param  string $jobId the ID of the job returned from enqueue()
     * @return mixed
     */
    abstract function getResult($jobId);

    /**
     * Convert a JSON object to a string, replacing any Entity with its UUID.
     *
     * @param  array $args
     * @return string
     */
    function serialize($args)
    {
        return json_encode($this->convertEntitiesToUUIDs($args), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert a string back to a JSON object, restoring Entities from their UUIDs.
     *
     * @param  string $str
     * @return array
     */
    function deserialize($str)
    {
        return $this->convertUUIDsToEntities(json_decode($str, true));
    }

    function convertEntitiesToUUIDs($args)
    {
        $result = [];
        foreach ($args as $key => $value) {
            if ($value instanceof Entity) {
                $value = [
                    '_class' => $value->getClass(),
                    'uuid'   => $value->getUUID(),
                ];
            } else if (is_array($value)) {
                $value = $this->convertEntitiesToUUIDs($value);
            }
            $result[$key] = $value;
        }
        return $result;
    }

    function convertUUIDsToEntities($args)
    {
        $result = [];
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                if (isset($value['_class'])) {
                    $class  = $value['_class'];
                    $entity = $class::getByUUID($value['uuid']);
                    $value  = $entity;
                } else {
                    $value = $this->deserialize($value);
                }
            }
            $result[$key] = $value;
        }
        return $result;
    }


}
