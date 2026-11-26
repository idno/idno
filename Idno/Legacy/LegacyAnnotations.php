<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Controller\Permalink\Annotations;

/**
 * What the annotation pages ask the site, over the legacy application: what `Idno\Pages\Annotation\View` and `Delete` asked, moved here unchanged, so the same
 * lookups are made in the same order and the same events fire.
 */
final class LegacyAnnotations implements Annotations
{
    public function find(string $idOrSlug): ?Entity
    {
        $entity = Entity::getByID($idOrSlug);
        if (empty($entity)) {
            $entity = Entity::getBySlug($idOrSlug);
        }

        return $entity instanceof Entity ? $entity : null;
    }

    public function canRead(Entity $entity): bool
    {
        return (bool) $entity->canRead();
    }

    public function owner(Entity $entity): ?User
    {
        $owner = $entity->getOwner();

        return $owner instanceof User ? $owner : null;
    }

    public function url(Entity $entity): string
    {
        return (string) $entity->getURL();
    }

    public function title(Entity $entity): string
    {
        return (string) $entity->getTitle();
    }

    public function listTitle(Entity $entity): string
    {
        return Idno::site()->language()->_('Annotations on: %s', [(string) $entity->getTitle()]);
    }

    public function shortDescription(Entity $entity): string
    {
        return (string) $entity->getShortDescription();
    }

    public function keyOf(Entity $entity, string $id): ?string
    {
        // the address the post has now first, as the page always looked for it
        $own = $entity->getURL() . '/annotations/' . $id;
        if ($entity->getAnnotation($own) !== false) {
            return $own;
        }
        // an annotation made while the post had another address is stored under that one: its own id is what an address names
        $suffix = '/annotations/' . $id;
        foreach ($entity->getAllAnnotations() as $list) {
            foreach (is_array($list) ? array_keys($list) : [] as $key) {
                if (str_ends_with((string) $key, $suffix)) {
                    return (string) $key;
                }
            }
        }

        return null;
    }

    public function annotation(Entity $entity, string $permalink): mixed
    {
        $annotation = $entity->getAnnotation($permalink);

        return is_array($annotation) ? $entity->withCanonicalAnnotationAddresses([$annotation])[0] : $annotation;
    }

    public function subtype(Entity $entity, string $permalink): ?string
    {
        $subtype = $entity->getAnnotationSubtype($permalink);

        return is_string($subtype) ? $subtype : null;
    }

    public function all(Entity $entity): array
    {
        $all = [];
        foreach ($entity->getAllAnnotations() as $subtype => $list) {
            $all[$subtype] = is_array($list) ? $entity->withCanonicalAnnotationAddresses($list) : $list;
        }

        return $all;
    }

    public function canEditAnnotation(Entity $entity, string $permalink): bool
    {
        return (bool) $entity->canEditAnnotation($permalink);
    }

    public function remove(Entity $entity, string $permalink): bool
    {
        return (bool) $entity->removeAnnotation($permalink) && (bool) $entity->save();
    }

    public function templateType(): string
    {
        return (string) Idno::site()->template()->getTemplateType();
    }

    public function forgetTemplateVariables(array $names): void
    {
        Idno::site()->template()->forgetVariables($names);
    }
}
