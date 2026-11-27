<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Controller\Permalink\Permalinks;
use Idno\Http\Page\PageContext;
use Idno\Http\Webmention\ReceivedWebmention;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the post permalink asks the site, over the legacy application: what `Idno\Pages\Entity\View` asked, moved here unchanged, so the same lookups are made
 * in the same order, the same events fire and the same lines are logged.
 */
final class LegacyPermalinks implements Permalinks
{
    public function find(string $idOrSlug): ?Entity
    {
        $entity = Entity::getByID($idOrSlug);
        if (empty($entity)) {
            $entity = Entity::getBySlug($idOrSlug);
        }

        return $entity instanceof Entity ? $entity : null;
    }

    public function byShortUrl(string $code): ?Entity
    {
        $entity = Entity::getByShortURL($code);

        return $entity instanceof Entity ? $entity : null;
    }

    public function canRead(Entity $entity): bool
    {
        return (bool) $entity->canRead();
    }

    public function canEdit(Entity $entity): bool
    {
        return (bool) $entity->canEdit();
    }

    public function isLoggedIn(): bool
    {
        return (bool) Idno::site()->session()->isLoggedIn();
    }

    public function isAdministrator(): bool
    {
        $session = Idno::site()->session();

        return $session->isLoggedIn() && $session->currentUser()->isAdmin();
    }

    public function isUser(Entity $entity): bool
    {
        return $entity instanceof User;
    }

    public function displayUrl(Entity $entity): string
    {
        return (string) $entity->getDisplayURL();
    }

    public function owner(Entity $entity): ?User
    {
        $owner = $entity->getOwner();

        return $owner instanceof User ? $owner : null;
    }

    public function title(Entity $entity): string
    {
        return (string) $entity->getTitle();
    }

    public function description(Entity $entity): string
    {
        $description = $entity->getShortDescription();
        if (empty($description)) {
            $description = Idno::site()->template()->sampleText($entity->getDescription());
        }

        return (string) $description;
    }

    public function updated(Entity $entity): int
    {
        return $entity->lastChangedAt();
    }

    public function selectMarkdownIfWanted(PageContext $page): void
    {
        Idno::site()->currentPage()->selectMarkdownIfWanted();
    }

    public function wantsMarkdown(PageContext $page): bool
    {
        return (bool) Idno::site()->currentPage()->wantsMarkdown();
    }

    public function markdown(PageContext $page, Entity $entity): Response
    {
        $document = Idno::site()->currentPage()->drawMarkdown($entity);

        $document = is_string($document) ? $document : '';
        $response = new Response($document, $page->status());
        // As every drawn page: the length of what was drawn, in bytes (a HEAD answer keeps it)
        $response->headers->set('Content-Length', (string) strlen($document));

        return $response;
    }

    public function saveFromInput(Entity $entity): bool
    {
        return (bool) $entity->saveDataFromInput();
    }

    public function delete(Entity $entity): bool
    {
        if (!$entity->delete()) {
            return false;
        }
        Idno::site()->session()->addMessage(Idno::site()->language()->esc_('%s was deleted.', [$entity->getTitle()]));

        return true;
    }

    public function addWebmentions(Entity $entity, ReceivedWebmention $mention): bool
    {
        return (bool) $entity->addWebmentions($mention->source, $mention->target, $mention->sourceResponse, $mention->sourceMf2);
    }

    public function objectUrl(Entity $entity): string
    {
        return (string) $entity->getObjectURL();
    }

    public function referrer(PageContext $page): string
    {
        return \Idno\Http\RedirectTarget::sanitize($page->request()->headers->get('Referer') ?? '', \Idno\Core\Idno::site()->config()->getDisplayURL());
    }
}
