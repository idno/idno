<?php

namespace IdnoPlugins\StaticPages\Pages {

    use Idno\Common\Page;

    class ClearHomepage extends Page
    {

        function getContent()
        {
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');
        }

        function postContent()
        {
            $this->adminGatekeeper();

            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\StaticPages\StaticPage::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');

                return;
            }
            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {

                if ($staticpages->getCurrentHomepageId() == $this->arguments[0]) {
                    $staticpages->clearHomepage();
                }

            }
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');

        }

    }

}
