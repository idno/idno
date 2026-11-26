<?php

namespace IdnoPlugins\StaticPages\Pages\Admin {

    use Idno\Common\Page;

    class Categories extends Page
    {

        function getContent()
        {
            $this->adminGatekeeper();
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');
        }

        function postContent()
        {
            $this->adminGatekeeper();

            $categories = $this->getInput('categories');
            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {
                $staticpages->saveCategories($categories);
            }
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');

        }

    }

}

