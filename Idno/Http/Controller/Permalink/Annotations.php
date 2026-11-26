<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Permalink;

use Idno\Common\Entity;
use Idno\Entities\User;

/**
 * What the annotation pages ask the site: the post an address names, who may read and edit its annotations, the annotations themselves (a post keeps them by
 * the address each was stored under, its key) and what removing one does. The legacy application implements it (`Idno\Legacy\LegacyAnnotations`), so the page
 * service is built and tested without it.
 */
interface Annotations
{
    /**
     * The post an id or a slug names: by its id, else by its slug. Null for none.
     */
    public function find(string $idOrSlug): ?Entity;

    /**
     * Whether the viewer may read the post (its access, and a draft that is the viewer's).
     */
    public function canRead(Entity $entity): bool;

    /**
     * The post's owner, null when it has none.
     */
    public function owner(Entity $entity): ?User;

    /**
     * The address the post has now: the one an annotation's address is built from.
     */
    public function url(Entity $entity): string;

    public function title(Entity $entity): string;

    /**
     * The title of the list of the post's annotations, in the language of the request (`Annotations on: <the post's title>`).
     */
    public function listTitle(Entity $entity): string;

    public function shortDescription(Entity $entity): string;

    /**
     * The key the post stores the annotation an address names under, whatever address the post had when the annotation was made: the annotation's own id (the
     * last part of its address) decides. Null when the post holds no such annotation.
     */
    public function keyOf(Entity $entity, string $id): ?string;

    /**
     * The annotation stored under a key: false when there is none.
     */
    public function annotation(Entity $entity, string $permalink): mixed;

    /**
     * The kind of the annotation stored under a key (`reply`, `like`, `share`, `mention`, `rsvp`); null when there is none.
     */
    public function subtype(Entity $entity, string $permalink): ?string;

    /**
     * Every annotation of the post: its kinds, each a list of annotations by the key they are stored under.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function all(Entity $entity): array;

    /**
     * Whether the viewer may remove the annotation (the post's editor, or the annotation's author).
     */
    public function canEditAnnotation(Entity $entity, string $permalink): bool;

    /**
     * Removes the annotation and saves the post, as the viewer. Whether it was removed and saved.
     */
    public function remove(Entity $entity, string $permalink): bool;

    /**
     * The request's template type (`default`, `json`, `rss`, ...).
     */
    public function templateType(): string;

    /**
     * Takes variables off the page's template, which an annotation drawn into the page left there.
     *
     * @param list<string> $names
     */
    public function forgetTemplateVariables(array $names): void;
}
