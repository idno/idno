<?php

/**
 * Bonita form handling class.
 *
 * Uses the templating class and some extra helpers to provide a safe form handler. See
 * examples/forms.php for more.
 *
 * @package    Bonita
 * @subpackage Forms
 */

namespace Idno\Core\Bonita {

    class Forms extends Templates
    {

        /**
         *  Gatekeeper function that validates input forms and prevents csrf attacks.
         *  Call this from your form action code.
         *
         *  The token is the session's own (`Idno\Security\FormToken`), posted as `__bTk`. The time (`__bTs`) and
         *  the action (`__bTa`) a form prints are ignored, and so is $action: a token is good for as long as the
         *  session is. A token that is missing, is not a string or is not the session's is simply invalid. A
         *  stateless (API) request carries no form token and is always valid.
         *
         * @param  string  $action                    Unused; kept for the signature.
         * @param  boolean $haltExecutionOnBadRequest If set to true, the function halts all execution if the form doesn't validate. (True by default.)
         * @return true|false
         */
        public static function validateToken($action = '', $haltExecutionOnBadRequest = true)
        {
            if (\Idno\Core\Idno::site()->session()->isAPIRequest()) {
                return true;
            }

            if (self::formToken()->isValid($_REQUEST[\Idno\Security\FormToken::FIELD] ?? null)) {
                return true;
            }
            if ($haltExecutionOnBadRequest) {
                exit;
            }

            return false;
        }

        /**
         *  Determines whether Bonita form submission data exists and is ready to be processed.
         *
         * @return true|false
         */
        public static function formSubmitted()
        {

            if (isset($_REQUEST['__bTk']) && isset($_REQUEST['__bTs'])) {
                return true;
            }

            return false;
        }

        /**
         *    The session's form token: 64 lower-case hex characters, the same for every form of the session. The
         *    action and the time are ignored (they are printed with the token for the forms that always carried
         *    them).
         *
         * @param string     $action Ignored.
         * @param int|string $time   Ignored.
         *
         * @return string
         */
        public static function token($action, $time)
        {
            return self::formToken()->value();
        }

        /**
         *  Forgets the session's form token, so that the next form is drawn with a new one. Called when the session's
         *  id changes (logging in) and nowhere else.
         */
        public static function forgetToken()
        {
            self::formToken()->forget();
        }

        private static function formToken(): \Idno\Security\FormToken
        {
            return \Idno\Core\Idno::container()->get(\Idno\Security\FormToken::class);
        }

        /**
         * Obfuscate a form field name.
         *
         * @param  type $field
         * @return type
         */
        public static function obfuscateField($field)
        {
            return md5(\Idno\Core\Idno::site()->config()->site_secret . $field);
        }

    }

}
