<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): says how the server that answers is set up (the environment, the
 * debug flag, whether opcache is on), so that a test that times pages can check the server it measures.
 */
final class ServerProbeController
{
    #[Route('/_test/server', name: 'idno_test_server')]
    public function __invoke(Request $request): JsonResponse
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return new JsonResponse([
            'env' => (string) $request->server->get('APP_ENV'),
            'debug' => (string) $request->server->get('APP_DEBUG'),
            'opcache' => is_array($status) && ($status['opcache_enabled'] ?? false) === true,
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
