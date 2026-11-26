<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by `PageContext::answerConditionally()` where a legacy page sent `304 Not Modified` and exited: the page asked to answer a conditional
 * request and the client's copy is current, so the page stops there, before it draws anything. The listener that answers exceptions (and a
 * deprecated shell) turns it into the 304.
 */
final class PageNotModified extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The visitor\'s copy of the page is current.');
    }

    /**
     * The Response of this exception, the one place it is built: a 304 with no content. The headers the page and the lifecycle add (the session's
     * caching lines, `Link`, the standard headers) are put on it on the way out, as on any answer.
     */
    public function toResponse(): Response
    {
        return new Response('', 304);
    }
}
