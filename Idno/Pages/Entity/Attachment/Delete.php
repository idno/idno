<?php

namespace Idno\Pages\Entity\Attachment {

    use Idno\Core\Idno;


    class Delete extends \Idno\Common\Page
    {

        // Handle POST requests to the entity

        function postContent()
        {
            $this->gatekeeper();

            if (!empty($this->arguments[0])) {
                $object = \Idno\Common\Entity::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("We couldn't find the post."));
                $this->goneContent();
            }
            if (!$object->canEdit()) {
                $this->deniedContent();
            }

            if (!empty($this->arguments[1])) {
                $attachment_id = $this->arguments[1];

                $object->deleteAttachment($attachment_id);
                $object->save();
            } else {
                $this->noContent();
            }

            $this->forwardBack();
        }

    }

}

