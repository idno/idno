<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): says how the server that answers is set up (the environment, the
 * debug flag, whether opcache is on, whether the Twig bypass is), so that a test that compares two servers can check them.
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
            'twig_bypass' => (string) $request->server->get('IDNO_TEST_TWIG_BYPASS'),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
