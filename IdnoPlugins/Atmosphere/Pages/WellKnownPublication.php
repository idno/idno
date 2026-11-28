<?php

namespace IdnoPlugins\Atmosphere\Pages;

use Idno\Common\Page;
use Idno\Entities\User;
use IdnoPlugins\Atmosphere\Main;

/**
 * Serves the .well-known/site.standard.publication endpoint.
 * Returns the AT-URI of the publication record, confirming the link
 * between this domain and the publication on the PDS.
 *
 * Looks for the first admin user with a connected PDS, then falls back
 * to any connected user.
 *
 * Route: /.well-known/site.standard.publication
 */
class WellKnownPublication extends Page
{
    public function getContent()
    {
        $did = null;

        // Try admin users first
        $admins = User::get(['admin' => true], [], 100, 0);
        if (!empty($admins)) {
            foreach ($admins as $admin) {
                if (self::canSpeakForSite($admin)) {
                    $session = Main::getATProtoSessionForUser($admin);
                    $did = $session['did'];
                    break;
                }
            }
        }

        // Fall back to any connected user
        if (empty($did)) {
            $users = User::get([], [], 100, 0);
            if (!empty($users)) {
                foreach ($users as $user) {
                    if (self::canSpeakForSite($user)) {
                        $session = Main::getATProtoSessionForUser($user);
                        $did = $session['did'];
                        break;
                    }
                }
            }
        }

        if (empty($did)) {
            $this->setResponse(404);
            echo 'No AT Protocol publication configured.';
            exit;
        }

        $atUri = 'at://' . $did . '/site.standard.publication/self';

        header('Content-Type: text/plain');
        header('Cache-Control: public, max-age=3600');
        echo $atUri;
        exit;
    }

    /**
     * Can this member's identity be given as the owner of the site's publication? Only a
     * connected member who can still sign in: not one who is banned, and not one whose
     * account is closed (being deleted).
     */
    private static function canSpeakForSite(User $user): bool
    {
        return !$user->isBanned() && !$user->isClosed() && Main::isUserConnected($user);
    }
}
