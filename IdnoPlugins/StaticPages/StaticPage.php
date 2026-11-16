<?php

namespace IdnoPlugins\StaticPages {

    use Idno\Common\Entity;
    use Idno\Entities\User;

    #[\Idno\Storage\Attribute\AsEntity('static_page')]
    class StaticPage extends Entity
    {

        function getTitle()
        {
            if (!empty($this->title)) {
                return $this->title;
            }

            return 'Untitled';
        }

        function getDescription()
        {
            if (!empty($this->body)) {
                return $this->body;
            }

            return '';
        }

        function getPriority()
        {
            if (!empty($this->priority)) {
                return $this->priority;
            }

            return 0;
        }

        function getSetAsHomepageURL()
        {
            return \Idno\Core\Idno::site()->config()->getDisplayURL() . $this->getClassSelector() . '/homepage/set/' . $this->getID();
        }

        function getClearHomepageURL()
        {
            return \Idno\Core\Idno::site()->config()->getDisplayURL() . $this->getClassSelector() . '/homepage/clear/' . $this->getID();
        }

        function isHomepage()
        {
            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {
                return $staticpages->getCurrentHomepageId() == $this->getID();
            }
            return false;
        }

        function getActivityStreamsObjectType()
        {
            return 'article';
        }

        function getURL()
        {

            // If we have a URL override, use it
            if (!empty($this->url)) {
                return $this->url;
            }

            if (!empty($this->canonical)) {
                return $this->canonical;
            }

            // If a slug has been set, use it
            if ($slug = $this->getSlug()) {
                return \Idno\Core\Idno::site()->config()->getURL() . 'pages/' . $slug;
            }

            $new = false;
            if ($args = func_get_args()) {
                if ($args[0] === true) {
                    $new = true;
                }
            }

            $id = $this->getID();
            if (!$new && !empty($id)) {
                $uuid = $this->getUUID();
                if (!empty($uuid)) {
                    return $uuid;
                }
            }

            return \Idno\Core\Idno::site()->config()->url . $this->getClassSelector() . '/edit';

        }

        function canEdit($user_id = '')
        {
            if (empty($user_id)) {
                $user = \Idno\Core\Idno::site()->session()->currentUser();
            } else {
                $user = User::getByUUID($user_id);
            }
            if (!($user instanceof User)) {
                return false;
            }
            if (!$user->isAdmin()) {
                return false;
            }

            return true;
        }

        function saveDataFromInput()
        {

            $body        = \Idno\Core\Idno::site()->currentPage()->getInput('body');
            $title       = \Idno\Core\Idno::site()->currentPage()->getInput('title');
            $category    = \Idno\Core\Idno::site()->currentPage()->getInput('category');
            $forward_url = \Idno\Core\Idno::site()->currentPage()->getInput('forward_url');
            $hide_title  = \Idno\Core\Idno::site()->currentPage()->getInput('hide_title');
            $access      = \Idno\Core\Idno::site()->currentPage()->getInput('access');

            if ($staticpages = \Idno\Core\Idno::site()->plugins()->get('StaticPages')) {
                /* @var IdnoPlugins\StaticPages\Main $staticpages */
                $categories = $staticpages->getCategories();
                if (in_array($category, $categories) || $category == 'No Category') {

                    $this->title       = $title;
                    $this->body        = $body;
                    $this->category    = $category;
                    $this->forward_url = $forward_url;
                    $this->hide_title  = $hide_title;
                    $this->setAccess($access);

                    if ($result = $this->publish()) {
                        return true;
                    }

                } else {
                    \Idno\Core\Idno::site()->session()->addMessage("Your selected category wasn't found in the list.");
                }
                    //}
            }

            return false;

        }

        function deleteData()
        {
        }

    }

}
