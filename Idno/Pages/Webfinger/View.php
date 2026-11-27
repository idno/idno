<?php

    /**
     * Webfinger (RFC 7033)
     */

namespace Idno\Pages\Webfinger {

    /**
     * Serves WebFinger JRD responses for local user discovery.
     */
    class View extends \Idno\Common\Page
    {

        function getContent()
        {

            // A private site names no member to anybody
            if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
                $this->noContent();
            }

            $acct = $this->getTextInput('resource'); // A text: resource[]=x names nobody
            if (!empty($acct)) {
                if (substr($acct, 0, 5) == 'acct:' && strlen($acct) > 8) {
                    $handle = str_replace('@' . \Idno\Core\Idno::site()->config()->host, '', substr($acct, 5));
                    $user = \Idno\Entities\User::getByHandle($handle);
                    if ($user && $user->isBanned()) {
                        $user = false; // A banned member is not found
                    }
                    if ($user) {
                        $links = \Idno\Core\Idno::site()->events()->triggerEvent('webfinger', array('object' => $user));
                    }
                }
            }
            if (empty($user)) $this->noContent();
            if (empty($links)) {
                $links = array();
            }

            $jrd = [
                'subject' => $acct,
                'aliases' => [
                    $user->getURL(),
                    $user->getActivityPubActorID(),
                ],
                'links'   => $links,
            ];

            // Remove duplicate aliases
            $jrd['aliases'] = array_values(array_unique($jrd['aliases']));

            header('Content-Type: application/jrd+json');
            header('Access-Control-Allow-Origin: *');

            echo json_encode($jrd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        function postContent()
        {
        }

    }

}
