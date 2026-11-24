<?php

    /**
     * Defines built-in log in functionality
     */

namespace Idno\Pages\Session {

    /**
     * Default class to serve the homepage
     */
    class Login extends \Idno\Common\Page
    {

        function getContent()
        {

            // If we're somehow here but logged in, move to the front page if we're viewing with an HTML template
            if (\Idno\Core\Idno::site()->session()->isLoggedOn() && \Idno\Core\Idno::site()->template()->getTemplateType() === 'default') {

                $fwd = $this->getInput('fwd'); // Forward to a new page?
                if (empty($fwd)) {
                    $fwd = \Idno\Core\Idno::site()->config()->getDisplayURL();
                } else {
                    $fwd = \Idno\Core\Webservice::base64UrlDecode($this->getInput('fwd'));
                }
                $fwd = self::sanitizeRedirectUrl($fwd);
                $this->forward($fwd);
            }

            $fwd = \Idno\Core\Webservice::base64UrlDecode($this->getInput('fwd')); // Forward to a new page?
            if ($fwd == \Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/login') {
                $fwd = \Idno\Core\Idno::site()->config()->getDisplayURL();
            }
            if (empty($fwd)) {
                $fwd = \Idno\Core\Idno::site()->config()->getDisplayURL();
            }
            $fwd = self::sanitizeRedirectUrl($fwd);
            $t        = \Idno\Core\Idno::site()->template();
            $vars = [
                'fwd' => $fwd
            ];

            // If user is logged in and we got this far, this is an api login so lets return a user api token (#2240).
            // Only the JSON response may carry it. Another site can't read that one, but it can load
            // any response that is a script (JSONP), and the browser may send the session cookie with it.
            if (\Idno\Core\Idno::site()->session()->isLoggedOn() && \Idno\Core\Idno::site()->template()->getTemplateType() === 'json' && $this->isSSL()) {
                $user = \Idno\Core\Idno::site()->session()->currentUser();
                $vars['api-token'] = $user->getAPIkey();
            }

            $t->body  = $t->__($vars)->draw('account/login');
            $t->title = \Idno\Core\Idno::site()->language()->_('Sign in');
            $t->drawPage();
        }

        function postContent()
        {

            $fwd = $this->getInput('fwd'); // Forward to a new page?
            if (empty($fwd)) {
                $fwd = \Idno\Core\Idno::site()->config()->getDisplayURL();
            }
            $fwd = self::sanitizeRedirectUrl($fwd);

            $login = (string)$this->getInput('email');
            $password = trim((string)$this->getInput('password'));
            $language = \Idno\Core\Idno::site()->language();

            // Who is it for? The throttle counts for the ACCOUNT the name resolves to (the database compares names by its own
            // collation, so spellings that differ in case, accents or the like are one account), else for the name folded.
            if ($user = \Idno\Entities\User::getByHandle($login)) {
            } else if ($user = \Idno\Entities\User::getByEmail($login)) {
            }
            $user = $user instanceof \Idno\Entities\User ? $user : null;
            $key  = $user !== null ? \Idno\Security\LoginThrottle::keyForAccount((string)$user->getUUID()) : \Idno\Security\LoginThrottle::keyForUnknownName($login);

            // An attempt is reserved BEFORE the password is looked at (so that parallel requests cannot each get a guess); too
            // many in this window and it is refused without checking, even a right password.
            if (!self::throttleAttempt($key)) {
                \Idno\Core\Idno::site()->session()->addErrorMessage($language->_("Too many failed sign-in attempts for that username or email address. Please wait a few minutes and try again."));
                $this->forwardToLogin($fwd);

                return;
            }

            if ($user === null) {
                \Idno\Core\Idno::site()->events()->triggerEvent('login/failure/nouser', array('method' => 'password', 'credentials' => array('email' => $login)));
                $this->setResponse(401);
                // A login for a name nobody has costs what a wrong password for a member costs (one bcrypt), so that the time
                // it takes does not say which names are members'.
                self::passwords()->spendVerification($password);
                \Idno\Core\Idno::site()->session()->addErrorMessage($language->_("Oops! That username or password isn't right. Please try again."));
                $this->forwardToLogin($fwd);

                return;
            }

            if ($user->checkPassword($password)) {
                self::throttleReset($key);
                \Idno\Core\Idno::site()->events()->triggerEvent('login/success', array('user' => $user)); // Trigger an event for auditing
                \Idno\Core\Idno::site()->session()->logUserOn($user);
                $this->forwardToLogin($fwd);
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage($language->_("Oops! That username or password isn't right. Please try again."));
                \Idno\Core\Idno::site()->events()->triggerEvent('login/failure', array('user' => $user));
                $this->forwardToLogin($fwd);
            }
        }

        /**
         * The login throttle (Idno\Security\LoginThrottle): a throttle that cannot be built is not a reason to refuse a login.
         *
         * @return \Idno\Security\LoginThrottle|null
         */
        private static function throttle()
        {
            try {
                return \Idno\Core\Idno::container()->get(\Idno\Security\LoginThrottle::class);
            } catch (\Throwable $e) {
                \Idno\Core\Idno::site()->logging()->warning('The login throttle is unavailable: ' . get_class($e));

                return null;
            }
        }

        private static function passwords()
        {
            return \Idno\Core\Idno::container()->get(\Idno\Security\Passwords::class);
        }

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
         * @param  string $key
         * @return bool
         */
        public static function throttleAttempt($key)
        {
            try {
                $throttle = self::throttle();

                return $throttle === null || $throttle->attempt($key);
            } catch (\Throwable $e) {
                return true;
            }
        }

        /**
         * Clears the count of a key (a successful login, a password reset).
         *
         * @param string $key
         */
        public static function throttleReset($key)
        {
            try {
                self::throttle()?->reset($key);
            } catch (\Throwable $e) {
                \Idno\Core\Idno::site()->logging()->warning('The count of failed logins could not be reset: ' . get_class($e));
            }
        }

        /**
         * Validates that a redirect URL is local to this site.
         * Returns the site's home URL if the redirect is external.
         *
         * @param  string $url
         * @return string
         */
        public static function sanitizeRedirectUrl($url)
        {
            $siteUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
            $url     = trim((string) $url);

            if ($url === '') {
                return $siteUrl;
            }

            // Browsers treat backslashes as slashes and strip control characters,
            // so neither can be trusted in a redirect target
            if (strpos($url, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $url)) {
                return $siteUrl;
            }

            // Allow site-relative paths, but not protocol-relative URLs (//example.com)
            if ($url[0] === '/') {
                return (strlen($url) > 1 && $url[1] === '/') ? $siteUrl : $url;
            }

            // Otherwise it must be an absolute http(s) URL on this site's own host.
            // Compare parsed hosts rather than string prefixes, so that
            // https://example.com.evil.test and https://example.com@evil.test are rejected.
            $target = parse_url($url);
            $site   = parse_url($siteUrl);
            if (empty($target['scheme']) || empty($target['host']) || empty($site['host'])) {
                return $siteUrl;
            }
            if (!in_array(strtolower($target['scheme']), ['http', 'https'], true)) {
                return $siteUrl;
            }
            if (strtolower($target['host']) !== strtolower($site['host'])) {
                return $siteUrl;
            }
            if (($target['port'] ?? null) !== ($site['port'] ?? null)) {
                return $siteUrl;
            }

            return $url;
        }

    }

}

