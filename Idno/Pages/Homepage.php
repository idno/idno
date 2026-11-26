<?php

    /**
     * Defines the site homepage
     */

namespace Idno\Pages {

    use Idno\Core\Idno;
    use Idno\Http\Controller\Home\HomePages;
    use Idno\Http\Page\PageContext;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * The page of the site's home: a deprecated shell, removed in 3.0.
     *
     * The home page is served by \Idno\Http\Controller\HomepageController. This class has no logic of its own: it hands its requests to the
     * same service (\Idno\Http\Controller\Home\HomePages), so a plugin's page that extends it, a route a plugin registered for it and the
     * pages that build one (the feed, the tag search) are served as before, and logging a deprecation when a subclass is.
     *
     * @deprecated Use \Idno\Http\Controller\HomepageController (the route `idno_home`), or extend \Idno\Common\Page; removed in 3.0.
     */
    class Homepage extends \Idno\Legacy\PageShell
    {
        protected const SHELL = self::class;

        public const ACTION = [\Idno\Http\Controller\HomepageController::class, 'home'];

        // Handle GET requests to the homepage

        function getContent()
        {
            return $this->delegate(fn (PageContext $page): Response => !empty($page->arguments()[0]) ? Idno::container()->get(HomePages::class)->content($page, $page->arguments()[0]) : Idno::container()->get(HomePages::class)->home($page));
        }

        /**
         * A webmention to the homepage means someone mentioned our site's root.
         */
        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            return $this->delegateWebmention(fn (): bool => Idno::container()->get(HomePages::class)->receiveWebmention($source, $target, $source_response, $source_mf2));
        }

    }

}
