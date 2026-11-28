<?php

    /**
     * Session management class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    use Idno\Entities\User;

    class Session extends \Idno\Common\Component
    {

        private $user;

        private $storageHandler = false;

        function init()
        {
            ini_set('session.cookie_lifetime', 60 * 60 * 24 * 7); // Persistent cookies
            ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 7); // Garbage collection to match

            header('P3P: CP="CAO PSA OUR"');
            ini_set('session.use_only_cookies', true); // Only cookies for session
            ini_set('session.cookie_httponly', true); // Restrict cookies to HTTP only (help reduce XSS attack profile)
            ini_set('session.use_strict_mode', true); // Help mitigate session fixation
            ini_set("session.use_trans_sid", false); // Prevent transparent IDs
            if (\Idno\Common\Page::isSSL()) {
                ini_set('session.cookie_secure', true); // Set secure cookies when site is secure
            }

            // Using a more secure hashing algorithm for session IDs, if available
            if (($hash = Idno::site()->config()->session_hash_function) && (in_array($hash, hash_algos()))) {
                ini_set('session.hash_function', $hash);
            }

            $sessions_handled = false;

            // If we're storing sessions in the database, then try to store it.
            if ((!$sessions_handled) && (Idno::site()->config()->sessions_database)) {
                $db = Idno::site()->db();
                if ($db instanceof \Idno\Common\SessionStorageInterface) {
                    if ($db->handleSession()) {
                        $this->storageHandler = $db;
                        $sessions_handled = true;
                    }
                }
            }

            // Try a pluggable session storage handler
            if ((!$sessions_handled) && (Idno::site()->config()->sessions_storage)) {
                $storage_class = Idno::site()->config()->sessions_storage;
                if (class_exists("{$storage_class}")) {
                    if (is_subclass_of($storage_class, "Idno\\Common\\SessionStorageInterface")) {
                        $storage_class = new $storage_class();
                        if ($storage_class->handleSession()) {
                            $this->storageHandler = $storage_class;
                            $sessions_handled = true;
                        }
                    }
                }
            }

            // Fallback to files if no other session storage handler has been defined
            if (!$sessions_handled) {
                session_save_path(Idno::site()->config()->session_path);
            }

            session_name(Idno::site()->config()->sessionname);
            session_start();

            // A request signed with an API key is authenticated by its signature and keeps no
            // session (see discardStoredSession()). A stored session that says it is one was
            // left behind by an earlier version, and is never taken up again.
            if (!empty($_SESSION['is_api_request'])) {
                $_SESSION = [];
            }

            // Flag insecure sessions (so we can check state changes etc)
            if (!isset($_SESSION['secure'])) {
                $_SESSION['secure'] = \Idno\Common\Page::isSSL();
            }

            // Validate session
            try {
                $this->validate();
            } catch (\Exception $ex) {
                // Session didn't validate, log & destroy
                \Idno\Core\Idno::site()->logging()->error('Error validating session', ['error' => $ex->getMessage()]);
                header('X-IDNO-DEBUG: Tilt!');

                $_SESSION = [];
                session_destroy();
            }

            // Session login / logout
            Idno::site()->routes()->addRoute('/session/login', '\Idno\Pages\Session\Login', true);
            Idno::site()->routes()->addRoute('/session/logout', '\Idno\Pages\Session\Logout');
            Idno::site()->routes()->addRoute('/currentUser/?', '\Idno\Pages\Session\CurrentUser');

            // Update the session on save if we're saving the current user
            \Idno\Core\Idno::site()->events()->addListener(
                'save', function (\Idno\Core\Event $event) {

                    $eventdata = $event->data();
                    $object    = $eventdata['object'];

                    if (empty($object) || empty($this->user)
                        || !($object instanceof User) || !($this->user instanceof User)
                    ) {
                        return;
                    }

                    if ($object->getUUID() != $this->user->getUUID()) {
                        return;
                    }

                    if (!empty($_SESSION['user_uuid'])) {
                        if ($object->getUUID() != $_SESSION['user_uuid']) {
                            return;
                        }
                    }

                    $this->user = $this->refreshSessionUser($object);

                }
            );

            // If this is an API request, we need to destroy the session afterwards. See #1028
            register_shutdown_function(
                function () {
                    $session = Idno::site()->session();
                    if ($session && $session->isAPIRequest()) {
                        $session->logUserOff();
                        $session->removeSessionCookie();
                    }
                }
            );
        }

        /**
         * Validate the session.
         *
         * @throws \Exception if the session is invalid.
         */
        protected function validate()
        {
        }

        /**
         * Kill the session.
         */
        function finishEarly()
        {
            if ($this->isAPIRequest()) {
                $this->discardStoredSession();

                return;
            }
            session_write_close();
        }

        /**
         * End the session of a request that was authenticated by an API signature or an
         * access token, without storing it: such a request is its member only for as long as
         * it lasts. This is called where a session would otherwise be written and closed
         * (before a page is drawn, before a redirect); once a session is closed it can no
         * longer be destroyed, and the stored session would be accepted afterwards, by its id
         * alone, as the member. What the request holds stays in memory until it ends, and the
         * session's cookie is taken off the answer.
         */
        function discardStoredSession()
        {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $held = $_SESSION;
                @session_destroy();
                $_SESSION = $held;
            }
            $this->removeSessionCookie();
        }

        /**
         * Take the session's cookie off the answer, if the headers have not been sent yet.
         * Other cookies are left as they are.
         */
        function removeSessionCookie()
        {
            if (headers_sent()) {
                return;
            }
            $keep = [];
            $found = false;
            foreach (headers_list() as $header) {
                if (stripos($header, 'Set-Cookie:') !== 0) {
                    continue;
                }
                if (strpos(ltrim(substr($header, strlen('Set-Cookie:'))), session_name() . '=') === 0) {
                    $found = true;
                } else {
                    $keep[] = $header;
                }
            }
            if ($found) {
                header_remove('Set-Cookie');
                foreach ($keep as $header) {
                    header($header, false);
                }
            }
        }

        /**
         * Get the UUID of the currently logged-in user, or false if
         * we're logged out
         *
         * @return mixed
         */

        function currentUserUUID()
        {
            if ($this->isLoggedOn()) {
                return $this->currentUser()->getUUID();
            }

            return false;
        }

        /**
         * Wrapper function for isLoggedIn()
         *
         * @see    Idno\Core\Session::isLoggedIn()
         * @return true|false
         */

        function isLoggedOn()
        {
            return $this->isLoggedIn();
        }

        /**
         * Is a user logged into the current session?
         *
         * @return true|false
         */
        function isLoggedIn()
        {
            if (!empty($this->user) && $this->user instanceof \Idno\Entities\User) {
                return true;
            }

            return false;
        }

        /**
         * Returns true if a user is logged into the current session, and they're an admin.
         *
         * @return bool
         */
        function isAdmin()
        {
            if ($this->isLoggedIn()) {
                return $this->currentUser()->isAdmin();
            }

            return false;
        }

        /**
         * Returns the currently logged-in user, if any
         *
         * @return \Idno\Entities\User
         */

        function currentUser()
        {
            if (!empty($this->user)) {
                return $this->user;
            }

            return false;
        }

        /**
         * Adds a message to the queue to be delivered to the user as soon as is possible
         *
         * @param string $message      The text of the message
         * @param string $message_type This type of message; this will be added to the displayed message class, or returned as data
         */

        function addMessage($message, $message_type = 'alert-info')
        {
            if (empty($_SESSION['messages'])) {
                $_SESSION['messages'] = array();
            }
            $_SESSION['messages'][] = $this->getStructuredMessage($message, $message_type);
        }

        /**
         * Draw a message
         *
         * @param  $message
         * @param  string $message_type
         * @return string
         */
        function drawMessage($message, $message_type = 'alert-info')
        {
            return Idno::site()->template()
                ->__(['message' => $this->getStructuredMessage($message, $message_type)])
                ->draw('shell/messages/message');
        }

        /**
         * Draw a message from a message structure
         *
         * @param  array $message
         * @return string
         */
        function drawStructuredMessage($message)
        {
            return Idno::site()->template()
                ->__(['message' => $message])
                ->draw('shell/messages/message');
        }

        /**
         * Turns a string message into a message structure
         *
         * @param  $message
         * @param  string $message_type
         * @return array
         */
        function getStructuredMessage($message, $message_type = 'alert-info')
        {
            return ['message' => $message, 'message_type' => $message_type];
        }

        /**
         * Error message wrapper for addMessage()
         *
         * @param string $message
         */
        function addErrorMessage($message)
        {
            $this->addMessage($message, 'alert-danger');
        }

        /**
         * Adds a message to the queue to be delivered to the user as soon as is possible, ensuring it's at the beginning of the list
         *
         * @param string $message      The text of the message
         * @param string $message_type This type of message; this will be added to the displayed message class, or returned as data
         */

        function addMessageAtStart($message, $message_type = 'alert-info')
        {
            if (empty($_SESSION['messages'])) {
                $_SESSION['messages'] = array();
            }
            array_unshift($_SESSION['messages'], array('message' => $message, 'message_type' => $message_type));
        }

        /**
         * Retrieve any messages from the session, remove them from the session, and return them
         *
         * @return array
         */
        function getAndFlushMessages()
        {
            $messages = $this->getMessages();
            $this->flushMessages();

            return $messages;
        }

        /**
         * Retrieve any messages waiting for the user in the session
         *
         * @return array
         */
        function getMessages()
        {
            if (!empty($_SESSION['messages'])) {
                return $_SESSION['messages'];
            } else {
                return array();
            }
        }

        /**
         * Remove any messages from the session
         */
        function flushMessages()
        {
            $messages                       = array();
            $_SESSION['messages']           = $messages;
            $_SESSION['last_message_flush'] = date('r', time());
        }

        /**
         * Get access groups the current user is allowed to write to
         *
         * @return array
         */

        function getWriteAccessGroups()
        {
            if ($this->isLoggedOn()) {
                return $this->currentUser()->getWriteAccessGroups();
            }

            return array();
        }

        /**
         * Get IDs of the access groups the current user is allowed to write to
         *
         * @return array
         */

        function getWriteAccessGroupIDs()
        {
            if ($this->isLoggedOn()) {
                return $this->currentUser()->getWriteAccessGroups();
            }

            return array();
        }

        /**
         * Get access groups the current user (if any) is allowed to read from
         *
         * @return array
         */

        function getReadAccessGroups()
        {
            if ($this->isLoggedOn()) {
                return $this->currentUser()->getReadAccessGroups();
            }

            return array('PUBLIC');
        }

        /**
         * Get IDs of the access groups the current user (if any) is allowed to read from
         *
         * @return array
         */

        function getReadAccessGroupIDs()
        {
            $group = array('PUBLIC');
            if ($this->isLoggedOn()) {
                $group = $this->currentUser()->getReadAccessGroupIDs();
            }

            return $group;
        }

        /**
         * Log the current session user off
         *
         * @return true
         */

        function logUserOff()
        {

            \Idno\Core\Idno::site()->events()->triggerEvent(
                "user/logoff", array(
                "user"   => !empty($this->user) ? $this->user : null,
                )
            );

            unset($_SESSION['user_uuid']);
            unset($this->user);

            // Unset all session variables, as per PHP docs.
            $_SESSION = [];

            // Really log the user off by destroying the cookie
            // See https://secure.php.net/manual/en/function.session-destroy.php
            if (!defined('IDNO_UNIT_TEST')) {
                if (!$this->isAPIRequest()) { // #1365 - we need to destroy the session, but resetting cookie causes problems with the api
                    if (ini_get("session.use_cookies")) {
                        $params = session_get_cookie_params();
                        setcookie(
                            session_name(), '', time() - 42000,
                            $params["path"], $params["domain"],
                            $params["secure"], $params["httponly"]
                        );
                    }
                }
            }

            @session_destroy();

            return true;
        }

        /**
         * Set a piece of session data
         *
         * @param string $name
         * @param mixed  $value
         */
        function set($name, $value)
        {
            $_SESSION[$name] = $value;
        }

        /**
         * Retrieve the session data with key $name, if it exists
         *
         * @param  string $name
         * @return mixed
         */
        function get($name)
        {
            if (!empty($_SESSION[$name])) {
                return $_SESSION[$name];
            } else {
                return false;
            }
        }

        /**
         * Remove data with key $name from the session
         *
         * @param $name
         */
        function remove($name)
        {
            unset($_SESSION[$name]);
        }

        /**
         * Called at the beginning of each request handler, attempts to authorize the request.
         *
         * Checks HTTP request headers to see if the request has been properly
         * signed for API access.
         *
         * If this is not an API request, then check the session for the logged in user's credentials.
         *
         * Triggers "user/auth/request" to give plugins an opportunity to implement their own auth mechanism.
         * Then "user/auth/success" or "user/auth/failure" depending on if a user was found for the provided credentials.
         *
         * @return \Idno\Entities\User|false The logged-in user, or false otherwise
         */
        function tryAuthUser()
        {
            // attempt to delegate auth to a plugin (note: plugin is responsible for calling setIsAPIRequest or not)
            $return = \Idno\Core\Idno::site()->events()->triggerEvent('user/auth/request', [], false);

            // auth standard API requests (check X-IDNO-* headers first, fall back to X-KNOWN-* for backwards compat)
            $apiUsername = $_SERVER['HTTP_X_IDNO_USERNAME'] ?? $_SERVER['HTTP_X_KNOWN_USERNAME'] ?? null;
            $apiSignature = $_SERVER['HTTP_X_IDNO_SIGNATURE'] ?? $_SERVER['HTTP_X_KNOWN_SIGNATURE'] ?? null;
            if (!$return && !empty($apiUsername) && !empty($apiSignature)) {
                \Idno\Core\Idno::site()->logging()->debug("Attempting to auth via API credentials");

                $t = \Idno\Core\Input::getInput('_t');
                if (empty($t)) {
                    \Idno\Core\Idno::site()->template()->setTemplateType('json');
                }

                $user = \Idno\Entities\User::getByHandle($apiUsername);
                if (empty($user)) {
                    $user = \Idno\Entities\User::getByEmail($apiUsername);
                }
                if (!empty($user) && $user->isClosed()) {
                    // A closed account has no key: nothing is compared, and it is nobody's request
                    $user = false;
                }
                if (!empty($user)) {
                    \Idno\Core\Idno::site()->logging()->debug("API auth found user by username: {$apiUsername} - " . $user->getName());

                    $key  = $user->getAPIkey();
                    $hmac = trim($apiSignature);
                    //$compare_hmac = base64_encode(hash_hmac('sha256', explode('?', $_SERVER['REQUEST_URI'])[0], $key, true));
                    $compare_hmac = base64_encode(hash_hmac('sha256', ($_SERVER['REQUEST_URI']), $key, true));

                    if ($hmac == $compare_hmac) {
                        $this->setIsAPIRequest(true);
                        \Idno\Core\Idno::site()->logging()->debug("API auth verified signature for user: " . $user->getName());
                        // TODO maybe this should set the current user without modifying $_SESSION?
                        $return = $this->refreshSessionUser($user);
                    } else {
                        \Idno\Core\Idno::site()->logging()->debug("API auth failed signature validation for user: " . $user->getName());
                        \Idno\Core\Idno::site()->logging()->debug("Expected signature formed over base64_encode(hash_hmac('sha256', '{$_SERVER['REQUEST_URI']}', \$key, true)) = '$compare_hmac', but got '$hmac'. ");
                        \Idno\Core\Idno::site()->logging()->debug("Please read http://docs.idno.co/en/latest/developers/plugins/api/ for further details.");
                    }
                } else {
                    \Idno\Core\Idno::site()->logging()->debug("API User given in X_IDNO_USERNAME ('{$apiUsername}') could not be found.");
                }
            }

            // auth via session credentials
            if (!$return) {
                $this->refreshCurrentSessionuser();
                $return = $this->currentUser();
            }

            if ($this->isAPIRequest()) {
                if (!\Idno\Common\Page::isSSL() && !\Idno\Core\Idno::site()->config()->disable_cleartext_warning) {
                    $this->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Warning: Access credentials were sent over a non-secured connection! To disable this warning set disable_cleartext_warning in your config.ini"));
                }
                // If this is an API request but we're not logged in, set page response code to access denied
                if (!$return) {
                    $ip = $_SERVER['REMOTE_ADDR'];
                    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                        $proxies = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']); // We are behind a proxy
                        $ip      = trim($proxies[0]);
                    }

                    \Idno\Core\Idno::site()->logging()->error("API Login failure from $ip");
                }
            }

            $return = \Idno\Core\Idno::site()->events()->triggerEvent(
                $return ? "user/auth/success" : "user/auth/failure", array(
                "user"   => $return,
                "is api" => $this->isAPIRequest(),
                ), $return
            );

            return $return;
        }

        /**
         * Log the specified user on (note that this is NOT the same as taking the user's auth credentials)
         *
         * @param  \Idno\Entities\User $user
         * @return \Idno\Entities\User
         */

        function logUserOn(\Idno\Entities\User $user)
        {
            if ($user->isBanned() || $user->isClosed() || \Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                $this->logUserOff();

                return false;
            }
            $return = $this->refreshSessionUser($user);
            @session_regenerate_id(true);

            // user/auth/success event needs to be triggered here
            $return = \Idno\Core\Idno::site()->events()->triggerEvent(
                $return ? "user/auth/success" : "user/auth/failure", array(
                "user"   => $return,
                "is api" => $this->isAPIRequest(),
                ), $return
            );

            \Idno\Core\Idno::site()->events()->triggerEvent(
                "user/logon", array(
                "user"   => $return,
                )
            );

            return $return;
        }

        /**
         * Refresh the user currently stored in the session
         *
         * @param  \Idno\Entities\User $user
         * @return \Idno\Entities\User
         */
        function refreshSessionUser(\Idno\Entities\User $user)
        {
            if ($user = User::getByUUID($user->getUUID(), false)) {

                if ($user->isBanned() || $user->isClosed() || \Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                    $this->logUserOff();

                    return false;
                }

                $_SESSION['user_uuid'] = $user->getUUID();
                $this->user            = $user;

                return $user;
            }

            return false;
        }

        /**
         * If we're logged in, refresh the current session user.
         */
        function refreshCurrentSessionuser()
        {
            if (!$this->currentUser() && !empty($_SESSION['user_uuid'])) {
                if ($this->user = User::getByUUID($_SESSION['user_uuid'])) {
                    if ($this->user->isBanned() || $this->user->isClosed() || \Idno\Core\Idno::site()->config()->emailIsBlocked($this->user->email)) {
                        $this->logUserOff();
                    }
                }
            } else if ($this->isLoggedIn()) {
                $user_uuid = $this->currentUserUUID();
                if ($user = User::getByUUID($user_uuid)) {
                    $this->refreshSessionUser($user);
                } else {
                    $this->logUserOff();
                }
            }
        }

        /**
         * Sets whether this session is an API request or a manual browse
         *
         * @param boolean $is_api_request
         */
        function setIsAPIRequest($is_api_request)
        {
            $is_api_request             = (bool)$is_api_request;
            $_SESSION['is_api_request'] = $is_api_request;
        }

        /**
         * Is this session an API request?
         *
         * @return bool
         */
        function isAPIRequest()
        {
            if (!empty($_SESSION['is_api_request'])) {
                return true;
            }

            return false;
        }

        /**
         * If the current user isn't logged in and this isn't a public site, and this hasn't been defined as an
         * always-public page, forward to the login page.
         */
        function publicGatekeeper()
        {

            if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
                if (!\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                    $class = get_class(Idno::site()->currentPage());
                    if (!\Idno\Core\Idno::site()->routes()->isRoutePublic($class)) {
                        \Idno\Core\Idno::site()->currentPage()->deniedContent();
                    }
                }
            }
        }

    }

}
