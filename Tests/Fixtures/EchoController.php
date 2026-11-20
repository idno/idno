<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): answers any method with what the
 * request carried (see RequestDescription), so a test can pin what an outbound client sent.
 * The body is read with getContent(): the request is built without parsing it for PUT, PATCH
 * and DELETE.
 */
final class EchoController
{
    #[Route('/_test/echo', name: 'idno_test_echo')]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(RequestDescription::of($request), 200, ['Cache-Control' => 'no-store']);
    }
}
