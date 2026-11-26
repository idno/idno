<?php

namespace IdnoPlugins\StaticPages\Pages\Admin {

    use Idno\Common\Page;

    class AddCategory extends Page
    {

        function getContent()
        {
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');
        }

        function postContent()
        {
            $this->adminGatekeeper();

            $category = $this->getInput('category');
            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {

                $staticpages->addCategory($category);

            }
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');

        }

    }

}

