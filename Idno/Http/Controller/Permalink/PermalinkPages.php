<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Permalink;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page of a post: its permalink, which every content type's address routes to (and the `/view/<id>` address). The one implementation:
 * `PermalinkController` answers the GET, HEAD and OPTIONS the router hands it, and the deprecated `Idno\Pages\Entity\View` (and so a plugin's page that
 * extends it, or a route a plugin registered for it) serves its POST, DELETE, webmentions and any GET of a subclass through the same methods.
 */
#[AsTaggedItem(index: 'Idno\Pages\Entity\View')]
final class PermalinkPages implements ReceivesWebmentions
{
    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application
     */
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly Permalinks $permalinks,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
    )
    {
    }

    /**
     * The post an address names, in its shell, or as Markdown when the request asks for it. The checks come first and in this order: a post that is not
     * there is gone (410), one the viewer may not read is answered as one that is not there (410), an account is the profile page, and only then is a conditional request
     * answered (never before the viewer's right to see the post is known) and the Markdown form drawn.
     */
    public function view(PageContext $page): Response
    {
        $this->permalinks->selectMarkdownIfWanted($page);

        $entity = $this->entityOf($page);
        if ($entity === null) {
            return $this->renderer->gone($page);
        }
        if (!$this->permalinks->canRead($entity)) {
            // answered as a post that is not there: the address does not tell which posts exist
            return $this->renderer->gone($page);
        }
        // An account's address is its profile
        if ($this->permalinks->isUser($entity)) {
            return $this->renderer->redirect($page, $this->permalinks->displayUrl($entity));
        }

        $page->setOwner($this->permalinks->owner($entity));
        $page->setPermalink(true, $entity);

        // The page is not sent again to a viewer who holds a current copy; a post with no date has no change to compare with
        $updated = $this->permalinks->updated($entity);
        if ($updated > 0) {
            $page->answerConditionally($updated);
            $page->setLastModified($updated);
        }

        if ($this->permalinks->wantsMarkdown($page)) {
            return $this->permalinks->markdown($page, $entity);
        }

        return $this->renderer->page($page, [
            'title' => $this->permalinks->title($entity),
            'body' => $this->renderer->template('entity/wrapper', ['object' => $entity]),
            'description' => $this->permalinks->description($entity),
        ]);
    }

    /**
     * A post's address in a layout the site no longer has (`/YYYY/MM/<slug>` and `/YYYY/MM/DD/<slug>`, which a site could choose before a post's address had
     * one layout), sent permanently to the address the post has: a link made then keeps working. The target is built from the post, never from the request.
     * A post that is not there and one the viewer may not read are the same answer (the site's missing-post page, 410), so the address says nothing about
     * which posts exist.
     */
    public function otherLayout(PageContext $page): Response
    {
        $entity = $this->entityOf($page);
        if ($entity === null || !$this->permalinks->canRead($entity)) {
            return $this->renderer->gone($page);
        }

        return $this->renderer->redirect($page, $this->permalinks->displayUrl($entity), 301);
    }

    /**
     * What a POST to the post's address does: a signed-in member who may edit it saves what the request holds into it and is sent to it; a visitor and a
     * member who may not edit it are refused; one that may not read it is answered as the site's missing post is (sent home). Nothing is stored before
     * those checks are passed.
     */
    public function save(PageContext $page): Response
    {
        if (!$this->permalinks->isLoggedIn()) {
            return $this->renderer->denied($page);
        }
        $entity = $this->entityOf($page);
        if ($entity === null || !$this->permalinks->canRead($entity)) {
            return $this->renderer->redirect($page);
        }
        if (!$this->permalinks->canEdit($entity)) {
            return $this->renderer->denied($page);
        }
        if ($this->permalinks->saveFromInput($entity)) {
            return $this->renderer->redirect($page, $this->permalinks->displayUrl($entity));
        }

        return $this->renderer->redirect($page, $this->permalinks->referrer($page));
    }

    /**
     * What a DELETE of the post's address does: only a signed-in member who may edit the post deletes it (an account only an administrator's session does),
     * a post the member may not read is answered as a missing one is (sent home, whatever the request), and the member is sent back to where they came from.
     */
    public function delete(PageContext $page): Response
    {
        if (!$this->permalinks->isLoggedIn()) {
            return $this->renderer->denied($page);
        }
        $entity = $this->entityOf($page);
        if ($entity === null || !$this->permalinks->canRead($entity)) {
            return $this->renderer->redirect($page);
        }
        if ($this->permalinks->isUser($entity)) {
            if (!$this->permalinks->isAdministrator()) {
                return $this->renderer->denied($page);
            }
        } elseif (!$this->permalinks->canEdit($entity)) {
            return $this->renderer->denied($page);
        }
        $this->permalinks->delete($entity);

        return $this->renderer->redirect($page, $this->permalinks->referrer($page));
    }

    /**
     * A permalink takes a webmention on its own route (`idno_permalink`).
     */
    public function receivesFor(string $route): bool
    {
        return $route === 'idno_permalink';
    }

    public function accepts(string $route, array $arguments): bool
    {
        $key = (string) ($arguments[0] ?? '');
        if (!$this->receivesFor($route) || in_array($key, ['', '0'], true)) {
            return false;
        }
        $entity = $this->permalinks->find($key);

        return $entity !== null && $this->permalinks->canRead($entity);
    }

    /**
     * A webmention to a post: added to the post's annotations, unless it comes from the post itself (its own address as the source, or the source the
     * target). A post that is not there takes nothing. Whether the mention was taken.
     */
    public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool
    {
        if (!$this->receivesFor($route)) {
            return false;
        }
        $key = (string) ($arguments[0] ?? '');
        $entity = !in_array($key, ['', '0'], true) ? $this->permalinks->find($key) : null;
        if ($entity === null) {
            // (what the address names is the sender's: no control characters, and no more than 200 bytes, in a log line)
            ($this->logger)()->error('No object was found with ID ' . substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $key), 0, 200) . '.');

            return false;
        }
        if ($mention->source !== $mention->target && $mention->source !== $this->permalinks->objectUrl($entity)) {
            return $this->permalinks->addWebmentions($entity, $mention);
        }

        return true;
    }

    private function entityOf(PageContext $page): ?\Idno\Common\Entity
    {
        $key = $page->arguments()[0] ?? '';

        // (the legacy page asked `empty()` of the argument: '0' is no address)
        return !in_array($key, ['', '0'], true) ? $this->permalinks->find($key) : null;
    }
}
