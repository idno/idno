<?php

namespace IdnoPlugins\Like\Pages {

    class Edit extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $title = \Idno\Core\Idno::site()->language()->_('Edit bookmark');
                $object = \IdnoPlugins\Like\Like::getByID($this->arguments[0]);
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('New bookmark');
                $object = new \IdnoPlugins\Like\Like();
                $object->pageTitle = ($object->getTitleFromURL($this->getInput('url')));
            }

            if (!$object) $this->noContent();

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $edit_body = $t->__(array(
                'object' => $object,
                'url' => $this->getInput('url')
            ))->draw('entity/Like/edit');

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

            $new = false;
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Like\Like::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $object = new \IdnoPlugins\Like\Like();
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

        }

    }

}
