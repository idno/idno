<?php

namespace IdnoPlugins\Bridgy {

    use Idno\Common\Plugin;

    class Main extends Plugin
    {

        function registerPages()
        {

            \Idno\Core\Idno::site()->template()->extendTemplate('account/menu/items', 'bridgy/menu');

            \Idno\Core\Idno::site()->routes()->addRoute('/account/bridgy/?', 'IdnoPlugins\Bridgy\Pages\Account');
            \Idno\Core\Idno::site()->routes()->addRoute('/account/bridgy/enabled/?', 'IdnoPlugins\Bridgy\Pages\Enabled');
            \Idno\Core\Idno::site()->routes()->addRoute('/account/bridgy/disabled/?', 'IdnoPlugins\Bridgy\Pages\Disabled');
            \Idno\Core\Idno::site()->routes()->addRoute('/account/bridgy/check/?', 'IdnoPlugins\Bridgy\Pages\Check');

        }

        /// How long the state of an address given to Bridgy is accepted, in seconds
        const STATE_LIFETIME = 3600;

        /**
         * The state that goes into the address Bridgy sends a member's browser back to. The
         * pages at those addresses are plain GETs that change the member's settings, so they
         * act only on an address that carries this: it is made for one member, one of the two
         * pages and one service, with the site's secret, and is good for an hour.
         *
         * @param  \Idno\Entities\User $user
         * @param  string              $action  'enabled' or 'disabled'
         * @param  string              $service
         * @param  int|null            $time    When it is made (now, unless a test says otherwise)
         * @return string
         */
        static function callbackState(\Idno\Entities\User $user, $action, $service, $time = null)
        {
            $time = $time === null ? time() : (int) $time;
            $signed = implode('|', ['bridgy', (string) $action, (string) $service, (string) $user->getUUID(), $time]);

            return $time . '.' . hash_hmac('sha256', $signed, (string) \Idno\Core\Bonita\Main::getSiteSecret());
        }

        /**
         * Whether a state is the one this member was given for this page and service, and is
         * not too old.
         *
         * @param  mixed $state What the request carries
         * @return bool
         */
        static function isCallbackState(\Idno\Entities\User $user, $action, $service, $state)
        {
            if (!is_string($state) || !preg_match('/^(\d{1,12})\.[0-9a-f]{64}$/', $state, $match)) {
                return false;
            }
            $time = (int) $match[1];
            if (abs(time() - $time) > static::STATE_LIFETIME) {
                return false;
            }

            return hash_equals(static::callbackState($user, $action, $service, $time), $state);
        }

        /**
         * The address to give Bridgy for it to send the member's browser back to
         *
         * @param  string $action  'enabled' or 'disabled'
         * @param  string $service
         * @return string
         */
        static function callbackUrl($action, $service)
        {
            $url = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/bridgy/' . $action . '/?service=' . rawurlencode($service);
            if ($user = \Idno\Core\Idno::site()->session()->currentUser()) {
                $url .= '&state=' . static::callbackState($user, $action, $service);
            }

            return $url;
        }

        function registerTranslations()
        {

            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'bridgy', dirname(__FILE__) . '/languages/'
                )
            );
        }

        function getAdminIcon()
        {
            return 'share-2';
        }

    }

}

