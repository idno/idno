<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request's copy of a page is current (`If-Modified-Since`). Static and pure: not a service. A page asks with
 * `PageContext::answerConditionally()`, which stops it with `PageNotModified` when this says so.
 */
final class ConditionalRequest
{
    /**
     * Whether the client's copy is current: a GET or HEAD, with no `If-None-Match` (which makes `If-Modified-Since` ignored: RFC 9110
     * section 13.1.3), whose `If-Modified-Since` is an HTTP-date that is not before the page's last change.
     */
    public static function notModified(Request $request, int $lastChange): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $request->headers->has('If-None-Match')) {
            return false;
        }
        $since = HttpDate::parse((string) $request->headers->get('If-Modified-Since', ''));

        // A client that has a copy from before the change is sent the page; one whose copy is as new as the change, or newer, is told it is current.
        // (The legacy `Page::lastModifiedGatekeeper()` compared the other way round.)
        return $since !== null && $lastChange <= $since;
    }
}
