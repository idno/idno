<?php

namespace IdnoPlugins\Status {

    #[\Idno\Storage\Attribute\AsEntity('status_reply')]
    class Reply extends \IdnoPlugins\Status\Status
    {

        function getMetadataForFeed()
        {
            return array(
                'type' => 'reply',
                'in-reply-to' => $this->inreplyto
            );
        }

    }

}
