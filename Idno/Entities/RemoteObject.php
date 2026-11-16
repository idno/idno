<?php

    /**
     * Remote object representation
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    #[\Idno\Storage\Attribute\AsEntity('remote_object')]
    class RemoteObject extends \Idno\Entities\BaseObject implements \JsonSerializable
    {

        public function save($add_to_feed = false, $feed_verb = 'post')
        {
            // TODO: use a remote API to save to external sources if we have permission to
            return false;
        }

    }

}

