<?php

namespace Idno\Exceptions {

    /**
     * Exception whose message is written for the person looking at the page, and may be shown
     * to any visitor.
     */
    class FriendlyException extends \RuntimeException implements Friendly
    {
    }
}
