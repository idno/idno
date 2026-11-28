<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Account\PasswordResetPages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The password-reset pages: `/account/password` (the form that asks for a reset link, and the POST that mails it) and `/account/password/reset` (the
 * link's form that sets the new password, and its POST). Core still registers both legacy patterns, held by `Idno\Legacy\ControllerRoute` and public as
 * before (a visitor who forgot their password reaches them on a private site too), so that the legacy router's rules decide what a plugin's registration
 * does to them. They are account pages: a signed API request is refused. Each POST needs the session's form token, which the page's lifecycle checks.
 */
#[AsController]
final class PasswordResetController
{
    public function __construct(private readonly PasswordResetPages $pages)
    {
    }

    #[Route('/{lead}account/password{slash}', name: 'idno_password', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: 'Idno\Pages\Account\Password', accountPage: true, publicOnPrivateSite: true, methods: ['GET', 'POST'])]
    public function request(PageContext $page): Response
    {
        return $page->request()->isMethod('POST') ? $this->pages->request($page) : $this->pages->requestForm($page);
    }

    #[Route('/{lead}account/password/reset{slash}', name: 'idno_password_reset', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: 'Idno\Pages\Account\Password\Reset', accountPage: true, publicOnPrivateSite: true, methods: ['GET', 'POST'])]
    public function reset(PageContext $page): Response
    {
        return $page->request()->isMethod('POST') ? $this->pages->reset($page) : $this->pages->resetForm($page);
    }
}
