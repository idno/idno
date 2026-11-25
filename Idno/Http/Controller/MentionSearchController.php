<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Discovery\MentionSearch;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/search/mentions.json`: a page that was a legacy class (the `#[SitePage]` names it). Not public on a private site.
 */
#[AsController]
final class MentionSearchController
{
    public function __construct(private readonly MentionSearch $search)
    {
    }

    #[Route('/{lead}search/mentions.json{slash}', name: 'idno_mention_search', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Search\Mentions')]
    public function __invoke(PageContext $page): Response
    {
        return $this->search->answer($page);
    }
}
