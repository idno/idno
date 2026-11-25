<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Home;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The home page: the stream of the site's posts in its shell, at the root and under `/content/` (the friendly paths that name content
 * types). The one implementation of it: `HomepageController` answers the requests the router gives it, and the deprecated
 * `Idno\Pages\Homepage` (and so a plugin's page that extends it, the feeds and the tag search that build one) draws it through the same
 * methods.
 */
final class HomePages
{
    public function __construct(
        private readonly HomeStream $stream,
        private readonly PageRenderer $renderer,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * The home page of a request that names no content type in its path.
     */
    public function home(PageContext $page): Response
    {
        return $this->serve($page, null);
    }

    /**
     * The stream of the content types a friendly path names (`note/article`, `all`).
     */
    public function content(PageContext $page, string $types): Response
    {
        return $this->serve($page, $types);
    }

    private function serve(PageContext $page, ?string $friendly): Response
    {
        $query = $page->input('q');
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
        $this->logger->info("received homepage mention from $source");

        if ($this->stream->isSingleUser()) {
            $user = $this->stream->firstAdministrator();
            if ($user !== null) {
                return $this->stream->profilePageWebmention($user, $source, $target, $sourceResponse, $sourceMf2);
            }
            $this->logger->debug('query for an admin-user failed to find one');
        } else {
            $this->logger->debug('disregarding mention to multi-user site');
        }

        return false;
    }
}
