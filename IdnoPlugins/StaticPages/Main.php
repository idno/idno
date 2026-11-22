<?php

namespace IdnoPlugins\StaticPages {

    use Idno\Common\Plugin;

    class Main extends Plugin
    {

        public $cats_and_pages = [];

        function registerTranslations()
        {

            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'staticpages', dirname(__FILE__) . '/languages/'
                )
            );
        }

        function registerPages()
        {

            \Idno\Core\Idno::site()->routes()->addRoute('/staticpages?/edit/?', 'IdnoPlugins\StaticPages\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/staticpages?/edit/([A-Za-z0-9\-]+)/?', '\IdnoPlugins\StaticPages\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/staticpages?/delete/([A-Za-z0-9\-]+)/?', '\IdnoPlugins\StaticPages\Pages\Delete');
            \Idno\Core\Idno::site()->routes()->addRoute('/staticpages?/homepage/set/([A-Za-z0-9\-]+)/?', 'IdnoPlugins\StaticPages\Pages\SetHomepage');
            \Idno\Core\Idno::site()->routes()->addRoute('/staticpages?/homepage/clear/([A-Za-z0-9\-]+)/?', 'IdnoPlugins\StaticPages\Pages\ClearHomepage');

            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/?', 'IdnoPlugins\StaticPages\Pages\Admin');
            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/add/?', 'IdnoPlugins\StaticPages\Pages\Admin\AddCategory');
            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/edit/?', 'IdnoPlugins\StaticPages\Pages\Admin\EditCategory');
            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/delete/?', 'IdnoPlugins\StaticPages\Pages\Admin\DeleteCategory');
            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/categories/?', 'IdnoPlugins\StaticPages\Pages\Admin\Categories');
            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/reorder/?', 'IdnoPlugins\StaticPages\Pages\Admin\ReorderCategory');
            \Idno\Core\Idno::site()->routes()->addRoute('/admin/staticpages/reorder/page/?', 'IdnoPlugins\StaticPages\Pages\Admin\ReorderPage');

            \Idno\Core\Idno::site()->routes()->addRoute('/pages/([^/.]+)/?', 'IdnoPlugins\StaticPages\Pages\View');

            // This makes sure that the homepage is accessible even when it is overridden.
            \Idno\Core\Idno::site()->routes()->addRoute('/content/default/?', 'Idno\Pages\Homepage');

            if (!empty($this->getCurrentHomepageId())) {
                \Idno\Core\Idno::site()->routes()->hijackRoute('', 'IdnoPlugins\StaticPages\Pages\Homepage');
                \Idno\Core\Idno::site()->routes()->hijackRoute('/', 'IdnoPlugins\StaticPages\Pages\Homepage');
            }

            \Idno\Core\Idno::site()->template()->extendTemplate('admin/menu/items', 'staticpages/admin/menu');
            \Idno\Core\Idno::site()->template()->prependTemplate('shell/toolbar/links', 'staticpages/toolbar', true);
        }

        /**
         * Sets a static page as the homepage, overwriting the current setting if one is set.
         * @param $pageId
         * @return bool
         */
        function setAsHomepage($pageId)
        {

            if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
                if (\Idno\Core\Idno::site()->session()->currentUser()->isAdmin()) {
                    $obj = \Idno\Common\Entity::getByID($pageId);
                    if (!empty($obj)) {
                        \Idno\Core\Idno::site()->config()->staticPages['homepage'] = $pageId;
                        return \Idno\Core\Idno::site()->config()->save();
                    }
                }
            }

            return false;

        }

        /**
         * Removes a previously set static page from acting as the homepage. This will re-enable Idno's default
         * behavior.
         * @return bool
         */
        function clearHomepage()
        {

            if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
                if (\Idno\Core\Idno::site()->session()->currentUser()->isAdmin()) {
                    unset(\Idno\Core\Idno::site()->config()->staticPages['homepage']);
                    return \Idno\Core\Idno::site()->config()->save();
                }
            }

            return false;

        }

        /**
         * Gets the ID of the page which is currently acting as the homepage, if any is set.
         * @return string
         */
        function getCurrentHomepageId()
        {
            if (!empty(\Idno\Core\Idno::site()->config()->staticPages['homepage'])) {
                return \Idno\Core\Idno::site()->config()->staticPages['homepage'];
            }
            return false;
        }

        /**
         * Save static page categories
         *
         * @param $categories
         * @return bool
         */
        function saveCategories($categories)
        {

            if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
                if (\Idno\Core\Idno::site()->session()->currentUser()->isAdmin()) {

                    if (is_array($categories)) {
                        $categories = implode("\n", $categories);
                    }
                    \Idno\Core\Idno::site()->config()->staticPages['categories'] = $categories;

                    return \Idno\Core\Idno::site()->config()->save();

                }
            }

            return false;

        }

        /**
         * Adds a category
         * @param $category
         * @return bool
         */
        function addCategory($category)
        {

            $category = trim($category);
            if (empty($category)) {
                return false;
            }
            $categories = $this->getCategories();

            $key = array_search($category, $categories);
            if ($key === false) {
                $categories[] = $category;
            }

            return $this->saveCategories($categories);

            return false;

        }

        /**
         * Removes a category
         * @param $category
         * @return bool
         */
        function deleteCategory($category)
        {

            if ($categories = $this->getCategories()) {

                $key = array_search($category, $categories);
                if ($key !== false) {
                    if ($pages = $this->getPagesByCategory($category)) {
                        foreach ($pages as $page) {
                            $page->category = 'No Category';
                            $page->save();
                        }
                    }
                    unset($categories[$key]);
                }

                return $this->saveCategories($categories);

            }

            return false;

        }

        /**
         * Renames a category
         * @param $category
         * @param $new_category
         * @return bool
         */
        function editCategory($category, $new_category)
        {

            if (empty($category) || empty($new_category)) {
                return false;
            }
            if ($categories = $this->getCategories()) {
                $key = array_search($category, $categories);
                if ($key !== false) {
                    if ($pages = $this->getPagesByCategory($category)) {
                        foreach ($pages as $page) {
                            $page->category = $new_category;
                            $page->save();
                        }
                    }
                    $categories[$key] = $new_category;
                }

                return $this->saveCategories($categories);
            }

            return false;

        }

        /**
         * Retrieves categories for static pages. You must have categories before you can create a page.
         * @return array
         */
        function getCategories()
        {

            if (!empty(\Idno\Core\Idno::site()->config()->staticPages['categories'])) {
                // Take the categories record and split it into an array
                $categories = str_replace("\r", '', \Idno\Core\Idno::site()->config()->staticPages['categories']);
                $categories = explode("\n", $categories);

                // Trim all categories first
                array_filter($categories, function ($var) {
                    return trim($var);
                });

                // Then remove any empty categories
                array_filter($categories);

                // Now send back the array
                return $categories;
            }

            return [];

        }

        /**
         * Given a category, retrieves all pages for that category.
         * @param $category
         * @return array
         */
        function getPagesByCategory($category)
        {
            $pages = StaticPage::get(['category' => $category], [], PHP_INT_MAX);
            if (!empty($pages)) {
                usort($pages, function ($left, $right) {
                    return $right->getPriority() - $left->getPriority();
                });
            }

            return $pages;
        }

        /**
         * Returns a 2d array with categories as the main keys and an array of pages (or false) as the entities
         * @param $force_refresh If set to true, never gets the cached version
         * @return array
         */
        function getPagesAndCategories($force_refresh = false)
        {

            if (!empty($this->cats_and_pages) && !$force_refresh) {
                return $this->cats_and_pages;
            }
            $pages      = [];
            $categories = $this->getCategories();
            $categories = array_merge(['No Category'], $categories);
            foreach ($categories as $category) {
                $pages[$category] = $this->getPagesByCategory($category);
            }
            $this->cats_and_pages = $pages;

            return $pages;

        }

        function getAdminIcon()
        {
            return 'file';
        }

    }

}
