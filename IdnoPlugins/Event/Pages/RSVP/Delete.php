<?php

namespace IdnoPlugins\Event\Pages\RSVP {

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
            if (empty($object)) {
                $this->forward();

                return; // A script's request is not forwarded
            }
            // Ends the request for anybody who may not delete this
            $this->deleteGatekeeper($object);

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('%s was deleted.', [$object->getTitle()]));
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("We couldn't delete %s.", [$object->getTitle()]));
            }
            $this->forwardAfterDelete($object);
        }

    }

}

