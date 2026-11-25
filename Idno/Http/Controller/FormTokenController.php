<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Security\FormToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/service/security/csrftoken/`: the session's form token, for a script (a page that was a legacy class: the `#[SitePage]` names it). Only an
 * XHR request is answered (anything else is the site's 403 page, as before), and the answer is never cached. The token is the session's one
 * stored token, so the endpoint needs no address to make one for.
 */
#[AsController]
final class FormTokenController
{
    public function __construct(
        private readonly FormToken $token,
        private readonly PageRenderer $renderer,
    )
    {
    }

    #[Route('/{lead}service/security/csrftoken{slash}', name: 'idno_csrf_token', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Service\Security\CSRFToken', legacyPatterns: ['/service/security/csrftoken/?'])]
    public function __invoke(PageContext $page): Response
    {
        if (!$page->isXhr()) {
            return $this->renderer->denied($page);
        }
        $page->setCaching(['Cache-Control' => 'no-store']);

        return new Response(
            (string) json_encode(['time' => time(), 'token' => $this->token->value()]),
            200,
            ['Content-Type' => 'application/json']
        );
    }
}
