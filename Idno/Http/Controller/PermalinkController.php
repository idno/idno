<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Permalink\PermalinkPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\RoutedByLegacyTable;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * A post's permalink: the page every content type's address shows its posts at (and the `/view/<id>` address). It has no `#[Route]`: a permalink's
 * address is a stored setting (the permalink structure), and the content types, and the plugins, register their own patterns for the page
 * `Idno\Pages\Entity\View` when the site boots. The legacy route table says which requests are the permalink's, as it always did, and hands them to this action
 * (`#[RoutedByLegacyTable]`); the class `Idno\Pages\Entity\View`, kept as a deprecated shell, names this action and serves what the controller does not take.
 */
#[AsController]
final class PermalinkController
{
    public function __construct(private readonly PermalinkPages $pages)
    {
    }

    #[SitePage(name: 'Idno\Pages\Entity\View', shell: true, markdownPermalink: true)]
    #[RoutedByLegacyTable(route: 'idno_permalink')]
    public function view(PageContext $page): Response
    {
        return $this->pages->view($page);
    }
}
