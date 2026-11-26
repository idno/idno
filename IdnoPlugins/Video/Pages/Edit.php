<?php

namespace IdnoPlugins\Video\Pages {

    class Edit extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $title = \Idno\Core\Idno::site()->language()->_('Edit video');
                $object = \IdnoPlugins\Video\Video::getByID($this->arguments[0]);
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('New video');
                $object = new \IdnoPlugins\Video\Video();
            }

            if (!$object) {
                $this->noContent();
            }

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $edit_body = $t->__(array(
                'object' => $object,
                'url' => $this->getInput('url')
            ))->draw('entity/Video/edit');

            $body = $t->__(['body' => $edit_body])->draw('entity/editwrapper');

            if (!empty($this->xhr)) {
                echo $body;
            } else {
                $t->__(array('body' => $body, 'title' => $title))->drawPage();
            }
        }

        function postContent()
        {
            $this->createGatekeeper();

            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Video\Video::getByID($this->arguments[0]);
                if (empty($object)) {
                    $this->noContent();
                }
                if (!$object->canEdit()) {
                    $this->deniedContent();
                }
            } else {
                $object = new \IdnoPlugins\Video\Video();
            }

            // Before any of the request is read into a post that exists
            $this->editGatekeeper($object);

            if ($object->saveDataFromInput()) {
                if ($object->getPublishStatus() === 'draft') {
                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your draft has been saved.'));
                    $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'drafts/');
                } else {
                    $forward = $this->forwardToTarget($object->getDisplayURL());
                    $this->forward($forward);
                }
            }
            // Not saved (the message says why): back to the form
            $this->forward(empty($object->_id) ? \Idno\Core\Idno::site()->config()->getDisplayURL() . 'video/edit' : $object->getEditURL());

        }

    }

}
