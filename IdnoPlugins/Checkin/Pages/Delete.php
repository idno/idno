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
            if (!$this->mayDeleteRequestedEntity($object ?? false)) {
                return;
            }

            if ($object->delete()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your checkin was deleted.'));
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("We couldn't delete %s.", [$object->getTitle()]));
            }
            $this->forward(\Idno\Http\RedirectTarget::sanitize((string) ($_SERVER['HTTP_REFERER'] ?? ''), \Idno\Core\Idno::site()->config()->getDisplayURL(), \Idno\Core\Idno::site()->config()->getURL()));
        }

    }

}

