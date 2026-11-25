<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only: the controller of the page whose shell is `FixtureShell`. It makes one call of the service the shell calls.
 */
#[AsController]
final class FixtureShellController
{
    public function __construct(private readonly FixtureShellPages $pages)
    {
    }

    #[Route('/{lead}_test/fixture-shell-controller{slash}', name: 'idno_test_fixture_shell', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: FixtureShell::class, shell: true, markdownPermalink: true)]
    public function show(PageContext $page): Response
    {
        return $this->pages->draw($page);
    }
}
