<?php

declare(strict_types=1);

namespace Idno\Http;

use Idno\Kernel;
use Idno\Legacy\LegacyResponse;
use Idno\Legacy\NotInstalledException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What index.php runs for every HTTP request: the kernel handles it, and an uninstalled
 * site is sent to the installer.
 */
final class FrontController
{
    /**
     * Boots the kernel and handles the request. Does not send the response.
     */
    public static function handle(Kernel $kernel, Request $request): Response
    {
        try {
            return $kernel->handle($request);
        } catch (NotInstalledException) {
            return self::notInstalledResponse();
        }
    }

    /**
     * The redirect to the installer, for a site that has no configuration yet. It is sent
     * exactly as the legacy front controller sent it: header('Location: ./warmup/') and
     * nothing else (PHP turns that into a 302).
     */
    public static function notInstalledResponse(): LegacyResponse
    {
        return new LegacyResponse(
            static function (): void {
                header('Location: ./warmup/');
            },
            302,
            ['Location' => './warmup/']
        );
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
