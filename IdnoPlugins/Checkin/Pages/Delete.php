<?php

namespace IdnoPlugins\Checkin\Pages {

    class Delete extends \Idno\Common\Page
    {

        function postContent()
        {
            $this->createGatekeeper();

            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\Checkin\Checkin::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $this->forward();

                return; // A script's request is not forwarded
            }
            // Ends the request for anybody who may not delete this
            $this->deleteGatekeeper($object);

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your check-in was deleted.'));
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("We couldn't delete %s.", [$object->getTitle()]));
            }
            $this->forwardAfterDelete($object);
        }

    }

}

