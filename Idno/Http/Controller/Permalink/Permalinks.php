<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Permalink;

use Idno\Common\Entity;
use Idno\Entities\User;
use Idno\Http\Page\PageContext;
use Idno\Http\Webmention\ReceivedWebmention;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the post permalink asks the site: the entity an address names, who may read and edit it, how it is described and drawn as Markdown, and what writing to
 * or deleting it does. The legacy application implements it (`Idno\Legacy\LegacyPermalinks`), so the permalink service is built and tested without it.
 */
interface Permalinks
{
    /**
     * The entity an id or a slug names: by its id, else by its slug. Null for none.
     */
    public function find(string $idOrSlug): ?Entity;

    /**
     * Whether the viewer may read the entity (its access, and a draft that is the viewer's).
     */
    public function canRead(Entity $entity): bool;

    /**
     * Whether the viewer may edit the entity.
     */
    public function canEdit(Entity $entity): bool;

    /**
     * Whether someone is signed in (by the browser's session or by a signed request).
     */
    public function isLoggedIn(): bool;

    /**
     * Whether the signed-in viewer is an administrator (never for a signed API request).
     */
    public function isAdministrator(): bool;

    /**
     * Whether the entity is an account: its address is its owner's profile.
     */
    public function isUser(Entity $entity): bool;

    /**
     * The address a person sees for the entity (an account's profile page).
     */
    public function displayUrl(Entity $entity): string;

    /**
     * The entity's owner, null when it has none.
     */
    public function owner(Entity $entity): ?User;

    public function title(Entity $entity): string;

    /**
     * The short description of the entity, else a sample of its description.
     */
    public function description(Entity $entity): string;

    /**
     * When the entity last changed, as a timestamp; 0 for one that has no date.
     */
    public function updated(Entity $entity): int;

    /**
     * Makes the request's template type Markdown when the request asks for the Markdown form (`.md`, `?format=markdown`, `?_t=markdown`, `Accept`).
     */
    public function selectMarkdownIfWanted(PageContext $page): void;

    /**
     * Whether the request asks for the Markdown form of the post.
     */
    public function wantsMarkdown(PageContext $page): bool;

    /**
     * The post as a Markdown document: the page's answer in the type's own headers, at the context's status.
     */
    public function markdown(PageContext $page, Entity $entity): Response;

    /**
     * Saves what the request holds into the entity. Whether it was saved.
     */
    public function saveFromInput(Entity $entity): bool;

    /**
     * Deletes the entity, and tells the member so with a message (`%s was deleted.`) when it was. Whether it was deleted.
     */
    public function delete(Entity $entity): bool;

    /**
     * Adds a received webmention to the entity. Whether it was taken.
     */
    public function addWebmentions(Entity $entity, ReceivedWebmention $mention): bool;

    /**
     * The address the entity has as an object: what a webmention from the post itself is recognised by.
     */
    public function objectUrl(Entity $entity): string;

    /**
     * Where a request that came from another page sends the person back: the page it came from when it is this site's, else the site's home page.
     */
    public function referrer(PageContext $page): string;
}
