<?php

namespace IdnoPlugins\StaticPages {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use Idno\Core\Time;
    use Idno\Http\Page\PageContext;
    use Idno\Http\Page\PageRenderer;
    use Symfony\Component\HttpFoundation\Response;

    /**
     * The StaticPages plugin's answer to the home page's replacement event (`homepage/replace`, see `Idno\Http\Controller\Home\HomePages::home()`):
     * when a static page is the site's home page, the page is the home page, drawn as the page's own address (`/pages/<slug>`) draws it, in the
     * shell, as a permalink of its owner. A home page that is gone or is no static page is unset (by an administrator: the
     * setting is theirs to change) and nothing replaces the home page; a page the viewer may not read (its access, a draft that is not theirs) is no home page for that viewer, who is served the ordinary one; a page that forwards sends a visitor to its address (an administrator
     * sees the page). The response is drawn through the renderer the event gives (nothing is echoed or ended), and what it answers is the
     * viewer's: the page's access is checked here, for the viewer, before anything of it is drawn.
     */
    class HomepageReplacement
    {

        public function __construct(private readonly Main $plugin)
        {
        }

        /**
         * @return Response|null the home page, or null when no static page is chosen (the stream is the home page)
         */
        public function respond(PageContext $page, PageRenderer $renderer): ?Response
        {
            $id = $this->plugin->getCurrentHomepageId();
            if (empty($id)) {
                return null;
            }

            $object = Entity::getByID($id);
            if (empty($object)) {
                $object = Entity::getBySlug($id);
            }

            // If the object doesn't exist or is invalid, unset the homepage (an administrator's to change; for anyone else it stays) and
            // let the ordinary home page be served
            if (empty($object) || !($object instanceof StaticPage)) {
                $this->plugin->clearHomepage();

                return null;
            }

            // The viewer's own access to the page, as its own address checks it (its access, and who may see it unpublished): what the
            // viewer may not read is not the home page for them, and is not named to them by a refusal or a redirect either
            if (!$object->canRead()) {
                return null;
            }

            // Forward if necessary
            if (!empty($object->forward_url) && !Idno::site()->session()->isAdmin()) {
                return $renderer->redirect($page, $object->forward_url);
            }

            $owner = $object->getOwner();
            $page->setOwner($owner instanceof \Idno\Entities\User ? $owner : null);
            $page->setPermalink(); // This is a permalink
            $page->addHeader('Last-Modified', Time::timestampToRFC2616((int) $object->updated)); // Say when this was last modified

            return $renderer->page($page, [
                'title'       => $object->getTitle(),
                'body'        => $renderer->template('staticpages/page', ['object' => $object]),
                'description' => $object->getShortDescription(),
            ]);
        }

    }

}
