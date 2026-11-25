<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a conditional request (`If-Modified-Since`) to a page that asked for it (`PageContext::answerConditionally()`). Static and
 * pure: not a service.
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

    /**
     * The 304 the page answers when it asked, the answer is a 200 or a 206 (a conditional request is judged before a range: RFC 9110 section
     * 13.2.2) and the client's copy is current; otherwise the response itself. The 304 is a NEW response with the answer's headers less the ones
     * that describe a body (`Content-Type`, `Content-Length`, `Content-Range`, `Last-Modified` and the others `Response::setNotModified()`
     * removes) and no content, so a streamed or file response's body is never sent.
     */
    public static function apply(PageContext $page, Request $request, Response $response): Response
    {
        $lastChange = $page->conditionalTimestamp();
        if ($lastChange === null || !in_array($response->getStatusCode(), [200, 206], true) || !self::notModified($request, $lastChange)) {
            return $response;
        }
        $answer = new Response('', 304);
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $answer->headers->set($name, $values);
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $answer->headers->setCookie($cookie);
        }
        $answer->setNotModified();
        $answer->headers->remove('Content-Range');

        return $answer;
    }
}
