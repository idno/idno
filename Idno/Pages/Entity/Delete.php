<?php

    /**
     * Generic, backup viewer for entities
     */

namespace Idno\Pages\Entity {

    use Idno\Core\Idno;

    /**
     * Default class to serve the homepage
     */
    class Delete extends \Idno\Common\Page
    {

        // Handle GET requests to the entity

        function getContent()
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $this->forward(); // TODO: 404
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->__(
                array(

                'title' => $object->getTitle(),
                'body'  => $object->draw()

                )
            )->drawPage();
        }

        // Handle POST requests to the entity

        function postContent()
        {
            $this->gatekeeper();

            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("We couldn't find the post to delete."));
                $this->forward();
            } // TODO: 404
            if (!$object->canEdit()) {
                $this->deniedContent();
            }

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('%s was deleted.', [$object->getTitle()]));
            }
            $this->forward(\Idno\Pages\Session\Login::sanitizeRedirectUrl($_SERVER['HTTP_REFERER'] ?? ''));
        }

    }

}

