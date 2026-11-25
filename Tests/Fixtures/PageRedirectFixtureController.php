<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageRedirect;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): handlers that end as a page a controller serves does when it forwards,
 * for the test that sends them through the real kernel (`Tests\Kernel\PageRedirectKernelTest`).
 */
#[AsController]
final class PageRedirectFixtureController
{
    #[Route('/_test/page-redirect/redirect', name: 'idno_test_page_redirect_redirect', methods: ['GET'])]
    public function redirect(): Response
    {
        throw new PageRedirect('https://example.com/next');
    }

    #[Route('/_test/page-redirect/api', name: 'idno_test_page_redirect_api', methods: ['GET'])]
    public function api(): Response
    {
        throw new PageRedirect('https://example.com/next', '{"location":"https:\/\/example.com\/next"}', 200);
    }

    #[Route('/_test/page-redirect/empty', name: 'idno_test_page_redirect_empty', methods: ['GET'])]
    public function empty(): Response
    {
        throw new PageRedirect('https://example.com/next', '', 200);
    }
}
