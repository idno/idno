<?php

/**
 * Administration homepage
 */

namespace Idno\Pages\Admin {

    /**
     * Default class to serve the homepage
     */
    class Home extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only

            $t = \Idno\Core\Idno::site()->template();
            $t->body = $t->draw('admin/home');
            $t->title = \Idno\Core\Idno::site()->language()->_('Administration');
            $t->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only
            $title = $this->getInput('title');
            $homepagetitle = $this->getInput('homepagetitle');
            $description = $this->getInput('description');
            $url = rtrim($this->getInput('url'), ' /') . '/';
            $path = dirname(dirname(dirname(dirname(__FILE__)))); // Path is more safely derived from install location
            if (!empty($url)) {
                $host = parse_url($url, PHP_URL_HOST); // Host can be safely derived from URL
            }
            $hub = $this->getInput('hub'); // WebSub hub
            $open_registration = $this->getInput('open_registration') === 'true';
            $walled_garden = $this->getInput('walled_garden') === 'true' && \Idno\Core\Idno::site()->config()->canMakeSitePrivate();
            $show_privacy = $this->getInput('show_privacy') === 'true';
            $indieweb_citation = $this->getInput('indieweb_citation') === 'true';
            $indieweb_reference = $this->getInput('indieweb_reference') === 'true';
            $user_avatar_favicons = $this->getInput('user_avatar_favicons') === 'true';
            $share_backup_url = $this->getInput('share_backup_url');
            $items_per_page = (int)$this->getInput('items_per_page');
            $single_user = $this->getInput('single_user') === 'true';
            $permalink_structure = $this->getInput('permalink_structure');

            if (!empty($title)) {
                \Idno\Core\Idno::site()->config()->title = $title;
            }
            \Idno\Core\Idno::site()->config()->homepagetitle = trim($homepagetitle);
            if (!empty($description)) {
                \Idno\Core\Idno::site()->config()->description = $description;
            }
            if (!empty($url)) {
                \Idno\Core\Idno::site()->config()->url = $url;
            }
            if (!empty($path)) {
                \Idno\Core\Idno::site()->config()->path = $path;
            }
            if (!empty($host)) {
                \Idno\Core\Idno::site()->config()->host = $host;
            }
            \Idno\Core\Idno::site()->config()->hub = $hub;
            if (!empty($items_per_page) && is_int($items_per_page)) {
                \Idno\Core\Idno::site()->config()->items_per_page = $items_per_page;
            }
            \Idno\Core\Idno::site()->config()->open_registration = $open_registration;
            \Idno\Core\Idno::site()->config()->walled_garden = $walled_garden;
            \Idno\Core\Idno::site()->config()->show_privacy = $show_privacy;
            \Idno\Core\Idno::site()->config()->indieweb_citation = $indieweb_citation;
            \Idno\Core\Idno::site()->config()->indieweb_reference = $indieweb_reference;
            \Idno\Core\Idno::site()->config()->user_avatar_favicons = $user_avatar_favicons;
            \Idno\Core\Idno::site()->config()->share_backup_url = $share_backup_url;
            \Idno\Core\Idno::site()->config()->single_user = $single_user;
            \Idno\Core\Idno::site()->config()->permalink_structure = $permalink_structure;

            \Idno\Core\Idno::site()->events()->triggerEvent('admin/home/save');

            \Idno\Core\Idno::site()->config()->save();
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/');
        }

    }

}
