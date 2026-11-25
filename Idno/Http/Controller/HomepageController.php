<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Home\HomePages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The home page: the site's root and the streams under `/content/`. Both routes are the page `Idno\Pages\Homepage` was, so its body
 * class, its page events and a legacy page registered for the same path (a plugin's `hijackRoute()` of the root) are as they were.
 */
#[AsController]
final class HomepageController
{
    public function __construct(private readonly HomePages $pages)
    {
    }

    #[Route('/{slash}', name: 'idno_home', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Homepage', replaces: ['Idno\Pages\Homepage'])]
    public function home(PageContext $page): Response
    {
        return $this->pages->home($page);
    }

    /**
     * @param string $types the content types the path names, as written (`note/article`, `all`)
     */
    #[Route('/{lead}content/{types}{slash}', name: 'idno_content', requirements: ['lead' => '/?', 'slash' => '/?', 'types' => '[A-Za-z\-/]+'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'], utf8: false)]
    #[SitePage(name: 'Idno\Pages\Homepage', replaces: ['Idno\Pages\Homepage'])]
    public function content(PageContext $page, string $types): Response
    {
        return $this->pages->content($page, $types);
    }
}
