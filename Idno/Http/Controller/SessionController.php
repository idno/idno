<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Session\SessionPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The login page (`/session/login`: the form for a GET, signing in for a POST) and the logout (`/session/logout`, a POST). Core still registers the legacy
 * patterns, held by `Idno\Legacy\ControllerRoute`, the login page as public (a visitor of a private site has to be able to sign in), so that the legacy
 * router's rules decide what a plugin's registration (a single sign-on plugin's page) does to them. Both POSTs need the session's form token, which the
 * page's lifecycle checks.
 */
#[AsController]
final class SessionController
{
    public function __construct(private readonly SessionPages $pages)
    {
    }

    #[Route('/{lead}session/login{slash}', name: 'idno_login', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: 'Idno\Pages\Session\Login', publicOnPrivateSite: true, methods: ['GET', 'POST'])]
    public function login(PageContext $page): Response
    {
        return $page->request()->isMethod('POST') ? $this->pages->signIn($page) : $this->pages->login($page);
    }

    #[Route('/{lead}session/logout{slash}', name: 'idno_logout', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['POST', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Session\Logout', methods: ['POST'])]
    public function logout(PageContext $page): Response
    {
        return $this->pages->logout($page);
    }
}
