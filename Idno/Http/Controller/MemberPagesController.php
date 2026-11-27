<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Member\MemberPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The member's drafts page (`/drafts/`). It is for a signed-in member (a visitor is answered with the site's 403 page, after the page's events, as a
 * legacy gatekeeper did) and read only. Core still registers the legacy pattern `/drafts/?`, held by `Idno\Legacy\ControllerRoute`.
 */
#[AsController]
final class MemberPagesController
{
    public function __construct(private readonly MemberPages $pages)
    {
    }

    #[Route('/{lead}drafts{slash}', name: 'idno_drafts', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Drafts')]
    #[IsGranted('ROLE_USER')]
    public function drafts(PageContext $page): Response
    {
        return $this->pages->drafts($page);
    }
}
