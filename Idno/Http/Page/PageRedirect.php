<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * Thrown by the page a controller draws where a legacy page forwards the visitor and exits: the controller (or the listener
 * that answers exceptions) turns it into a redirect to the location.
 */
final class PageRedirect extends \RuntimeException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct('The page forwards the visitor.');
    }
}
