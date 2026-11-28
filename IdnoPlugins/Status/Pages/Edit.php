<?php

namespace IdnoPlugins\Status\Pages {

    class Edit extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Status\Status::getByID($this->arguments[0]);
            } else {
                $object = \IdnoPlugins\Status\Status::factory();
            }

            if (!$object) $this->noContent();

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $edit_body = $t->__(array(
                'object' => $object,
                'url' => $this->getInput('url'),
                'body' => $this->getInput('body'),
                'tags' => $this->getInput('tags')
            ))->draw('entity/Status/edit');

            $body = $t->__(['body' => $edit_body])->draw('entity/editwrapper');

            if (empty($object)) {
                $title = \Idno\Core\Idno::site()->language()->_('What are you up to?');
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('Edit status update');
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
                $object = \IdnoPlugins\Status\Status::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $object = \IdnoPlugins\Status\Status::factory();
            }

            $this->editGatekeeper($object);

            if ($object->saveDataFromInput()) {
                if ($object->getPublishStatus() === 'draft') {
                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your draft has been saved.'));
                    $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'drafts/');
                } else {
                    $forward = \Idno\Http\RedirectTarget::sanitize((string) ($this->getInput('forward-to', $object->getDisplayURL())), \Idno\Core\Idno::site()->config()->getDisplayURL(), \Idno\Core\Idno::site()->config()->getURL());
                    $this->forward($forward);
                }
            }

        }

    }

}
