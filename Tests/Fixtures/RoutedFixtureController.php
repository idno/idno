<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\RoutedByLegacyTable;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Test-environment only: two actions routed by the legacy route table, with no `#[Route]`: `show` for the placeholder `RoutedPlaceholder` (a page whose
 * class does not exist) and `showShell` for the kept shell `RoutedFixtureShell` (a Markdown permalink). Each makes one call of `RoutedFixturePages`.
 */
#[AsController]
final class RoutedFixtureController
{
    public function __construct(private readonly RoutedFixturePages $pages)
    {
    }

    #[SitePage(name: 'Tests\Fixtures\RoutedPlaceholder')]
    #[RoutedByLegacyTable(route: 'idno_test_routed')]
    public function show(PageContext $page): Response
    {
        return $this->pages->draw($page);
    }

    #[SitePage(name: RoutedFixtureShell::class, shell: true, markdownPermalink: true)]
    #[RoutedByLegacyTable(route: 'idno_test_routed_shell')]
    public function showShell(PageContext $page): Response
    {
        return $this->pages->draw($page);
    }
}
