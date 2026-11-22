<?php

namespace Idno\Exceptions {

    /**
     * Marks an exception whose message is written for the person looking at the page, and may
     * be shown to any visitor.
     *
     * That is already assumed of an exception whose type says nothing. This says it outright,
     * for a type that would otherwise be taken as technical: one that extends a technical
     * type, or an exception class of a PHP extension or of a library.
     *
     * @see \Idno\Core\ExceptionMessage
     */
    interface Friendly
    {
    }
}
