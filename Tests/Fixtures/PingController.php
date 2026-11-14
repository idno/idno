<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): proves that a route defined the
 * new way is matched before the legacy catch-all.
 */
final class PingController
{
    #[Route('/_test/ping', name: 'idno_test_ping')]
    public function __invoke(): Response
    {
        return new Response('pong', 200, ['X-Idno-Kernel' => '1']);
    }
}
