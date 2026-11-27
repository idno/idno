<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Session\CurrentUserPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The current-user page (`/currentUser`), GET and POST: the page of whoever the request is made by (an API client's "who am I"). Core still registers the
 * legacy pattern, held by `Idno\Legacy\ControllerRoute`. A POST has the session's form token checked by the page's lifecycle, a signed request needs none.
 * No `#[IsGranted]`: nobody is the site's 404 page, as the page always answered.
 */
#[AsController]
final class CurrentUserController
{
    public function __construct(private readonly CurrentUserPages $pages)
    {
    }

    #[Route('/{lead}currentUser{slash}', name: 'idno_current_user', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: 'Idno\Pages\Session\CurrentUser', methods: ['GET', 'POST'])]
    public function show(PageContext $page): Response
    {
        return $this->pages->show($page);
    }
}
