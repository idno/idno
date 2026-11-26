<?php

namespace IdnoPlugins\Video\Pages {

    class Delete extends \Idno\Common\Page
    {

        function getContent()
        {
            // Deleting is done with a POST from the post's menu
            $this->createGatekeeper();
            $this->forward();
        }

        function postContent()
        {
            $this->createGatekeeper();

            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Video\Video::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $this->forward();

                return; // A script's request is not forwarded
            }
            // Ends the request for anybody who may not delete this
            $this->deleteGatekeeper($object);

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your video was deleted.'));
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("We couldn't delete %s.", [$object->getTitle()]));
            }
            $this->forwardAfterDelete($object);
        }

    }

}
