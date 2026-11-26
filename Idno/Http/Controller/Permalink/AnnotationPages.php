<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Permalink;

use Idno\Common\Entity;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The annotation pages of a post: the list of its annotations, one annotation, the delete of an annotation, and the redirect of an annotation's address in
 * a layout the site no longer has. The one implementation: `AnnotationController` answers the requests the router hands it.
 */
final class AnnotationPages
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly Annotations $annotations,
    )
    {
    }

    /**
     * The annotations of a post (arguments `[post, annotation?]`), or one of them. A post that is not there, and one the viewer may not read, are the same answer:
     * the site's missing-content page (410): the finders leave out a post the viewer's read groups do not name, and the post's own `canRead()` is asked again
     * here, so that a post is never described to a viewer who may not read it.
     */
    public function show(PageContext $page): Response
    {
        $entity = $this->readableEntityOf($page);
        if ($entity === null) {
            return $this->renderer->gone($page);
        }
        $page->setOwner($this->annotations->owner($entity));

        $id = (string) ($page->arguments()[1] ?? '');
        if (!in_array($id, ['', '0'], true)) {
            return $this->one($page, $entity, $id);
        }

        return $this->list($page, $entity);
    }

    /**
     * What a POST to an annotation's delete address does: the post's editor, or the annotation's author, removes the annotation (the post is saved as the
     * viewer: an author who is not the post's editor is refused by the save, and nothing is removed); whoever asked is sent back to the post's comments.
     * Nothing is changed before the rights are known, and a post the viewer may not read is answered as a missing one is.
     */
    public function delete(PageContext $page): Response
    {
        $entity = $this->readableEntityOf($page);
        if ($entity === null) {
            return $this->renderer->gone($page);
        }

        $posted = $page->input('permalink');
        $permalink = is_string($posted) ? $this->decoded($posted) : '';
        if (in_array($permalink, ['', '0'], true)) {
            // the annotation the address names, as the post stores it, else the address it would have now
            $id = (string) ($page->arguments()[1] ?? '');
            $permalink = $this->annotations->keyOf($entity, $id) ?? $this->annotations->url($entity) . '/annotations/' . $id;
        }
        if ($this->annotations->canEditAnnotation($entity, $permalink)) {
            $this->annotations->remove($entity, $permalink);
        }

        return $this->renderer->redirect($page, $this->annotations->url($entity) . '#comments');
    }

    /**
     * An annotation's address in a layout the site no longer has (`/YYYY/MM/<slug>/annotations/<id>` and the one with the day), sent permanently to the address
     * the annotation has: a link made then keeps working. The target is built from the post, never from the request; a post that is not there and one the viewer
     * may not read are the same answer (410).
     */
    public function otherLayout(PageContext $page): Response
    {
        $entity = $this->readableEntityOf($page);
        if ($entity === null) {
            return $this->renderer->gone($page);
        }
        $id = (string) ($page->arguments()[1] ?? '');

        return $this->renderer->redirect($page, $this->annotations->url($entity) . '/annotations/' . $id, 301);
    }

    private function one(PageContext $page, Entity $entity, string $id): Response
    {
        // the address the post has now first; an annotation made while the post had another address is found by its own id
        $permalink = $this->annotations->keyOf($entity, $id) ?? $this->annotations->url($entity) . '/annotations/' . $id;
        $annotation = $this->annotations->annotation($entity, $permalink);
        $subtype = $this->annotations->subtype($entity, $permalink) ?? false;

        $page->setPermalink();

        return $this->renderer->page($page, [
            'title' => $this->annotations->title($entity),
            'body' => $this->renderer->template('entity/annotations/shell', ['annotation' => $annotation, 'subtype' => $subtype, 'permalink' => $permalink, 'object' => $entity]),
            'description' => $this->annotations->shortDescription($entity),
        ]);
    }

    private function list(PageContext $page, Entity $entity): Response
    {
        $rss = $this->annotations->templateType() === 'rss';
        $body = '';
        $items = [];
        foreach ($this->annotations->all($entity) as $subtype => $list) {
            foreach ($list as $permalink => $annotation) {
                $body .= $this->renderer->template('entity/annotations/shell', ['annotation' => $annotation, 'subtype' => $subtype, 'permalink' => $permalink, 'object' => $entity]);
                // what the shell drew is not left on the page; an RSS list does not name the post either (it lists the annotations)
                $this->annotations->forgetTemplateVariables(['annotation', 'annotation_permalink', 'annotations', 'action', 'subtype', 'permalink', ...($rss ? ['object'] : [])]);
                $items[] = $annotation;
            }
        }

        $vars = [
            'title' => $this->annotations->listTitle($entity),
            'body' => $body,
            'description' => $this->annotations->shortDescription($entity),
        ];
        if ($rss) {
            $vars = ['annotations' => $items] + $vars;
        }

        return $this->renderer->page($page, $vars);
    }

    private function readableEntityOf(PageContext $page): ?Entity
    {
        $key = (string) ($page->arguments()[0] ?? '');
        // (the legacy pages asked `empty()` of the argument: '0' is no address)
        $entity = !in_array($key, ['', '0'], true) ? $this->annotations->find($key) : null;

        return $entity !== null && $this->annotations->canRead($entity) ? $entity : null;
    }

    /** What an address's `permalink` field holds: the address, written in base64 with `-_,` for `+/=`. */
    private function decoded(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_,', '+/='));
    }
}
