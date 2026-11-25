<?php

namespace IdnoPlugins\Atmosphere\Pages;

use Idno\Common\Page;

/**
 * Admin settings page for Atmosphere.
 * Only controls the site-wide enable/disable toggle.
 * Per-user PDS connection is managed in user account settings.
 * Route: /admin/atmosphere
 */
class Admin extends Page
{
    public function getContent()
    {
        $this->adminGatekeeper();

        $t = \Idno\Core\Idno::site()->template();

        $syncEnabled = \IdnoPlugins\Atmosphere\Main::isEnabled();

        $body = $t->__(
            [
                'title'        => 'Atmosphere',
                'sync_enabled' => $syncEnabled,
            ]
        )->draw('atmosphere/admin/settings');

        $t->__(
            [
                'title' => 'Atmosphere',
                'body'  => $body,
            ]
        )->drawPage();
    }

    public function postContent()
    {
        $this->adminGatekeeper();

        $enabled = $this->getInput('sync_enabled') ? true : false;
        \Idno\Core\Idno::site()->config()->atmosphere_enabled = $enabled;
        \Idno\Core\Idno::site()->config()->save();

        if ($enabled) {
            \Idno\Core\Idno::site()->session()->addMessage('Syncing to the Atmosphere is on for this site. Members can now connect their accounts from their account settings.');
        } else {
            \Idno\Core\Idno::site()->session()->addMessage('Syncing to the Atmosphere is off.');
        }

        $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/atmosphere/');
    }
}
