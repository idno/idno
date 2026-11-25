<?php

namespace IdnoPlugins\StandardSiteSync\Pages;

use Idno\Common\Page;
use IdnoPlugins\StandardSiteSync\Main;

/**
 * Disconnect the current user from their AT Protocol PDS.
 * Route: /account/settings/standardsitesync/disconnect
 */
class Disconnect extends Page
{
    public function postContent()
    {
        $this->gatekeeper();

        $user = \Idno\Core\Idno::site()->session()->currentUser();
        $user->standardsitesync_failure = null;
        Main::saveATProtoSessionForUser($user, []);

        \Idno\Core\Idno::site()->session()->addMessage('Disconnected from AT Protocol PDS.');
        $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/standardsitesync/');
    }
}
