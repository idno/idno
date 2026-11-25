<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Discovery\Webfinger;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/.well-known/webfinger`: a page that was a legacy class (the `#[SitePage]` names it). Public on a private site.
 */
#[AsController]
final class WebfingerController
{
    public function __construct(private readonly Webfinger $webfinger)
    {
    }

    #[Route('/{lead}.well-known/webfinger{slash}', name: 'idno_webfinger', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Webfinger\View', publicOnPrivateSite: true, legacyPatterns: ['/\.well\-known/webfinger/?'])]
    public function __invoke(PageContext $page): Response
    {
        return $this->webfinger->answer($page);
    }
}
