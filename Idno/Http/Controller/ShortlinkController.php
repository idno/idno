<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Permalink\ShortlinkPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The shortlink of a post, `/s/<code>`: sent permanently to the post's address. Core still registers the legacy pattern `/s/:id/?`, held by
 * `Idno\Legacy\ControllerRoute`, so that the legacy router's rules decide what a plugin's registration does to it.
 */
#[AsController]
final class ShortlinkController
{
    public function __construct(private readonly ShortlinkPages $pages)
    {
    }

    #[Route('/{lead}s/{code}{slash}', name: 'idno_shortlink', requirements: ['lead' => '/?', 'slash' => '/?', 'code' => '[A-Za-z0-9\-]+'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Entity\Shortlink')]
    public function go(PageContext $page, string $code): Response
    {
        return $this->pages->go($page, $code);
    }
}
