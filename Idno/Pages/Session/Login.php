<?php

namespace Idno\Pages\Session {

    /**
     * The helpers the login page used to hold. The page itself is `Idno\Http\Controller\SessionController`; this class is no page and is only kept for
     * legacy code and plugins that call its static methods.
     *
     * @deprecated Use \Idno\Http\RedirectTarget and \Idno\Security\LoginAttempts. Removed in 3.0.
     */
    final class Login
    {

        /**
         * The throttle key of an account: for the login page and for the pages that ask for the current password.
         *
         * @param  \Idno\Entities\User $user
         * @return string
         */
        public static function throttleKeyFor(\Idno\Entities\User $user)
        {
            return \Idno\Security\LoginThrottle::keyForAccount((string)$user->getUUID());
        }

        /**
         * Reserves an attempt for the key; true when it may go on.
         *
         * @deprecated Use \Idno\Security\LoginAttempts::attempt().
         * @param  string $key
         * @return bool
         */
        public static function throttleAttempt($key)
        {
            return \Idno\Core\Idno::container()->get(\Idno\Security\LoginAttempts::class)->attempt((string)$key);
        }

        /**
         * Clears the count of a key (a successful login, a password reset).
         *
         * @deprecated Use \Idno\Security\LoginAttempts::reset().
         * @param string $key
         */
        public static function throttleReset($key)
        {
            \Idno\Core\Idno::container()->get(\Idno\Security\LoginAttempts::class)->reset((string)$key);
        }

        /**
         * Validates that a redirect URL is local to this site.
         * Returns the site's home URL if the redirect is external.
         *
         * @deprecated Use \Idno\Http\RedirectTarget::sanitize().
         * @param  string $url
         * @return string
         */
        public static function sanitizeRedirectUrl($url)
        {
            return \Idno\Http\RedirectTarget::sanitize((string) $url, \Idno\Core\Idno::site()->config()->getDisplayURL());
        }

    }

}
