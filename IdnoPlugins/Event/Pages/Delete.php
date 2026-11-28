<?php

namespace IdnoPlugins\Event\Pages {

    class Delete extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Event\Event::getByID($this->arguments[0]);
            } else {
                // TODO 404
                $this->forward();
            }
            if (empty($object)) {
                // a post that is not there (or that the member may not read) is no page to delete: it was a fatal error, drawing the title of nothing
                $this->forward();

                return;
            }

            $t = \Idno\Core\Idno::site()->template();
            $body = $t->__(array(
                'object' => $object
            ))->draw('entity/Event/delete');

            if (!empty($this->xhr)) {
                echo $body;
            } else {
                $t->__(array('body' => $body, 'title' => \Idno\Core\Idno::site()->language()->_("Delete %s", [$object->getTitle()])))->drawPage();
            }
        }

        function postContent()
        {
            $this->createGatekeeper();

            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Event\Event::getByID($this->arguments[0]);
            }
            if (!$this->mayDeleteRequestedEntity($object ?? false)) {
                return;
            }

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('%s was deleted.', [$object->getTitle()]));
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("We couldn't delete %s.", [$object->getTitle()]));
            }
            $this->forward(\Idno\Http\RedirectTarget::sanitize((string) ($_SERVER['HTTP_REFERER'] ?? ''), \Idno\Core\Idno::site()->config()->getDisplayURL(), \Idno\Core\Idno::site()->config()->getURL()));
        }

    }

}

