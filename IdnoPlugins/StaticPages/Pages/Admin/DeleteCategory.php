<?php

namespace IdnoPlugins\StaticPages\Pages\Admin {

    use Idno\Common\Page;

    class DeleteCategory extends Page
    {

        function getContent()
        {
            $this->adminGatekeeper();
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');
        }

        function postContent()
        {
            $this->adminGatekeeper();

            $category = $this->getInput('category');
            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {

                $staticpages->deleteCategory($category);

            }
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/staticpages/');

        }

    }

}

