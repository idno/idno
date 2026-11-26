<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Permalink\AnnotationPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\RoutedByLegacyTable;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The annotation pages of a post: the list of its annotations and one annotation (under the post's address and under `/view/<id>`), the delete of an
 * annotation, and the redirect of an annotation's address in a layout the site no longer has. They have no `#[Route]`: a post's address is
 * registered by the content types and the plugins when the site boots, so the legacy route table says which requests are these pages' and hands them
 * to these actions (`#[RoutedByLegacyTable]`); the placeholders core registers in the table name the actions.
 */
#[AsController]
final class AnnotationController
{
    public function __construct(private readonly AnnotationPages $pages)
    {
    }

    #[SitePage(name: 'Idno\Pages\Annotation\View')]
    #[RoutedByLegacyTable(route: 'idno_annotations')]
    public function show(PageContext $page): Response
    {
        return $this->pages->show($page);
    }

    #[SitePage(name: 'Idno\Pages\Annotation\Delete', methods: ['POST'])]
    #[RoutedByLegacyTable(route: 'idno_annotation_delete')]
    #[IsGranted('ROLE_USER')]
    public function delete(PageContext $page): Response
    {
        return $this->pages->delete($page);
    }

    /**
     * An annotation's address in a layout the site no longer has: sent permanently to the address the annotation has.
     */
    #[SitePage(name: 'Idno\Legacy\Placeholder\AnnotationOtherLayout')]
    #[RoutedByLegacyTable(route: 'idno_annotation_other_layout')]
    public function otherLayout(PageContext $page): Response
    {
        return $this->pages->otherLayout($page);
    }
}
