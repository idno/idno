<?php

namespace IdnoPlugins\Photo\Pages {

    class Edit extends \Idno\Common\Page
    {

        /// The session key under which the text of a refused form is kept for the next draw of the form
        const KEPT_INPUT = 'photo_form_kept_input';

        function getContent()
        {

            $this->createGatekeeper();    // This functionality is for logged-in users only

            // Are we loading an entity?
            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Photo\Photo::getByID($this->arguments[0]);
            } else {
                $object = new \IdnoPlugins\Photo\Photo();
            }

            if (!$object) $this->noContent();

            // What the member typed into a form that was refused (no photo, or a photo
            // the server would not take) is put back; the file itself has to be chosen again
            $session = \Idno\Core\Idno::site()->session();
            $kept = $session->get(self::KEPT_INPUT);
            if (is_array($kept)) {
                $session->remove(self::KEPT_INPUT);
                if (($kept['id'] ?? '') === (string) $object->getID() && (empty($object->_id) || $object->canEdit())) {
                    $object->title = (string) ($kept['title'] ?? '');
                    $object->body = \Idno\Core\Idno::site()->template()->sanitize_html((string) ($kept['body'] ?? ''));
                    $object->tags = $kept['tags'] ?? null;
                }
            }

            if ($owner = $object->getOwner()) {
                $this->setOwner($owner);
            }

            $t = \Idno\Core\Idno::site()->template();
            $edit_body = $t->__(array(
                'object' => $object
            ))->draw('entity/Photo/edit');

            $body = $t->__(['body' => $edit_body])->draw('entity/editwrapper');

            if (empty($object)) {
                $title = \Idno\Core\Idno::site()->language()->_('Upload a photo');
            } else {
                $title = \Idno\Core\Idno::site()->language()->_('Edit photo details');
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
                $object = \IdnoPlugins\Photo\Photo::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $object = new \IdnoPlugins\Photo\Photo();
            }

            if ($object->saveDataFromInput()) {
                if ($object->getPublishStatus() === 'draft') {
                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your draft has been saved.'));
                    $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'drafts/');
                } else {
                    $forward = $this->getInput('forward-to', $object->getDisplayURL());
                    $this->forward($forward);
                }
            } else if (!\Idno\Core\Idno::site()->session()->isAPIRequest()) {

                // Nothing was saved, and the session holds the reason. Go back to the form
                // with what was typed, rather than to wherever the member came from
                \Idno\Core\Idno::site()->session()->set(self::KEPT_INPUT, [
                    'id' => (string) $object->getID(),
                    'title' => (string) $this->getInput('title'),
                    'body' => (string) $this->getInput('body'),
                    'tags' => $this->getInput('tags'),
                ]);
                $this->forward(empty($object->_id) ? \Idno\Core\Idno::site()->config()->getDisplayURL() . 'photo/edit/' : $object->getEditURL());
            }

        }

    }

}
