<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Account\RegistrationPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The registration page (`/account/register`: the form for a GET, making the account for a POST). Core still registers the legacy pattern, held by
 * `Idno\Legacy\ControllerRoute` and public as before (an invited person reaches the page on a private site too), so that the legacy router's rules decide
 * what a plugin's registration does to it. It is an account page: a signed API request is refused. Its POST needs the session's form token, which the
 * page's lifecycle checks.
 */
#[AsController]
final class RegistrationController
{
    public function __construct(private readonly RegistrationPages $pages)
    {
    }

    #[Route('/{lead}account/register{slash}', name: 'idno_register', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: 'Idno\Pages\Account\Register', accountPage: true, publicOnPrivateSite: true, methods: ['GET', 'POST'])]
    public function register(PageContext $page): Response
    {
        return $page->request()->isMethod('POST') ? $this->pages->register($page) : $this->pages->form($page);
    }
}
