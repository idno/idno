<?php

namespace IdnoPlugins\Event\Pages\RSVP {

    class Edit extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Event\RSVP::getByID($this->arguments[0]);
            } else {
                $object = new \IdnoPlugins\Event\RSVP();
            }

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $body = $t->__(array(
                'object' => $object,
                'url' => $this->getInput('url')
            ))->draw('entity/RSVP/edit');

            if (empty($object)) {
                $title = \Idno\Core\Idno::site()->language()->_('Write an RSVP');
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('Edit RSVP');
            }

            if (!empty($this->xhr)) {
                echo $body;
            } else {
                $t->__(array('body' => $body, 'title' => $title))->drawPage();
            }
        }

        function postContent()
        {
            $this->createGatekeeper();

            $new = false;
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Event\RSVP::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $object = new \IdnoPlugins\Event\RSVP();
            }

            $this->editGatekeeper($object);

            if ($object->saveDataFromInput()) {
                $this->forward($object->getDisplayURL());
            }

        }

    }

}

