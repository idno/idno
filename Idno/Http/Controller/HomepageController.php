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
 *
 * The feeds, the search and the tags are the same page under other addresses, and were pages of their own that built a home page to draw
 * (`Idno\Pages\Feed`, `Idno\Pages\Search\Forward`, `Idno\Pages\Search\Tags`, deleted): their routes are the home page's, so they fire its page
 * events once and draw its body class. There is no shell for them to shadow (`replaces` is empty): a legacy page a plugin registered for
 * one of these addresses has it, as it would for any address the site has no legacy page for.
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
        // the one action that serves the site's root as the home page: the one a plugin may replace
        return $this->pages->home($page, root: true);
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

    /**
     * The home page's stream as a feed: RSS unless the request names another template type (`?_t=json`; an `Accept` header does not choose).
     */
    #[Route('/{lead}feed.xml{slash}', name: 'idno_feed_xml', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[Route('/{lead}feed{slash}', name: 'idno_feed', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[Route('/{lead}rss.xml{slash}', name: 'idno_rss_xml', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Homepage', defaultType: 'rss')]
    public function feed(PageContext $page): Response
    {
        return $this->pages->feed($page);
    }

    /**
     * A search, sent to the home page's own (`/?q=`).
     */
    #[Route('/{lead}search{slash}', name: 'idno_search', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Homepage')]
    public function search(PageContext $page): Response
    {
        return $this->pages->search($page);
    }

    /**
     * The posts with a hashtag. The route takes the rest of the path as the tag (anything but white space, as the legacy pattern did, and any
     * byte: the path need not be UTF-8); the tag itself is read from the path as it was sent.
     */
    #[Route('/{lead}tag/{tag}', name: 'idno_tag', requirements: ['lead' => '/?', 'tag' => '[^\s]+'], defaults: ['lead' => ''], methods: ['GET', 'HEAD', 'OPTIONS'], utf8: false)]
    #[SitePage(name: 'Idno\Pages\Homepage')]
    public function tag(PageContext $page): Response
    {
        return $this->pages->tag($page);
    }
}
