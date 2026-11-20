<?php

    /**
     * Bookmarklet endpoint
     */

namespace Idno\Pages\Account\Settings\Following {

    /**
     * Default class to serve the following settings
     */
    class Bookmarklet extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->createGatekeeper();
            $user = \Idno\Core\Idno::site()->session()->currentUser();

            $u = $this->getInput('u');

            if ($content = \Idno\Core\Webservice::safeSend('get', $u)['content']) {

                $parser = new \Mf2\Parser($content, $u);
                if ($return = $parser->parse()) {

                    if (isset($return['items'])) {

                        $t     = \Idno\Core\Idno::site()->template();
                        $body  = '';
                        $hcard = array();

                        $this->findHcard($return['items'], $hcard);
                        $hcard = $this->removeDuplicateProfiles($hcard);

                        if (!count($hcard)) {
                            //throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Sorry, could not find any users on that page, perhaps they need to mark up their profile in <a href=\"http://microformats.org/wiki/microformats-2\">Microformats</a>?")); // TODO: Add a manual way to add the user

                            // No entry could be found, so lets fake one and allow manual entry
                            $hcard[] = [
                                'properties' => [
                                    'name'     => [$this->findPageTitle($content)],
                                    'photo'    => [],
                                    'email'    => [],
                                    'nickname' => [],
                                    'url'      => [$u] // No profile could be found as there is no markup, so lets just use the passed URL
                                ]
                            ];

                            // Display a warning
                            \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Page did not contain any <a href=\"http://microformats.org/wiki/microformats-2\">Microformats</a> markup... doing my best with what I have!'));

                        }

                        foreach ($hcard as $card) {
                            $body .= $t->__(array('mf2' => $card))->draw('account/settings/following/mf2user');
                        }

                        // List user
                        $t->body  = $body;
                        $t->title = \Idno\Core\Idno::site()->language()->_('Found users');
                        $t->drawPage();
                    }
                } else {
                    throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Sorry, there was a problem parsing the page!"));
                }
            } else {
                throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("Sorry, %s could not be retrieved!", [$u]));
            }

            // forward back
            $this->forward($_SERVER['HTTP_REFERER']);
        }

        /**
         * When passed an array of MF2 data, recursively find hcard entries.
         *
         * @param array $mf2
         * @param array $out
         */
        private function findHcard(array $mf2, array &$out)
        {
            foreach ($mf2 as $item) {
                // Find h-card
                if (in_array('h-card', $item['type'])) {
                    $out[] = $item;
                }
                if (isset($item['children'])) {
                    $this->findHcard($item['children'], $out);
                }
            }
        }

        /**
         * Go through the list of found hcards and remove duplicates (based on unique profile urls)
         *
         * @param  array $hcards
         * @return array
         */
        private function removeDuplicateProfiles(array $hcards)
        {
            $cards = array();

            foreach ($hcards as $card) {
                $key = serialize($card['properties']['url']);
                if (!isset($cards[$key])) {
                    $cards[$key] = $card;
                }
            }

            return $cards;
        }

        /**
         * Quickly find a title from a HTML page.
         *
         * @return string|false
         * @param  type $content
         */
        private function findPageTitle($content)
        {
            if (!preg_match("/<title>(.*)<\/title>/siU", $content, $matches)) {
                return false;
            }

            return trim($matches[1], " \n");
        }

        function postContent()
        {
            $this->createGatekeeper();
            $user = \Idno\Core\Idno::site()->session()->currentUser();

            if ($uuid = $this->getInput('uuid')) {

                if (// TODO: Do this better, perhaps support late bindings
                    (!$new_user = \Idno\Entities\User::getByUUID($uuid))
                    && (!$new_user = \Idno\Entities\User::getByProfileURL($uuid))
                    && (!$new_user = \Idno\Entities\RemoteUser::getByUUID($uuid))
                    && (!$new_user = \Idno\Entities\RemoteUser::getByProfileURL($uuid))
                ) {

                    // No user found, so create it if it's remote
                    if (!\Idno\Entities\User::isLocalUUID($uuid)) {
                        \Idno\Core\Idno::site()->logging()->debug("Creating new remote user");

                        $new_user = new \Idno\Entities\RemoteUser();

                        // Populate with data
                        $new_user->setTitle($this->getInput('name'));
                        $new_user->setHandle($this->getInput('nickname'));
                        $new_user->email = $this->getInput('email');
                        $new_user->setUrl($uuid);

                        // TODO: Get a profile URL - get it from passed photo variable, upload to local and treat as avatar.

                        if (!$new_user->save()) {
                            throw new \Exception(\Idno\Core\Idno::site()->language()->_("There was a problem saving the new remote user."));
                        }

                    }
                } else {
                    \Idno\Core\Idno::site()->logging()->debug("New user found as " . $new_user->uuid);
                }

                if ($new_user) {

                    \Idno\Core\Idno::site()->logging()->debug("Trying a follow");

                    if ($user->addFollowing($new_user)) {

                        \Idno\Core\Idno::site()->logging()->debug("User added to following");

                        if ($user->save()) {

                            \Idno\Core\Idno::site()->logging()->debug("Following saved");

                            // Ok, we've saved the new user, now, lets subscribe to their feeds
                            if ($feed = \Idno\Core\Idno::site()->reader()->getFeedObject($new_user->getURL())) {

                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_('You are now following %s, would you like to subscribe to their feed?', [$new_user->getTitle()]));

                                $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'following/confirm/?feed=' . urlencode($new_user->getURL()));
                            }

                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("You are now following %s", [$new_user->getTitle()]));

                        }
                    } else {
                        \Idno\Core\Idno::site()->logging()->debug('Could not follow user for some reason (probably already following)');
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_('You\'re already following %s', [$this->getInput('name')]));
                    }
                } else {
                    throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_('Sorry, that user doesn\'t exist!'));
                }
            } else {
                throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("No UUID, please try that again!"));
            }
        }

    }

}

