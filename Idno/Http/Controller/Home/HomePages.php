<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Home;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Queue\EventTrigger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Response;

/**
 * The home page: the stream of the site's posts in its shell, at the root and under `/content/` (the friendly paths that name content
 * types), and the pages that are the same stream under another address: the feeds (`/feed.xml`, `/feed`, `/rss.xml`, the stream in the
 * `rss` type), the tag pages (`/tag/<tag>`, the posts that carry a hashtag, in every content type) and the search page (a redirect to
 * the stream's own search). The one implementation of all of them: `HomepageController` answers the requests the router gives it, and
 * the deprecated `Idno\Pages\Homepage` (and so a plugin's page that extends it) draws it through the same methods.
 */
final class HomePages
{
    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application, which a
     *                                          kernel that did not boot it does not have
     * @param \Closure(): EventTrigger $events the site's events, fetched when the home page asks for a replacement (the same reason)
     */
    public function __construct(
        private readonly HomeStream $stream,
        private readonly PageRenderer $renderer,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
        #[AutowireServiceClosure(EventTrigger::class)]
        private readonly \Closure $events,
    )
    {
    }

    /**
     * The home page of a request that names no content type in its path.
     *
     * A plugin may replace it, when the caller says it is serving the site's root (`$root`: the controller's home action; the pages that
     * draw the stream under an address of their own, and a subclass of the deprecated shell wherever it is registered, never ask): the event
     * `homepage/replace` is triggered first, with `page` (the `PageContext`) and `renderer` (the `PageRenderer`), and a listener that sets a
     * `Symfony\Component\HttpFoundation\Response` as the event's response (`$event->setResponse()`) gives the home page. The first Response
     * set is kept: a later listener cannot replace or erase it. Anything else a listener answers (the event's default `true`, `false`, a
     * string, an array, any object that is no `Response`) is ignored and the stream is drawn. A listener that throws fails the page as a page
     * that throws does (the 500 page, the exception reported); this does not catch it. The streams under `/content/` are never replaced: they
     * are how the stream stays reachable when the home page is something else.
     */
    public function home(PageContext $page, bool $root = false): Response
    {
        if ($root) {
            $replacement = ($this->events)()->trigger(
                'homepage/replace',
                ['page' => $page, 'renderer' => $this->renderer],
                static fn (mixed $answer): bool => $answer instanceof Response
            );
            if ($replacement instanceof Response) {
                return $replacement;
            }
        }

        return $this->serve($page, null);
    }

    /**
     * The stream of the content types a friendly path names (`note/article`, `all`).
     */
    public function content(PageContext $page, string $types): Response
    {
        return $this->serve($page, $types);
    }

    /**
     * The home page's stream as the feed: the same stream, drawn in the template type the request names, the `rss` type when it names
     * none (the controller's `defaultType`). It is not the site's root, so no plugin replaces it.
     */
    public function feed(PageContext $page): Response
    {
        return $this->serve($page, null);
    }

    /**
     * A visitor's search, sent to the stream's own: `<site address>?q=<the query>`. A query that is not text (`q[]=x`) is an empty one.
     */
    public function search(PageContext $page): Response
    {
        $query = $page->input('q');

        return $this->renderer->redirect($page, $this->stream->siteAddress() . '?q=' . urlencode(is_string($query) ? $query : ''));
    }

    /**
     * The posts that carry a hashtag, of every content type: the tag is what follows `/tag/` in the address as it was sent, decoded
     * once (a plus sign is a space, an encoded one a plus), less one trailing slash, and it is the stream's query as `#<tag>`, whatever
     * `q` the request carries. An address with no tag is no page.
     */
    public function tag(PageContext $page): Response
    {
        $tag = preg_match('~^/{1,2}tag/(.*)$~s', $page->request()->getPathInfo(), $found) === 1 ? $found[1] : '';
        if (str_ends_with($tag, '/')) {
            $tag = substr($tag, 0, -1);
        }
        $tag = urldecode($tag);
        if ($tag === '') {
            return $this->renderer->notFound($page);
        }
        // the page's own input, whatever `q` the request carries: what a template that reads it (the empty state) says it was searched for
        $page->replaceInput('q', '#' . $tag);

        return $this->serve($page, 'all', '#' . $tag);
    }

    private function serve(PageContext $page, ?string $friendly, ?string $search = null): Response
    {
        $query = $search ?? $page->input('q');
        $offset = (int) $page->input('offset');

        // An empty site has no home page
        if (!$this->stream->siteHasUsers()) {
            return $this->renderer->redirect($page, $this->stream->beginUrl());
        }

        // The home page of a single-user site is its owner's
        if ($page->owner() === null && ($owner = $this->stream->singleUserOwner()) !== null) {
            $page->setOwner($owner);
        }

        // The friendly content-specific path (if any) and the request's own types
        $resolved = $this->stream->types($friendly, $query, $page->input('types'));
        $friendlyTypes = $resolved['friendly'];

        $stream = $this->stream->page($resolved['types'], $query, $offset);
        $feed = $stream['items'];
        $count = $stream['count'];
        $create = $this->stream->creatableTypes();
        $titles = $this->stream->titles();

        // The page was last modified when its newest post was
        if (!empty($feed) && is_array($feed)) {
            $feed = array_filter($feed);
            $page->addHeader('Last-Modified', $this->stream->lastModified((int) reset($feed)->updated));
        }

        $body = $this->renderer->template('pages/home', [
            'items' => $feed,
            'contentTypes' => $create,
            'offset' => $offset,
            'count' => $count,
            'subject' => $query,
            'content' => $friendlyTypes,
        ]);

        return $this->renderer->page($page, [
            'title' => $titles['title'],
            'description' => $titles['description'],
            'content' => $friendlyTypes,
            'body' => $body,
        ]);
    }

    /**
     * A webmention to the home page means someone mentioned the site's root. On a single-user site the root is its owner's profile, so the
     * mention is passed on to the page of the profile; a multi-user site has no page there to take it.
     *
     * @param array<mixed> $sourceResponse the fetched source (`response`, `content`)
     * @param array<mixed> $sourceMf2      its parsed microformats
     */
    public function receiveWebmention(string $source, string $target, array $sourceResponse, array $sourceMf2): bool
    {
        ($this->logger)()->info("received homepage mention from $source");

        if ($this->stream->isSingleUser()) {
            $user = $this->stream->firstAdministrator();
            if ($user !== null) {
                return $this->stream->profilePageWebmention($user, $source, $target, $sourceResponse, $sourceMf2);
            }
            ($this->logger)()->debug('query for an admin-user failed to find one');
        } else {
            ($this->logger)()->debug('disregarding mention to multi-user site');
        }

        return false;
    }
}
