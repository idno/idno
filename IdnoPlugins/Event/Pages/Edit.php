<?php

namespace IdnoPlugins\Event\Pages {

    use Idno\Pages\Entity\Autosave;

    class Edit extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Event\Event::getByID($this->arguments[0]);
            } else {
                $object = new \IdnoPlugins\Event\Event();
                $autosave = new \Idno\Core\Autosave();
                foreach (array(
                    'title', 'summary', 'location', 'starttime', 'endtime', 'body'
                ) as $field) {
                    $object->$field = $autosave->getValue('event', $field);
                }
            }

            if (!$object) $this->noContent();

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $edit_body = $t->__(array(
                'object' => $object
            ))->draw('entity/Event/edit');

            $body = $t->__(['body' => $edit_body])->draw('entity/editwrapper');

            if (empty($object)) {
                $title = \Idno\Core\Idno::site()->language()->_('Write an event');
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('Edit event');
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
                $object = \IdnoPlugins\Event\Event::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $object = new \IdnoPlugins\Event\Event();
            }

            $this->editGatekeeper($object);

            if ($object->saveDataFromInput()) {
                (new \Idno\Core\Autosave())->clearContext('event');
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
