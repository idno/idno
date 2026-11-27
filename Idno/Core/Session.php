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

        /**
         * The user a stateless credential authenticated for this request (a signed API request, a bearer token, a plugin's
         * hook), held in memory only: it is never written to the session, and the browser session (if any) is untouched.
         * One process is one request on the web; the worker and the console never have one between messages.
         *
         * @var \Idno\Entities\User|null
         */
        private $statelessUser = null;

        /**
         * Whether a stateless credential authenticated this request (the request is an API request).
         *
         * @var bool
         */
        private $apiRequest = false;

        private $storageHandler = false;

        private $deprecationLogged = false;

        /**
         * Whether this request started the session it runs in (it carried no session cookie, or one for a session that does
         * not exist): only such a session is destroyed when an API request ends. The anonymous session of a browser that
         * carried its cookie (a message waiting for it, a login half done) is not ours to destroy.
         *
         * @var bool
         */
        private $sessionStartedHere = false;

        /**
         * The user the browser session held when the request's authentication began or, once tryAuthUser() has finished,
         * when it ended (null: nobody). A plugin that logs a user on and only then calls setIsAPIRequest() is put right
         * against it (see setIsAPIRequest()).
         *
         * @var string|null
         */
        private $sessionUserAtAuth = null;

        private $authFinished = false;

        /**
         * The session's password fingerprint when the request's authentication began (see $sessionUserAtAuth): put back with the
         * user it belongs to when a login a plugin made is turned into a stateless authentication.
         *
         * @var string|null
         */
        private $fingerprintAtAuth = null;

        /**
         * The key of the session's fingerprint of the user's password marker (see passwordFingerprint()).
         */
        const PASSWORD_FINGERPRINT = 'password_fingerprint';

        private ?\Idno\Security\NativeSession $native = null;

        /**
         * The owner of PHP's session (the container's one): every call this class makes to PHP's session goes through it.
         */
        private function native(): \Idno\Security\NativeSession
        {
            return $this->native ??= Idno::container()->get(\Idno\Security\NativeSession::class);
        }

        private static bool $sessionDirectoryWarned = false;

        /**
         * Where file sessions are kept (see Idno\Security\SessionDirectory); one warning a process when none could be made.
         *
         * @return array{path: ?string, own: bool, problem: ?string}
         */
        private function sessionDirectory(): array
        {
            $boot       = Idno::container()->get(\Idno\Config\BootConfig::class)->all();
            $configured = $boot['session_path'] ?? null;
            $override   = $_SERVER['IDNO_SESSION_DIR'] ?? $_ENV['IDNO_SESSION_DIR'] ?? getenv('IDNO_SESSION_DIR');
            $chosen     = \Idno\Security\SessionDirectory::choose(
                is_string($configured) ? $configured : null,
                (string) ini_get('session.save_path'),
                is_string($override) ? $override : null,
                Idno::container()->getParameter('kernel.project_dir'),
                sys_get_temp_dir()
            );
            if ($chosen['problem'] !== null && !self::$sessionDirectoryWarned) {
                self::$sessionDirectoryWarned = true;
                if ($this->shouldSaySessionDirectoryProblem($chosen['problem'], $override)) {
                    Idno::site()->logging()->warning($chosen['problem']);
                }
            }

            return $chosen;
        }

        /**
         * A web request is a process of its own as far as a static is concerned, so "once" needs a memory that outlives it: a marker file in the
         * temporary directory, one for each project, setting and problem, which lets the line out once an hour. Where the marker cannot be
         * written the line is said every time (never silently dropped).
         */
        private function shouldSaySessionDirectoryProblem(string $problem, mixed $override): bool
        {
            $marker = sys_get_temp_dir() . '/idno-session-directory-' . substr(hash('sha256', Idno::container()->getParameter('kernel.project_dir') . "\0" . (is_string($override) ? $override : '') . "\0" . $problem), 0, 16);
            $age    = is_file($marker) ? time() - (int) @filemtime($marker) : null;
            if ($age !== null && $age >= 0 && $age < 3600) {
                return false;
            }

            @touch($marker);

            return true;
        }

        function init()
        {
            // The ini values are set before any handler is installed, so that a handler a plugin supplies sees them
            $this->native()->configure(new \Idno\Security\SessionSettings((bool) \Idno\Common\Page::isSSL()));

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
                if (is_scalar($storage_class) && strcasecmp(ltrim((string) $storage_class, '\\'), 'Idno\\Data\\MySQL') === 0) {
                    // The old SQL backend has been removed: its session handler (the `session` table,
                    // same handler and options) is the db() service's, on the shared connection. The
                    // name is matched as PHP matched the class: in any case, leading backslash optional.
                    $db = Idno::site()->db();
                    if ($db instanceof \Idno\Legacy\RepositoryDataConcierge) {
                        $db->handleSession();
                    }
                } else if (class_exists("{$storage_class}")) {
                    if (is_subclass_of($storage_class, "Idno\\Common\\SessionStorageInterface")) {
                        $storage_class = new $storage_class();
                        if ($storage_class->handleSession()) {
                            $this->storageHandler = $storage_class;
                            $sessions_handled = true;
                        }
                    }
                }
            }

            // Fallback to files if no other session storage handler has been defined: PHP's own, in the configured directory
            $savePath   = $sessions_handled ? null : Idno::site()->config()->session_path;
            $collection = null;
            if (!$sessions_handled && !defined('IDNO_CONSOLE') && !in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && $this->native()->storesFiles()) {
                // Files, on a web request (never the command line: whoever ran first would own the directory against the web server): a directory of the site's own unless the boot configuration or PHP names one
                $chosen = $this->sessionDirectory();
                if ($chosen['path'] !== null) {
                    $savePath = $chosen['path'];
                }
                if ($chosen['own']) {
                    // a host that collects its own directory by cron (gc_probability 0) never collects this one
                    $collection = ['probability' => 1, 'divisor' => 1000];
                }
            }
            $this->native()->start(
                Idno::site()->config()->sessionname === null ? null : (string) Idno::site()->config()->sessionname,
                $savePath,
                $collection
            );
            $this->sessionStartedHere = !$this->native()->carriedCookie();

            $this->seedSession();

            // Session login / logout
            Idno::site()->routes()->addRoute('/session/login', '\Idno\Pages\Session\Login', true);
            Idno::site()->routes()->addRoute('/session/logout', '\Idno\Pages\Session\Logout');
            Idno::site()->routes()->addRoute('/currentUser/?', '\Idno\Pages\Session\CurrentUser');

            // Update the session on save if we're saving the current user
            \Idno\Core\Idno::site()->events()->addListener(
                'save', function (\Idno\Core\Event $event) {

                    $eventdata = $event->data();
                    $object    = $eventdata['object'];

                    $current = $this->currentUser();
                    if (empty($object) || empty($current)
                        || !($object instanceof User) || !($current instanceof User)
                    ) {
                        return;
                    }

                    if ($object->getUUID() != $current->getUUID()) {
                        return;
                    }

                    // The browser session's user is checked against the session; a stateless user has no session entry.
                    if (!$this->apiRequest && !empty($_SESSION['user_uuid'])) {
                        if ($object->getUUID() != $_SESSION['user_uuid']) {
                            return;
                        }
                    }

                    $this->refreshSessionUser($object);

                    // The session that makes a change of the password keeps itself logged in: it takes the new marker
                    // (any other session of the user carries the old one, and ends at its next request).
                    // Only when THIS object changed it: a save of a stale object (an autosave) writes the stored marker, and
                    // a session that did not change the password must not take the new fingerprint with it.
                    if (!$this->apiRequest && !empty($_SESSION['user_uuid']) && $object->hasUnsavedPasswordChange()) {
                        $_SESSION[self::PASSWORD_FINGERPRINT] = $this->passwordFingerprint($object);
                    }

                }
            );

            // A stateless request authenticated nobody in the session (nothing of it is written there), so there is nothing to
            // log off at the end. The session the request itself started is destroyed, so that an API client does not leave
            // a session behind for each request; a session the request carried (the browser's, a member's or a visitor's
            // with a message waiting) is left alone.
            register_shutdown_function(
                function () {
                    $session = Idno::site()->session();
                    if ($session && $session->isAPIRequest() && $session->sessionStartedHere) {
                        $_SESSION = [];
                        $session->native()->destroy();
                    }
                }
            );
        }

        /**
         * A value taken from a request, for a log line: control characters replaced and at most 200 bytes.
         *
         * @param  mixed $value
         * @return string
         */
        private static function forLog($value)
        {
            return substr((string)preg_replace('/[\x00-\x1F\x7F]/', '?', (string)$value), 0, 200);
        }

        /**
         * Gives a session what every session holds from its first request.
         */
        private function seedSession()
        {
            // A session is never empty: the database session handler (Symfony's PdoSessionHandler) destroys an empty
            // one when it writes, so a visitor with nothing else in the session would get a new id on the next
            // request, and the form token, which is bound to the session id, would no longer match.
            if (!isset($_SESSION['secure'])) {
                $_SESSION['secure'] = \Idno\Common\Page::isSSL();
            }

            // The session holds its form token from the first request: a page draws its forms after the session is closed
            // (`DefaultTemplate::drawPage()` closes it before the shell is drawn), and a token made then could not be stored.
            \Idno\Core\Bonita\Forms::token('', 0);
        }

        /**
         * After a logout: starts a new session (a new id, nothing of the old one) so that a message can wait in it for the
         * page the visitor lands on. logUserOff() has destroyed the old session, and its cookie is expired; this one's own
         * cookie follows it. Does nothing on a stateless request, which has no session to end.
         */
        function startNewSession()
        {
            if ($this->apiRequest) {
                return;
            }
            if ($this->native()->startNew()) {
                $_SESSION = [];
                $this->sessionStartedHere = true;
                \Idno\Core\Bonita\Forms::forgetToken();
                $this->seedSession();
            }
        }

        /**
         * What a session holds to tell whether its user's password has changed since it was made: a hash of the user's
         * password marker (`password_generation`, a random value only User::setPassword() writes: a real change of the
         * password, never the replacement of an old hash of the same password by a new one). Nothing of the password
         * or its hash: the marker is random. A user who has never changed their password since the marker was introduced
         * has none, and the fingerprint is that of an empty one.
         */
        private function passwordFingerprint(User $user)
        {
            return hash('sha256', (string)$user->password_generation);
        }

        /**
         * Kill the session.
         */
        function finishEarly()
        {
            $this->native()->close();
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
            if ($this->currentUser() instanceof \Idno\Entities\User) {
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
         * Returns the currently logged-in user, if any: on an API request the user a stateless credential authenticated
         * for it (nobody when there is none), else the browser session's
         *
         * @return \Idno\Entities\User
         */

        function currentUser()
        {
            // A request that is an API request (it skips the form token) is the stateless user or nobody, never the
            // browser session's user, whatever cookie it carries and whatever became of the stateless user.
            if ($this->apiRequest) {
                return !empty($this->statelessUser) ? $this->statelessUser : false;
            }
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
         * Log the current user off. On a stateless request (see isAPIRequest()) that clears the user held in memory
         * and nothing else: the browser session, if the request carried one, and its cookie are left alone.
         *
         * @return true
         */

        function logUserOff()
        {

            \Idno\Core\Idno::site()->events()->triggerEvent(
                "user/logoff", array(
                "user"   => $this->currentUser() ?: null,
                )
            );

            if ($this->apiRequest) {
                $this->statelessUser = null;

                return true;
            }

            unset($_SESSION['user_uuid']);
            unset($this->user);

            // Unset all session variables, as per PHP docs.
            $_SESSION = [];

            // Really log the user off by destroying the cookie
            // See https://secure.php.net/manual/en/function.session-destroy.php
            $this->native()->expireCookie();

            $this->native()->destroy();

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
            $this->authFinished = false;
            $this->sessionUserAtAuth = $_SESSION['user_uuid'] ?? null;
            $this->fingerprintAtAuth = $_SESSION[self::PASSWORD_FINGERPRINT] ?? null;
            // attempt to delegate auth to a plugin. A plugin authenticates a request by returning the User (the request is then
            // authenticated statelessly), or by calling authenticateStatelessly() itself.
            $return = \Idno\Core\Idno::site()->events()->triggerEvent('user/auth/request', [], false);
            if (!($return instanceof User)) {
                // Only a User authenticates a request: any other answer (a truthy string, say) is no authentication.
                $return = false;
            }
            if ($return instanceof User && !$this->isLoggedIn()) {
                $this->apiRequest = true;
                $return = $this->refreshSessionUser($return);
            }

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
                if (!empty($user)) {
                    \Idno\Core\Idno::site()->logging()->debug("API auth found user by username: " . self::forLog($apiUsername) . " - " . $user->getName());

                    $key  = $user->getAPIkey();

                    if ((new \Idno\Security\ApiSignatureVerifier())->verify((string) ($_SERVER['REQUEST_URI'] ?? ''), (string) $key, (string) $apiSignature)) {
                        $this->apiRequest = true;
                        \Idno\Core\Idno::site()->logging()->debug("API auth verified signature for user: " . $user->getName());
                        // The user is held in memory for this request: nothing is written to the session.
                        $return = $this->refreshSessionUser($user);
                    } else {
                        \Idno\Core\Idno::site()->logging()->debug("API auth failed signature validation for user: " . $user->getName());
                        \Idno\Core\Idno::site()->logging()->debug("Please read http://docs.idno.co/en/latest/developers/plugins/api/ for further details.");
                    }
                } else {
                    \Idno\Core\Idno::site()->logging()->debug("API User given in X_IDNO_USERNAME ('" . self::forLog($apiUsername) . "') could not be found.");
                }
            }

            // auth via session credentials (a stateless request never authenticates by the session)
            if (!$return && !$this->apiRequest) {
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

                    \Idno\Core\Idno::site()->logging()->error("API Login failure from " . self::forLog($ip));
                }
            }

            $return = \Idno\Core\Idno::site()->events()->triggerEvent(
                $return ? "user/auth/success" : "user/auth/failure", array(
                "user"   => $return,
                "is api" => $this->isAPIRequest(),
                ), $return
            );

            $this->sessionUserAtAuth = $_SESSION['user_uuid'] ?? null;
            $this->authFinished = true;
            $this->endSessionStartedHere();

            return $return;
        }

        /**
         * A stateless request that STARTED its session (it carried no cookie, or one for a session that does not exist) ends it here, while it
         * is still open: a page that draws closes the session before the request ends, and a session cannot be destroyed once it is closed. The
         * session's cookie is taken off the response, and `$_SESSION` stays an array in memory for the rest of the request (a message added later
         * is drawn, nothing is stored). A session the request CARRIED is left alone.
         */
        private function endSessionStartedHere(): void
        {
            if (!$this->apiRequest || !$this->sessionStartedHere || !$this->native()->isActive()) {
                return;
            }
            // what this request has said so far (the warning about a connection in the clear) is drawn with it: the messages stay in memory
            $messages = $_SESSION['messages'] ?? [];
            $_SESSION = $messages === [] ? [] : ['messages' => $messages];
            $this->native()->destroy();
            $this->removeSessionCookie();
        }

        /**
         * Log the specified user on (note that this is NOT the same as taking the user's auth credentials)
         *
         * @param  \Idno\Entities\User $user
         * @return \Idno\Entities\User
         */

        function logUserOn(\Idno\Entities\User $user)
        {
            if (\Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                $this->logUserOff();

                return false;
            }
            $return = $this->refreshSessionUser($user);
            if ($return && !$this->apiRequest) {
                $_SESSION[self::PASSWORD_FINGERPRINT] = $this->passwordFingerprint($return);
            }
            if (!$this->apiRequest) {
                $this->native()->regenerate();
                // The form token belongs to the session id it was made for: the next form carries a new one.
                \Idno\Core\Bonita\Forms::forgetToken();
            }

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
         * Refresh the user currently stored in the session (on a stateless request: the user held in memory, which
         * the session never learns of)
         *
         * @param  \Idno\Entities\User $user
         * @return \Idno\Entities\User
         */
        function refreshSessionUser(\Idno\Entities\User $user)
        {
            if ($user = User::getByUUID($user->getUUID(), false)) {

                if (\Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                    $this->logUserOff();

                    return false;
                }

                if ($this->apiRequest) {
                    // A stateless request: the user is held in memory, and the session is not written.
                    $this->statelessUser = $user;

                    return $user;
                }

                // A user put into the session by this call (a plugin's login) carries their fingerprint with them: the
                // session would otherwise be ended at its next request.
                if (($_SESSION['user_uuid'] ?? null) !== $user->getUUID()) {
                    $_SESSION[self::PASSWORD_FINGERPRINT] = $this->passwordFingerprint($user);
                }
                $_SESSION['user_uuid'] = $user->getUUID();
                $this->user            = $user;

                return $user;
            }

            return false;
        }

        /**
         * Whether the browser session may go on as $user, once per request where the session's user is loaded: its
         * fingerprint is that of the user's password marker. A session with no fingerprint (made before sessions had one)
         * is given the current one, and stays. Costs no statement: the user is the one already loaded.
         *
         * @param  \Idno\Entities\User $user
         * @return bool
         */
        private function checkPasswordFingerprint(User $user)
        {
            $current = $this->passwordFingerprint($user);
            if (!isset($_SESSION[self::PASSWORD_FINGERPRINT]) || !is_string($_SESSION[self::PASSWORD_FINGERPRINT])) {
                // A session made before sessions had a fingerprint can only be told from one made after the password changed
                // when the user has no marker yet (no real change since): then it is adopted. A user who has a marker has
                // changed the password, and a session that cannot say it came after is ended.
                if ((string)$user->password_generation !== '') {
                    return false;
                }
                $_SESSION[self::PASSWORD_FINGERPRINT] = $current;

                return true;
            }

            return hash_equals($_SESSION[self::PASSWORD_FINGERPRINT], $current);
        }

        /**
         * If we're logged in, refresh the current session user.
         */
        function refreshCurrentSessionuser()
        {
            if (!$this->currentUser() && !empty($_SESSION['user_uuid'])) {
                if ($this->user = User::getByUUID($_SESSION['user_uuid'])) {
                    if (\Idno\Core\Idno::site()->config()->emailIsBlocked($this->user->email)) {
                        $this->logUserOff();
                        $this->startNewSession(); // a session of the visitor's own, with a form token that later requests accept
                    } else if (!$this->checkPasswordFingerprint($this->user)) {
                        // The password was changed since this session was made: it is over, as a logout ends it, and the
                        // visitor is told on the page they land on.
                        $this->logUserOff();
                        $this->startNewSession();
                        $this->addMessage(\Idno\Core\Idno::site()->language()->_("Your password was changed, so you have been signed out here. Please sign in again."));
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
         * Sets whether this request is an API request.
         *
         * Deprecated: whether a request is an API request is a property of the request, and a stateless credential decides
         * it: call authenticateStatelessly() to authenticate a request by a credential of your own, or return the User from a
         * `user/auth/request` listener. This still sets the flag for the request (never the session), and logs one deprecation line.
         *
         * @deprecated
         * @param      boolean $is_api_request
         */
        function setIsAPIRequest($is_api_request)
        {
            if (!$this->deprecationLogged) {
                $this->deprecationLogged = true;
                \Idno\Core\Idno::site()->logging()->warning('Deprecated: Session::setIsAPIRequest() is deprecated; use Session::authenticateStatelessly() or return the User from a user/auth/request listener.');
            }
            $this->apiRequest = (bool)$is_api_request;

            // A plugin that logged a user on (or refreshed the session's user) before it called this meant that user to
            // authenticate the request: hold them in memory and put the session's user back as the browser had it, so that
            // no login is planted in the session and the request is that user or nobody.
            if ($this->apiRequest && empty($this->statelessUser)) {
                $held = $_SESSION['user_uuid'] ?? null;
                if (!empty($held) && $held !== $this->sessionUserAtAuth) {
                    $user = !empty($this->user) && $this->user->getUUID() === $held ? $this->user : User::getByUUID($held);
                    if ($user instanceof User) {
                        $this->statelessUser = $user;
                    }
                    if ($this->sessionUserAtAuth === null) {
                        unset($_SESSION['user_uuid']);
                    } else {
                        $_SESSION['user_uuid'] = $this->sessionUserAtAuth;
                    }
                    // The fingerprint belongs to the user the session holds, not to the one the plugin logged on.
                    if ($this->fingerprintAtAuth === null) {
                        unset($_SESSION[self::PASSWORD_FINGERPRINT]);
                    } else {
                        $_SESSION[self::PASSWORD_FINGERPRINT] = $this->fingerprintAtAuth;
                    }
                    $this->user = null;
                }
            }
        }

        /**
         * Is this request an API request: one that a stateless credential (a signed request, a bearer token, a
         * plugin's hook) authenticated, rather than the browser session?
         *
         * @return bool
         */
        function isAPIRequest()
        {
            return $this->apiRequest;
        }

        /**
         * Authenticates this request as $user by a stateless credential the caller has checked (a bearer token, a plugin's
         * own): the user is held in memory for the request, the request becomes an API request (no form token is asked of
         * it, account pages are closed to it, the user is never an administrator), and nothing is written to the session,
         * which the request's browser session (if it carried one) keeps unchanged. Triggers `user/auth/success` (with
         * `is api` true) when the user is held.
         *
         * @param \Idno\Entities\User $user
         */
        public function authenticateStatelessly(\Idno\Entities\User $user): void
        {
            $this->apiRequest = true;
            $held = $this->refreshSessionUser($user);
            $this->endSessionStartedHere();
            if ($held) {
                \Idno\Core\Idno::site()->events()->triggerEvent(
                    "user/auth/success", array(
                    "user"   => $held,
                    "is api" => true,
                    ), $held
                );
            }
        }

        /**
         * Makes this request nobody's and an API request (it skips the form token), with no user: for a page that authenticates its own
         * state-changing requests by something other than the session, and must never act as the browser session's user. Call it before the page
         * is begun. The browser's session is let go of for the rest of the request: a session the request STARTED (it carried no cookie) is destroyed
         * here; one it CARRIED is released without being written (`session_abort()`); then `$_SESSION` and the user held in memory are emptied and the
         * session's cookie is taken off the response, so nothing later (a drawn page, an error page, a redirect, the form token, the messages) reads or
         * writes the browser's session, and a form token drawn afterwards is the process's own. `currentUser()` is nobody until `authenticateStatelessly()`, and so is Symfony Security's token.
         * Nothing is logged. (What the boot did before this call, authenticating the cookie's user and firing `user/auth/success` for it, is done.)
         */
        public function beginStatelessRequest(): void
        {
            if ($this->native()->isActive()) {
                if ($this->sessionStartedHere) {
                    $_SESSION = [];
                    $this->native()->destroy();
                } else {
                    $this->native()->abort();
                }
            }
            $_SESSION = [];
            $this->removeSessionCookie();
            $this->user          = null;
            $this->statelessUser = null;
            $this->apiRequest    = true;
            // Symfony Security knew the cookie's user too (the firewall stored it before the request reached a page): nobody is making this request there either.
            // The ONE switch, for a controller's page and a kept shell's.
            $container = Idno::container();
            if ($container->has('security.token_storage')) {
                $container->get('security.token_storage')->setToken(null);
            }
        }

        /**
         * Takes the session's own `Set-Cookie` line off the response PHP has queued (the other cookies stay).
         */
        private function removeSessionCookie(): void
        {
            $this->native()->removeCookieLine();
        }

        /**
         * Forgets any stateless authentication: the user held in memory and the API-request flag. The queue worker
         * handles many messages in one process, and none may see what an earlier one authenticated.
         */
        public function resetStatelessAuthentication(): void
        {
            $this->statelessUser = null;
            $this->apiRequest    = false;
        }

        /**
         * If the current user isn't logged in and this isn't a public site, and this hasn't been defined as an
         * always-public page, forward to the login page.
         */
        function publicGatekeeper()
        {

            if (!\Idno\Core\Idno::site()->config()->isPublicSite()) {
                if (!\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                    $page = Idno::site()->currentPage();
                    if (!\Idno\Core\Idno::site()->routes()->isRoutePublic($page)) {
                        \Idno\Core\Idno::site()->currentPage()->deniedContent();
                    }
                }
            }
        }

    }

}
