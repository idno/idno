<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\WebmentionEndpoint;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The webmention endpoint, `/webmention/`: its page for a GET and the receiver for a POST. Other servers post to it with no session and no form token
 * (`formTokenReplacedBy`), and the request acts as nobody. Core still registers the legacy pattern `/webmention/?`, held by `Idno\Legacy\ControllerRoute`, as
 * public (a visitor of a private site is a sender too), so that the legacy router's rules decide what a plugin's registration does to it.
 */
#[AsController]
final class WebmentionController
{
    public function __construct(private readonly WebmentionEndpoint $endpoint)
    {
    }

    #[Route('/{lead}webmention{slash}', name: 'idno_webmention', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(
        name: 'Idno\Pages\Webmentions\Endpoint',
        publicOnPrivateSite: true,
        methods: ['GET', 'POST'],
        formTokenReplacedBy: 'Webmention (W3C): the sender is anonymous; the source page is fetched through the remote client and must link to the target, which must be a page of this site that takes mentions'
    )]
    public function __invoke(PageContext $page): Response
    {
        return $page->request()->isMethod('POST') ? $this->endpoint->receive($page) : $this->endpoint->page($page);
    }
}
