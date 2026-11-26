<?php

namespace IdnoPlugins\Text\Pages {

    use Idno\Core\Autosave;

    class Edit extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Text\Entry::getByID($this->arguments[0]);
            } else {
                $object = new \IdnoPlugins\Text\Entry();
                $autosave = new \Idno\Core\Autosave();
                foreach (array(
                    'title', 'body'
                ) as $field) {
                    $object->$field = $autosave->getValue('entry', $field);
                }
            }

            if (!$object) $this->noContent();

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $edit_body = $t->__(array(
                'object' => $object
            ))->draw('entity/Entry/edit');

            $body = $t->__(['body' => $edit_body])->draw('entity/editwrapper');

            if (empty($object->_id)) {
                $title = \Idno\Core\Idno::site()->language()->_('Write a post');
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('Edit post');
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
                $object = \IdnoPlugins\Text\Entry::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $object = new \IdnoPlugins\Text\Entry();
            }

            // Before any of the request is read into a post that exists
            $this->editGatekeeper($object);

            if ($object->saveDataFromInput()) {
                (new \Idno\Core\Autosave())->clearContext('entry');
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
