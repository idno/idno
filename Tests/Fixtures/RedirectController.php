<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): answers any method with a redirect of the
 * given status to this site's own `to` path (default /_test/echo). `to` must start with /_test/
 * and hold no space or control character, so the route can never send a client anywhere else.
 */
final class RedirectController
{
    #[Route('/_test/redirect/{status}', name: 'idno_test_redirect', requirements: ['status' => '301|302|303|307|308'])]
    public function __invoke(Request $request, string $status): Response
    {
        $to = $request->query->get('to', '/_test/echo');
        if (!is_string($to) || !str_starts_with($to, '/_test/') || preg_match('/[\x00-\x20\x7f]/', $to) === 1) {
            return new Response('refused', 400, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain']);
        }

        return new Response('', (int) $status, [
            'Location' => $request->getSchemeAndHttpHost() . $to,
            'Cache-Control' => 'no-store',
        ]);
    }
}
