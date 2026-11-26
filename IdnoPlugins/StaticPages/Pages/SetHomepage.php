<?php

namespace IdnoPlugins\StaticPages\Pages {

    use Idno\Common\Page;

    class SetHomepage extends Page
    {

        function getContent()
        {
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/?test=0');
        }

        function postContent()
        {
            $this->adminGatekeeper();

            if (!empty($this->arguments)) {
                $object = \IdnoPlugins\StaticPages\StaticPage::getByID($this->arguments[0]);
            }
            if (empty($object)) {
                $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/?test=1');

                return;
            }
            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {

                $success = $staticpages->setAsHomepage($this->arguments[0]);

            }
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/?test=2');

        }

    }

}

