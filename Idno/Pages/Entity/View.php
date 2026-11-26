<?php

    /**
     * Generic, backup viewer for entities
     */

namespace Idno\Pages\Entity {

    use Idno\Core\Idno;
    use Idno\Http\Controller\Permalink\PermalinkPages;
    use Idno\Http\Page\PageContext;
    use Idno\Http\Webmention\ReceivedWebmention;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * The page of a post, at its permalink: a deprecated shell, removed in 3.0.
     *
     * A post's permalink is served by \Idno\Http\Controller\PermalinkController, which the legacy route table hands the requests every content type's
     * permalink pattern (and a plugin's, registered for this class) matches. This class has no logic of its own: it hands its requests to the same service
     * (\Idno\Http\Controller\Permalink\PermalinkPages), so a plugin's page that extends it, a route a plugin registered for it, and the POST and DELETE of a
     * permalink are served as before.
     *
     * @deprecated Use \Idno\Http\Controller\PermalinkController (the route `idno_permalink`), or extend \Idno\Common\Page; removed in 3.0.
     */
    class View extends \Idno\Legacy\PageShell implements \Idno\Common\MarkdownPermalink
    {
        protected const SHELL = self::class;

        public const ACTION = [\Idno\Http\Controller\PermalinkController::class, 'view'];

        // Handle GET requests to the entity

        function getContent()
        {
            return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(PermalinkPages::class)->view($page));
        }

        // Get webmention content and handle it

        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            return $this->delegateWebmention(fn (): bool => Idno::container()->get(PermalinkPages::class)->receiveWebmention('idno_permalink', array_map('strval', array_values($this->arguments)), new ReceivedWebmention($source, $target, $source_response, $source_mf2)));
        }

        // Handle POST requests to the entity

        function postContent()
        {
            return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(PermalinkPages::class)->save($page));
        }

        // Handle DELETE requests to the entity

        function deleteContent()
        {
            return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(PermalinkPages::class)->delete($page));
        }

    }

}
