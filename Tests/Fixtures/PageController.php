<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): an HTML page that advertises the sink of
 * the same name as its webmention endpoint, for a test that publishes a post linking to it.
 */
final class PageController
{
    #[Route('/_test/page/{name}', name: 'idno_test_page', requirements: ['name' => '[a-z0-9-]{1,40}'])]
    public function __invoke(Request $request, string $name): Response
    {
        $endpoint = $request->getSchemeAndHttpHost() . '/_test/sink/' . $name;
        $html = '<!doctype html><html><head><title>Test page</title>'
            . '<link rel="webmention" href="' . $endpoint . '"></head>'
            . '<body><p class="h-entry">A page that accepts webmentions.</p></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
