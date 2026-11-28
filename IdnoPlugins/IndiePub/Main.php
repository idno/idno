<?php

namespace IdnoPlugins\IndiePub {

    use IdnoPlugins\IndiePub\Pages\IndieAuth\Token;

    class Main extends \Idno\Common\Plugin
    {
        function registerTranslations()
        {
            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'indiepub', dirname(__FILE__) . '/languages/'
                )
            );
        }

        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/indieauth/auth/?', '\IdnoPlugins\IndiePub\Pages\IndieAuth\Auth', true);
            \Idno\Core\Idno::site()->routes()->addRoute('/indieauth/approve/?', '\IdnoPlugins\IndiePub\Pages\IndieAuth\Approve', true);
            \Idno\Core\Idno::site()->routes()->addRoute('/indieauth/token/?', '\IdnoPlugins\IndiePub\Pages\IndieAuth\Token', true);
            \Idno\Core\Idno::site()->routes()->addRoute('/micropub/endpoint/?', '\IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint', true);
            \Idno\Core\Idno::site()->routes()->addRoute('/account/indiepub/?', '\IdnoPlugins\IndiePub\Pages\Account');
            \Idno\Core\Idno::site()->routes()->addRoute('/account/indiepub/revoke/?', '\IdnoPlugins\IndiePub\Pages\Revoke');
            \Idno\Core\Idno::site()->routes()->addRoute('/account/indiepub/add/?', '\IdnoPlugins\IndiePub\Pages\Add');

            \Idno\Core\Idno::site()->template()->extendTemplate('shell/head', 'indiepub/shell/head');
            \Idno\Core\Idno::site()->template()->extendTemplate('account/menu/items', 'account/menu/items/indiepub');

            header('Link: <' . \Idno\Core\Idno::site()->config()->getURL() . 'indieauth/auth>; rel="authorization_endpoint"', false);
            header('Link: <' . \Idno\Core\Idno::site()->config()->getURL() . 'indieauth/token>; rel="token_endpoint"', false);
            header('Link: <' . \Idno\Core\Idno::site()->config()->getURL() . 'micropub/endpoint>; rel="micropub"', false);
        }

        /**
         * Authenticate the current request by the access token it carries, if it carries one:
         * an IndieAuth access token of a user, or the API key of the site's first
         * administrator. The token is taken from the Authorization header (Bearer), or else
         * from the access_token field.
         *
         * On success the token's user becomes the user of this request (held in memory by
         * \Idno\Core\Session::authenticateStatelessly(): nothing is written to the session, and a
         * browser session the request carries is left as it is), and the request is an API
         * request, which means that no form token is asked of it and that the user is never an
         * administrator.
         *
         * Call this only from the page a token is meant for (the Micropub endpoint). It is
         * deliberately not called for every request: a token given to a Micropub client must
         * not let that client use the rest of the site as the user.
         *
         * @return \Idno\Entities\User|false user on success
         */
        public static function authenticate()
        {
            $headers = \Idno\Common\Page::getallheaders();
            if (!empty($headers['Authorization'])) {
                $token = $headers['Authorization'];
                $token = trim(str_replace('Bearer', '', $token));
            } else {
                $token = trim((string) \Idno\Core\Input::getInput('access_token'));
            }

            if (!empty($token)) {

                $found = Token::findUserForToken($token);
                if (!empty($found)) {
                    $user = $found['user'];
                    \Idno\Core\Idno::site()->session()->authenticateStatelessly($user);

                    return $user;
                }
                // The first administrator that is not being deleted (a closed account has no key)
                $user = null;
                foreach ((array) \Idno\Entities\User::get(['admin' => true], [], 20) as $candidate) {
                    if ($candidate instanceof \Idno\Entities\User && !$candidate->isClosed()) {
                        $user = $candidate;
                        break;
                    }
                }
                if ($user && hash_equals((string) $user->getAPIkey(), $token)) {
                    \Idno\Core\Idno::site()->session()->authenticateStatelessly($user);

                    return $user;
                }
            }

            return false;
        }

        function getAdminIcon()
        {
            return 'send';
        }

    }

}

