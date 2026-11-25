<?php

declare(strict_types=1);

namespace Idno\Http;

use Idno\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What index.php runs for every HTTP request: the kernel handles it.
 */
final class FrontController
{
    /**
     * Boots the kernel and handles the request. Does not send the response. A site with no
     * configuration is answered by the installer (Idno\Install\InstallGateListener), inside the kernel.
     */
    public static function handle(Kernel $kernel, Request $request): Response
    {
        return $kernel->handle($request);
    }

    /**
     * The request from the superglobals. Unlike Request::createFromGlobals(), this never
     * parses the body of a PUT, DELETE, PATCH or QUERY request (request_parse_body()
     * consumes php://input, which legacy code reads itself); for every other method the
     * two are identical.
     */
    public static function requestFromGlobals(): Request
    {
        return new Request($_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER);
    }
}
