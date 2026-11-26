<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by the page a controller draws where a legacy page ended its request at a `forward()` that could not redirect: a POST, PUT or DELETE a
 * script sends (an XHR request) is sent no `Location`, but a handler forwards to refuse and to finish, so the page stops there. The listener that
 * answers exceptions (and a deprecated shell) turns it into an empty answer at the status the page has set.
 */
final class PageEnded extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The page ended where it forwarded a request that cannot be redirected.');
    }

    /**
     * The Response of this exception, the one place it is built: no body, at the page's status (the headers the page and the lifecycle add are put
     * on it on the way out, as on any answer).
     */
    public static function response(int $status): Response
    {
        return new Response('', $status);
    }
}
