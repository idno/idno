<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Permalink;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shortlink of a post (`/s/<code>`): sent permanently to the address the post has. The one implementation, `ShortlinkController` answers the GET,
 * HEAD and OPTIONS of it; a webmention aimed at a shortlink is taken as one aimed at the post.
 */
#[AsTaggedItem(index: 'Idno\Pages\Entity\Shortlink')]
final class ShortlinkPages implements ReceivesWebmentions
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly Permalinks $permalinks,
    )
    {
    }

    /**
     * The post the code names, moved permanently to the address it has; a code no post has (or that the finders leave out for this viewer: a private post, a
     * draft that is not theirs, a post whose `canRead()` refuses them) is the site's missing-content page (410), so the address says nothing about which posts exist.
     */
    public function go(PageContext $page, string $code): Response
    {
        $entity = $this->entityOf($code);
        // a post the viewer may not read is the answer a missing one is (the finders already leave it out; this is asked again, as for the permalink)
        if ($entity === null || !$this->permalinks->canRead($entity)) {
            return $this->renderer->gone($page);
        }

        // The page's status is the redirect's, as the legacy page set it before it forwarded: a signed request, which is told where to go in a JSON body
        // and not redirected, is answered with it too
        $page->setStatus(301);

        return $this->renderer->redirect($page, $this->permalinks->displayUrl($entity), 301);
    }

    public function receivesFor(string $route): bool
    {
        return $route === 'idno_shortlink';
    }

    /**
     * A webmention to a shortlink: added to the post's annotations. A code no post has takes nothing. Whether the mention was taken.
     */
    public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool
    {
        if (!$this->receivesFor($route)) {
            return false;
        }
        $entity = $this->entityOf((string) ($arguments[0] ?? ''));
        if ($entity === null) {
            return false;
        }

        return $this->permalinks->addWebmentions($entity, $mention);
    }

    private function entityOf(string $code): ?\Idno\Common\Entity
    {
        // (the legacy page asked `empty()` of the argument: '0' is no code)
        return !in_array($code, ['', '0'], true) ? $this->permalinks->byShortUrl($code) : null;
    }
}
