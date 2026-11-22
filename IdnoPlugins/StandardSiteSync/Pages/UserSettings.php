<?php

namespace IdnoPlugins\StandardSiteSync\Pages;

use Idno\Common\Page;
use IdnoPlugins\StandardSiteSync\ATProtoClient;

/**
 * User account settings page for StandardSiteSync.
 * Each user manages their own PDS connection here.
 * Route: /account/settings/standardsitesync
 */
class UserSettings extends Page
{
    public function getContent()
    {
        $this->gatekeeper();

        $user = \Idno\Core\Idno::site()->session()->currentUser();
        $session = $user->standardsitesync_session ?? [];
        $connected = !empty($session['access_token']) && !empty($session['did']);
        $syncEnabled = !empty(\Idno\Core\Idno::site()->config()->standardsitesync_enabled);

        $t = \Idno\Core\Idno::site()->template();

        $body = $t->__(
            [
                'title'        => 'Standard.site Sync',
                'connected'    => $connected,
                'sync_enabled' => $syncEnabled,
                'did'          => $session['did'] ?? '',
                'pds'          => $session['pds'] ?? '',
            ]
        )->draw('standardsitesync/account/settings');

        $t->__(
            [
                'title' => 'Standard.site Sync',
                'body'  => $body,
            ]
        )->drawPage();
    }

    public function postContent()
    {
        $this->gatekeeper();

        $action = $this->getInput('action');

        if ($action === 'connect') {
            $handle = trim($this->getInput('handle'));
            if (empty($handle)) {
                \Idno\Core\Idno::site()->session()->addErrorMessage('Please enter your AT Protocol handle.');
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/standardsitesync/');
                return;
            }

            try {
                $authData = ATProtoClient::startOAuthFlow($handle);

                // Store pending auth data in session
                $_SESSION['standardsitesync_pending_auth'] = $authData;

                // Redirect to authorization server
                $this->forward($authData['url']);
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error('StandardSiteSync: OAuth start failed: ' . $e->getMessage());
                \Idno\Core\Idno::site()->session()->addErrorMessage('Failed to start authentication: ' . \Idno\Core\ExceptionMessage::forVisitor($e));
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/standardsitesync/');
            }
        }
    }
}
