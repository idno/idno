<?php

namespace Idno\Exceptions {

    /**
     * Marks an exception whose message is a technical one: written for whoever maintains the
     * site, not for the person looking at the page. It is logged, and shown to visitors only
     * when the site is in debug mode.
     *
     * An exception's message is taken to be written for the visitor unless its type says
     * otherwise, so mark the types that carry technical messages.
     *
     * @see \Idno\Core\ExceptionMessage
     */
    interface Technical
    {
    }
}
