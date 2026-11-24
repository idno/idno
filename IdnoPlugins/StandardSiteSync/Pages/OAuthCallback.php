<?php

namespace IdnoPlugins\StandardSiteSync\Pages;

use Idno\Common\Page;
use IdnoPlugins\StandardSiteSync\ATProtoClient;
use IdnoPlugins\StandardSiteSync\Main;

/**
 * OAuth callback handler for AT Protocol authorization.
 * Stores the session on the current user entity.
 * Route: /account/settings/standardsitesync/callback
 */
class OAuthCallback extends Page
{
    public function getContent()
    {
        $this->gatekeeper();

        $code = $this->getInput('code');
        $state = $this->getInput('state');
        $error = $this->getInput('error');
        $settingsUrl = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/standardsitesync/';

        if (!empty($error)) {
            $errorDesc = $this->getInput('error_description');
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                Main::sessionMessage('Authentication denied: %s', (string) ($errorDesc ?: $error))
            );
            $this->forward($settingsUrl);
            return;
        }

        if (empty($code)) {
            \Idno\Core\Idno::site()->session()->addErrorMessage('No authorization code received.');
            $this->forward($settingsUrl);
            return;
        }

        // Retrieve pending auth data
        $pendingAuth = $_SESSION['standardsitesync_pending_auth'] ?? null;
        unset($_SESSION['standardsitesync_pending_auth']);

        if (empty($pendingAuth)) {
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                'Authentication session expired. Please try again.'
            );
            $this->forward($settingsUrl);
            return;
        }

        // Verify state
        if ($state !== $pendingAuth['state']) {
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                'Invalid authentication state. Please try again.'
            );
            $this->forward($settingsUrl);
            return;
        }

        try {
            // Exchange code for tokens
            $session = ATProtoClient::completeOAuthFlow($code, $pendingAuth);

            // Save the session on the current user
            $user = \Idno\Core\Idno::site()->session()->currentUser();
            Main::saveATProtoSessionForUser($user, $session);

            \Idno\Core\Idno::site()->session()->addMessage(
                Main::sessionMessage('Successfully connected to your AT Protocol PDS (%s).', (string) $session['did'])
            );
        } catch (\Exception $e) {
            \Idno\Core\Idno::site()->logging()->error(
                'StandardSiteSync: OAuth callback failed: ' . $e->getMessage()
            );
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                Main::sessionMessage('Authentication failed: %s', \Idno\Core\ExceptionMessage::forVisitor($e))
            );
        }

        $this->forward($settingsUrl);
    }
}
