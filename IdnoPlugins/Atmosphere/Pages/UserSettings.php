<?php

namespace IdnoPlugins\Atmosphere\Pages;

use Idno\Common\Page;
use IdnoPlugins\Atmosphere\ATProtoClient;

/**
 * User account settings page for Atmosphere.
 * Each user manages their own PDS connection here.
 * Route: /account/settings/atmosphere
 */
class UserSettings extends Page
{
    public function getContent()
    {
        $this->gatekeeper();

        $user = \Idno\Core\Idno::site()->session()->currentUser();
        $session = $user->standardsitesync_session ?? [];
        $connected = !empty($session['access_token']) && !empty($session['did']);
        $syncEnabled = \IdnoPlugins\Atmosphere\Main::isEnabled();

        $t = \Idno\Core\Idno::site()->template();

        $body = $t->__(
            [
                'title'        => 'Atmosphere',
                'connected'    => $connected,
                'sync_enabled' => $syncEnabled,
                'did'          => $session['did'] ?? '',
                'pds'          => $session['pds'] ?? '',
                'bluesky'      => \IdnoPlugins\Atmosphere\Main::postsToBluesky($user),
                'failure'      => $connected ? \IdnoPlugins\Atmosphere\Main::getSyncFailure($user) : null,
            ]
        )->draw('atmosphere/account/settings');

        $t->__(
            [
                'title' => 'Atmosphere',
                'body'  => $body,
            ]
        )->drawPage();
    }

    public function postContent()
    {
        $this->gatekeeper();

        $action = $this->getInput('action');

        if ($action === 'options') {
            // Whether status updates, videos and audio are posted to Bluesky as well
            $user = \Idno\Core\Idno::site()->session()->currentUser();
            $user->standardsitesync_bluesky = !empty($this->getInput('bluesky'));
            $user->save();
            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your choice has been saved.'));
            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/atmosphere/');

            return;
        }

        if ($action === 'connect') {
            $handle = trim($this->getInput('handle'));
            if (empty($handle)) {
                \Idno\Core\Idno::site()->session()->addErrorMessage('Please enter your AT Protocol handle.');
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/atmosphere/');
                return;
            }

            try {
                $authData = ATProtoClient::startOAuthFlow($handle);

                // Store pending auth data in session
                $_SESSION['atmosphere_pending_auth'] = $authData;

                // Redirect to authorization server
                $this->forward($authData['url']);
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error('Atmosphere: OAuth start failed: ' . $e->getMessage());
                \Idno\Core\Idno::site()->session()->addErrorMessage('Failed to start authentication: ' . $e->getMessage());
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/atmosphere/');
            }
        }
    }
}
