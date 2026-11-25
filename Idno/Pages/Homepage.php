<?php

    /**
     * Defines the site homepage
     */

namespace Idno\Pages {

    use Idno\Core\Webmention;
    use Idno\Entities\User;

    /**
     * Default class to serve the homepage
     */
    class Homepage extends \Idno\Common\Page
    {

        // Handle GET requests to the homepage

        function getContent()
        {

            $query          = $this->getInput('q');
            $offset         = (int)$this->getInput('offset');
            $types          = $this->getInput('types');
            $friendly_types = array();

            // Check for an empty site
            if (!\Idno\Entities\User::get()) {
                // The site has no account yet: its administrator is made by the last step of the installation
                // (or `php bin/console idno:admin:create`), never by whoever arrives here first.
                $nonce = base64_encode(random_bytes(18));
                $this->setResponse(503);
                header('Content-Type: text/html; charset=UTF-8');
                header('Content-Security-Policy: ' . \Idno\Install\InstallPage::contentSecurityPolicy($nonce));
                header('Cache-Control: no-store');
                header('Retry-After: 300');
                echo \Idno\Install\InstallPage::pending($nonce);
                exit;
            }

            // Set the homepage owner for single-user sites
            if (!$this->getOwner() && \Idno\Core\Idno::site()->config()->single_user) {
                $owners = \Idno\Entities\User::get(['admin' => true]);
                if (!empty($owners) && count($owners) === 1) {
                    $this->setOwner($owners[0]);
                } else {
                    $number = 0;
                    if (!empty($owners)) {
                        $number = count($owners);
                    }
                    \Idno\Core\Idno::site()->logging()->warning("Expected exactly 1 admin user for single-user site; got $number");
                }
            }

            if (!empty($this->arguments[0])) { // If we're on the friendly content-specific URL
                if ($friendly_types = explode('/', $this->arguments[0])) {
                    $friendly_types = array_filter($friendly_types);
                    if (empty($friendly_types) && !empty($query)) {
                        $friendly_types = array('all');
                    }
                    $types = array();
                    // Run through the URL parameters and set content types appropriately
                    foreach ($friendly_types as $friendly_type) {
                        if ($friendly_type == 'all') {
                            $types = \Idno\Common\ContentType::getRegisteredClasses();
                            break;
                        }
                        if ($content_type_class = \Idno\Common\ContentType::categoryTitleToClass($friendly_type)) {
                            $types[] = $content_type_class;
                        }
                    }
                }
            } else {
                // If user has content-specific preferences, do something with $friendly_types
                if (empty($query)) {
                    $types = \Idno\Core\Idno::site()->config()->getHomepageContentTypes();
                }
            }

            $search = array();

            if (!empty($query)) {
                $search = \Idno\Core\Idno::site()->db()->createSearchArray($query);
            }

            if (empty($types)) {
                $types = \Idno\Common\ContentType::getRegisteredClasses();
            } else {
                $types = (array) $types;
            }

            $search['publish_status'] = 'published';

            $count = \Idno\Common\Entity::countFromX($types, $search);
            $feed  = \Idno\Common\Entity::getFromX($types, $search, array(), \Idno\Core\Idno::site()->config()->items_per_page, $offset);
            if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
                $create = \Idno\Common\ContentType::getRegistered();

                // If we can't create an object of this type, hide from the button bar
                foreach ($create as $key => $obj) {

                    $obj->editUrl = $obj->getEditURL(); // Helper for API clients: handle flexible site configuration

                    if (!$obj->createable) {
                        unset($create[$key]);
                    }
                }
            } else {
                $create = false;
            }

            if (!empty(\Idno\Core\Idno::site()->config()->description)) {
                $description = \Idno\Core\Idno::site()->config()->description;
            } else {
                $description = 'An independent social website, powered by Idno.';
            }

            // If we have a feed, set our last modified flag to the time of the latest returned entry
            if (!empty($feed)) {
                if (is_array($feed)) {
                    $feed = array_filter($feed);
                    $this->setLastModifiedHeader(reset($feed)->updated);
                }
            }

            if (!empty(\Idno\Core\Idno::site()->config()->homepagetitle)) {
                $title = \Idno\Core\Idno::site()->config()->homepagetitle;
            } else {
                $title = \Idno\Core\Idno::site()->config()->title;
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->__(
                array(

                'title'       => $title,
                'description' => $description,
                'content'     => $friendly_types,
                'body'        => $t->__(
                    array(
                    'items'        => $feed,
                    'contentTypes' => $create,
                    'offset'       => $offset,
                    'count'        => $count,
                    'subject'      => $query,
                    'content'      => $friendly_types
                    )
                )->draw('pages/home'),

                )
            )->drawPage();
        }

        /**
         * A webmention to the homepage means someone mentioned our site's root.
         */
        function webmentionContent($source, $target, $source_response, $source_mf2)
        {
            // if this is a single-user site, let's forward on the root mention
            // to their user page

            \Idno\Core\Idno::site()->logging()->info("received homepage mention from $source");

            if (\Idno\Core\Idno::site()->config()->single_user) {
                $user = \Idno\Entities\User::getOne(['admin' => true]);
                if ($user) {
                    \Idno\Core\Idno::site()->logging()->debug("pass on webmention to solo user: {$user->getHandle()}");
                    $userPage = \Idno\Core\Idno::site()->getPageHandler($user->getURL());
                    if ($userPage) {
                        return $userPage->webmentionContent($source, $target, $source_response, $source_mf2);
                    } else {
                        \Idno\Core\Idno::site()->logging()->debug("failed to find a Page to serve route " . $user->getURL());
                    }
                } else {
                    \Idno\Core\Idno::site()->logging()->debug("query for an admin-user failed to find one");
                }
            } else {
                \Idno\Core\Idno::site()->logging()->debug("disregarding mention to multi-user site");
            }

            return false;
        }

    }

}
