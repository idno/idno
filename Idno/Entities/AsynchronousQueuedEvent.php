<?php

namespace Idno\Entities {

    /**
     * Represents a queued event stored in the AsynchronousQueue.
     */
    #[\Idno\Storage\Attribute\AsEntity('queued_event')]
    class AsynchronousQueuedEvent extends \Idno\Entities\BaseObject
    {

        public function save($overrideAccess = false)
        {
            if (empty($this->queue)) {
                $this->queue = 'default';
            }

            return parent::save($overrideAccess);
        }

        public static function getPendingFromQueue($queue = 'default', $limit = 10, $offset = 0)
        {
            return self::get(['queue' => $queue, 'complete' => ['$not' => ['$in' => [true]]]], [], $limit, $offset);
        }

        public static function getFromQueue($queue = 'default', $limit = 10, $offset = 0)
        {
            return self::get(['queue' => $queue], [], $limit, $offset);
        }
    }

}
