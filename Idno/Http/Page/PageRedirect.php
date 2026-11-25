<?php

declare(strict_types=1);

namespace Idno\Http\Page;

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
}
