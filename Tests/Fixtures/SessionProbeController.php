<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): says how this request's PHP session is
 * stored (`files`, or `user` when a handler such as the database's is installed). The legacy
 * application has started the session before routing, so the answer is what the site really uses.
 */
final class SessionProbeController
{
    #[Route('/_test/session', name: 'idno_test_session')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['module' => session_module_name()]);
    }
}
