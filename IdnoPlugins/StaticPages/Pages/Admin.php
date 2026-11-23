<?php

namespace IdnoPlugins\StaticPages\Pages {

    use Idno\Common\Page;

    class Admin extends Page
    {

        function getContent()
        {

            $this->adminGatekeeper();
            $staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages');
            $categories  = $staticpages->getCategories();
            $pages       = $staticpages->getPagesAndCategories();
            $counts      = [];
            foreach ($pages as $category => $inCategory) {
                $counts[$category] = count($inCategory);
            }
            $t           = \Idno\Core\Idno::site()->template();
            $t->__(['categories' => $categories, 'pages' => $pages]);
            // The number of pages in each category is for the body's own draw: it is no variable of the page
            $body        = $t->withVars(array_merge($t->vars, ['category_counts' => $counts]))->draw('staticpages/admin');
            \Idno\Core\Idno::site()->template()->__([
                'title' => \Idno\Core\Idno::site()->language()->_('Pages'), 'body' => $body
            ])->drawPage();

        }

        function postContent()
        {

            $this->adminGatekeeper();

        }

    }

}

