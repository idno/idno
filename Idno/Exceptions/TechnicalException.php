<?php

namespace Idno\Exceptions {

    /**
     * Exception whose message is a technical one: it is logged, and shown to visitors only
     * when the site is in debug mode.
     */
    class TechnicalException extends \RuntimeException implements Technical
    {
    }
}
