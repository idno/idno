<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by the page a controller draws where a legacy page forwards the visitor and exits: the controller (or the listener
 * that answers exceptions) turns it into a redirect to the location.
 */
final class PageRedirect extends \RuntimeException
{
    /**
     * @param string      $location where the visitor is sent
     * @param string|null $body     what an API request is answered instead of a redirect (the JSON a legacy page echoed); null for a redirect
     * @param int         $status   the redirect's status (a redirect that must keep its method is 307)
     */
    public function __construct(public readonly string $location, public readonly ?string $body = null, public readonly int $status = 302)
    {
        parent::__construct('The page forwards the visitor.');
    }

    /**
     * The Response of this redirect, the one place it is built (the listener that answers a thrown redirect and the page renderer use it;
     * it is a pure function of the redirect, so nothing legacy is built): a `Location` and no body, at the status PHP gave a legacy
     * page's `header('Location: ...')`; or, for an API request, the JSON body the page echoed, at its status.
     */
    public function toResponse(): Response
    {
        if ($this->body !== null) {
            return new Response($this->body, $this->status, $this->body === '' ? [] : ['Content-Type' => 'application/json']);
        }
        $response = new Response('', self::statusWithLocation($this->status));
        $response->headers->set('Location', $this->location);

        return $response;
    }

    /**
     * The status PHP answers with when a `Location` header is sent at this one: a 201 and a 3xx stay, any other becomes 302.
     */
    public static function statusWithLocation(int $status): int
    {
        return ($status < 300 || $status > 399) && $status !== 201 ? 302 : $status;
    }
}
