<?php

    /**
     * User representation
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    use Idno\Common\Entity;
    use Idno\Core\Email;

    #[\Idno\Storage\Attribute\AsEntity('user')]
    #[\Idno\Storage\Attribute\IndexedField('handle')]
    #[\Idno\Storage\Attribute\IndexedField('email')]
    class User extends \Idno\Common\Entity
        implements \Idno\Common\JSONLDSerialisable
    {

        /**
         * Overloading the constructor for users to make it explicit that
         * they don't have owners
         */

        /**
         * Whether this object changed the password's hash (setPassword(), setPasswordHash(), the upgrade of a hash in checkPassword())
         * since it was loaded or last saved: only then does a save write the hash. Not stored (a PHP property, not an attribute).
         *
         * @var bool
         */
        private $passwordHashChanged = false;

        /**
         * Whether this object changed the password itself (setPassword()), and so the marker that the user's sessions are
         * bound to: only then does a save write the marker.
         *
         * @var bool
         */
        private $passwordMarkerChanged = false;

        function __construct()
        {

            parent::__construct();
            $this->owner = false;

        }

        /**
         * Register user-related events
         */
        static function registerEvents()
        {

            // Hook to add user data to webfinger
            \Idno\Core\Idno::site()->events()->addListener(
                'webfinger', function (\Idno\Core\Event $event) {

                    $eventdata = $event->data();
                    $user      = $eventdata['object'];

                    $links = $event->response();
                    if (empty($links)) {
                        $links = array();
                    }

                    if ($user instanceof User) {
                        $links = [
                        [
                          'rel' => 'self',
                          'type' => 'application/activity+json',
                          'href' => $user->getActivityPubActorID()
                        ],
                        [
                          'rel'  => 'http://webfinger.net/rel/profile-page',
                          'type' => 'text/html',
                          'href' => $user->getURL()
                        ]
                        ];

                        $iconUrl = $user->getIcon();
                        $iconMime = parent::getMediaMimeType($iconUrl);
                        if ($iconUrl && $iconMime) {
                            $links[] = [
                              'rel'  => 'http://webfinger.net/rel/avatar',
                              'type' => $iconMime,
                              'href' => $iconUrl
                            ];
                        }
                    }

                    $event->setResponse($links);

                }
            );

            // The removal of a closed account's content and of the account, run by the worker (User::delete() with the asynchronous queue)
            \Idno\Core\Idno::site()->events()->addListener(
                self::REMOVE_EVENT, function (\Idno\Core\Event $event) {
                    $uuid = (string)($event->data()['uuid'] ?? '');
                    $user = $uuid !== '' ? User::getByUUID($uuid, false) : false;
                    if (!$user instanceof User || !$user->isClosed()) {
                        return; // gone already, or never closed: a message for the account of nobody, or of an account that is in use, deletes nothing
                    }
                    $outcome = $user->removeAccount();
                    if ($outcome === 'time') {
                        \Idno\Core\Idno::site()->queue()->enqueue('default', self::REMOVE_EVENT, ['uuid' => $uuid], false);
                    } else if ($outcome === 'refused') {
                        \Idno\Core\Idno::site()->logging()->warning('The removal of an account stopped: something refused to be deleted. Deleting the account again on the Users page carries on.');
                    }
                }
            );

            // Refresh session user whenever it is saved
            \Idno\Core\Idno::site()->events()->addListener(
                'saved', function (\Idno\Core\Event $event) {

                    $eventdata = $event->data();
                    $user      = $eventdata['object'];

                    if ($user instanceof User) {
                        if ($currentUser = \Idno\Core\Idno::site()->session()->currentUser()) {
                            if ($user->getUUID() == $currentUser->getUUID()) {
                                \Idno\Core\Idno::site()->session()->refreshSessionUser($user);
                            }
                        }
                    }

                }
            );

        }

        /**
         * Retrieve the URI to this user's avatar icon image
         * (if none has been saved, a default is returned)
         *
         * @return string
         */
        function getIcon()
        {
            $response = \Idno\Core\Idno::site()->events()->triggerEvent('icon', array('object' => $this));
            if (!empty($response) && $response !== true) {
                return $response;
            }
            if (!empty($this->image)) {
                return $this->image;
            }
            if (!empty($this->icon)) {
                return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'file/' . $this->icon;
            }

            return \Idno\Core\Idno::site()->template()->__(array('user' => $this))->draw('entity/User/icon');
        }

        /**
         * Retrieve the Icon object to this user's avatar icon image
         * (if none has been saved, a default is returned)
         *
         * @return object
         */
        function getIconObject()
        {
            return [
                'url' => $this->getIcon(),
                'type' => 'Image',
                'mediaType' => parent::getMediaMimeType($this->getIcon()),
            ];
        }

        /**
         * Return the user's current timezone.
         *
         * @return type
         */
        function getTimezone()
        {
            return $this->timezone;
        }

        /**
         * A friendly alias for getTitle.
         *
         * @return string
         */
        function getName()
        {
            return $this->getTitle();
        }

        /**
         * A friendly alias for SetTitle.
         *
         * @param $name
         */
        function setName($name)
        {
            return $this->setTitle($name);
        }

        /**
         * Get the profile URL for this user
         *
         * @return string
         */
        function getURL()
        {
            if (!empty($this->url)) {
                return $this->url;
            }

            return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'profile/' . $this->getHandle();
        }

        /**
         * Get the ActivityPub actor ID for this user
         *
         * @return string
         */
        function getActivityPubActorID()
        {
            // Always use the canonical actor path, never the user's profile URL.
            // The actor URL must match the route registered by the ActivityPub plugin.
            return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'actor/' . $this->getHandle();
        }

        /**
         * Get the IndieAuth identity URL for this user
         *
         * @return string
         */
        function getIndieAuthURL()
        {
            if (\Idno\Core\Idno::site()->config()->single_user) {
                return \Idno\Core\Idno::site()->config()->getDisplayURL();
            }

            return $this->getURL();
        }

        /**
         * Wrapper for getURL for consistency
         *
         * @return string
         */
        function getDisplayURL()
        {
            return $this->getURL();
        }

        /**
         * Retrieve's this user's handle
         *
         * @return string
         */

        function getHandle()
        {
            return $this->handle;
        }

        /**
         * Get endpoints for sharedInbox
         *
         * @return string
         */

        function getActivityPubEndpoints()
        {
            return [ 'sharedInbox' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'inbox'];
        }

        /**
         * Get the ActivityPub followers collection URL for this user
         *
         * @return string
         */
        function getActivityPubFollowersURL()
        {
            return $this->getActivityPubActorID() . '/followers';
        }

        /**
         * Retrieves user by email address
         *
         * @param  string $email
         * @return User|false Depending on success
         */
        static function getByEmail($email)
        {
            if (!is_string($email) || $email === '') {
                return false;
            }
            if ($result = \Idno\Core\Idno::site()->db()->getObjects(get_called_class(), array('email' => $email), null, 1)) {
                foreach ($result as $row) {
                    return $row;
                }
            }

            return false;
        }

        function getOwner()
        {
            return $this;
        }

        function getOwnerID()
        {
            return $this->getUUID();
        }

        /**
         * Retrieve a text description of this user
         *
         * @return string
         */
        function getDescription()
        {
            if (!empty($this->profile['description'])) {
                return $this->profile['description'];
            }

            return '';
        }

        /**
         * Retrieve a one-line text description of this user
         *
         * @param  int $words
         * @return string
         */
        function getShortDescription($words = 25)
        {
            if (!empty($this->profile['tagline'])) {
                $tagline = $this->profile['tagline'];
            } else if (!empty($this->short_description)) {
                $tagline = $this->short_description;
            } else {
                $tagline = $this->getDescription();
            }

            if (!empty($tagline)) {
                $description       = strip_tags($tagline);
                $description_words = explode(' ', $description);
                $description       = implode(' ', array_slice($description_words, 0, $words));
                if (count($description_words) > $words) {
                    $description .= ' ...';
                }

                return $description;
            }

            return '';
        }

        /**
         * Sets this user's username handle (and balks if someone's already using it)
         *
         * @param  string $handle
         * @return true|false True or false depending on success
         */

        function setHandle($handle)
        {
            $handle = trim($handle);
            $handle = strtolower($handle);
            if (!empty($handle) && ctype_alnum($handle)) {
                if (!self::getByHandle($handle)) {
                    $this->handle = $handle;
                }
            }

            return false;
        }

        /**
         * Retrieves user by handle
         *
         * @param  string $handle
         * @return User|false Depending on success
         */
        static function getByHandle($handle)
        {
            if (!is_string($handle) || $handle === '') {
                return false;
            }
            if ($result = \Idno\Core\Idno::site()->db()->getObjects(get_called_class(), array('handle' => $handle), null, 1)) {
                foreach ($result as $row) {
                    return $row;
                }
            }

            return false;
        }

        /**
         * Retrieve a user by their profile URL.
         *
         * @param  string $url
         * @return User|false
         */
        static function getByProfileURL($url)
        {
            if (!is_string($url) || $url === '') {
                return false;
            }
            // If user explicitly has a profile url set (generally this means it's a RemoteUser class
            if ($result = \Idno\Core\Idno::site()->db()->getObjects(get_called_class(), array('url' => $url), null, 1)) {
                foreach ($result as $row) {
                    return $row;
                }
            }
            // Ok, now try and see if we can get the local profile
            if (preg_match("~" . \Idno\Core\Idno::site()->config()->url . 'profile/([A-Za-z0-9]+)?~', $url, $matches)) {
                return \Idno\Entities\User::getByHandle($matches[1]);
            }

            // Can't find
            return false;
        }

        /**
         * Returns this user's unique key for use with the API, and generates a new one if they don't
         * have one yet
         *
         * @return string
         */
        function getAPIkey()
        {
            if ($this->isClosed()) {
                return ''; // a closed account has no key, and none is made for it
            }
            if (!empty($this->apikey)) {
                return $this->apikey;
            }

            return $this->generateAPIkey();
        }

        /**
         * Generate a semi-random API key for this user, and then return it
         *
         * @return string
         */
        function generateAPIkey()
        {
            $token = new \Idno\Core\TokenProvider();

            $apikey       = strtolower(base64_encode($token->generateToken(24)));
            $this->apikey = $apikey;
            $this->save();

            return $apikey;
        }

        /**
         * Returns this user's PublicKey object for an ActivityPub actor Profile
         *
         * @return object
         */
        function getPublicKey()
        {
            if (empty($this->publicKeyPem)) {
                $this->generateKeyPair();
            }

            $publicKey = [
                'id' => $this->getActivityPubActorID() . '#main-key',
                'owner' => $this->getActivityPubActorID(),
                'publicKeyPem' => $this->publicKeyPem,
            ];

            return $publicKey;
        }

        /**
         * Returns this user's PublicKeyPem for ActivityPub, and generates a new one if they don't
         * have one yet
         *
         * @return string
         */
        function getPublicKeyPem()
        {
            if (empty($this->publicKeyPem)) {
                $this->generateKeyPair();
            }

            return $this->publicKeyPem;
        }

        /**
         * Returns this user's PrivateKey for ActivityPub, and generates a new one if they don't
         * have one yet
         *
         * @return string
         */
        public function getPrivateKey()
        {
            if (empty($this->privateKey)) {
                $this->generateKeyPair();
            }

            return $this->privateKey;
        }

        /**
         * Generate a (ActivityPub) Key Pair for this user
         * Props to wordpress-activitypub
         *
         * @return string
         */
        function generateKeyPair()
        {
            $config = array(
                'digest_alg' => 'sha512',
                'private_key_bits' => 2048,
                'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            );

            $key = \openssl_pkey_new($config);
            $priv_key = null;

            \openssl_pkey_export($key, $priv_key);

            $detail = \openssl_pkey_get_details($key);

            // check if keys are valid
            if (
                empty($priv_key) || ! is_string($priv_key) ||
                ! isset($detail['key']) || ! is_string($detail['key'])
            ) {
                return array(
                    'private_key' => null,
                    'public_key'  => null,
                );
            }

            $this->privateKey = $priv_key;
            $this->publicKeyPem = $detail['key'];
            $this->save(true);

            return;
        }

        /**
         * Is this user an admin?
         *
         * @return bool
         */
        function isAdmin()
        {
            if (\Idno\Core\Idno::site()->session()->isAPIRequest()) {
                return false; // Refs #831 - limit admin access on API
            }
            if (!empty($this->admin)) {
                return true;
            }

            return false;
        }

        /**
         * Set this user's site administrator status
         *
         * @param bool $admin
         */
        function setAdmin($admin)
        {
            if ($admin == true) {
                $this->admin = true;
            } else {
                $this->admin = false;
            }
        }

        /**
         * May a given administrator take this member's administration rights away, or delete this member's account? Never
         * their own, and never those of the last administrator: a site always keeps somebody who can administer it.
         *
         * Only the Users page asks (the one place an administrator does either to somebody). It is a check and then an
         * act, so two administrators who demote each other at the same moment can still leave none.
         *
         * @param  string $by_uuid The UUID of the administrator who asks
         * @return bool
         */
        function canBeDemotedOrDeletedBy($by_uuid)
        {
            return $this->whyNotRemovableBy($by_uuid) === '';
        }

        /**
         * How many administrators the site has. The one place the count is made: administrators who cannot sign in
         * (an account that is being deleted, see isClosed()) are not counted.
         *
         * @return int
         */
        static function countAdministrators()
        {
            $count = 0;
            foreach (static::get(['admin' => true], [], 10000) ?: [] as $user) {
                if (!empty($user->admin) && !$user->isClosed()) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * Can a specified user (either an explicitly specified user ID
         * or the currently logged-in user if this is left blank) edit
         * this user?
         *
         * @param  string $user_id
         * @return true|false
         */

        function canEdit($user_id = '')
        {

            if (!parent::canEdit($user_id)) {
                return false;
            }

            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUserUUID();
            }

            if ($user_id == $this->getUUID()) {
                return true;
            }

            return \Idno\Core\Idno::site()->events()->triggerEvent('canEdit/user', ['object' => $this, 'user_id' => $user_id], false);

        }

        /**
         * Retrieve the URL required to edit this user
         *
         * @return string
         */
        function getEditURL()
        {
            return \Idno\Core\Idno::site()->config()->url . 'profile/' . $this->getHandle() . '/edit';
        }

        /**
         * Sets the built-in password property to a safe hash (if the
         * password is acceptable)
         *
         * @param  string $password
         * @return true|false
         */
        function setPassword($password)
        {
            if (!empty($password)) {
                try {
                    $this->password = self::passwords()->hash((string)$password);
                    $this->passwordHashChanged = true;
                    // A real change of the password: the marker that the sessions of the user are bound to
                    // (see Session::passwordFingerprint()). The replacement of a hash of the same password by a new one
                    // (checkPassword()) leaves it alone.
                    $this->password_generation = bin2hex(random_bytes(16));
                    $this->passwordMarkerChanged = true;
                } catch (\InvalidArgumentException $e) {
                    // Empty once trimmed, or longer than the hasher accepts
                    return false;
                }

                return true;
            }

            return false;
        }

        /**
         * Stores a ready-made hash of the user's password (an import, a migration): the password is not changed, so no marker
         * is written and no session of the user ends. A save writes it, as it writes the hash setPassword() makes.
         *
         * @param  string $hash
         * @return true
         */
        function setPasswordHash($hash)
        {
            $this->password = (string)$hash;
            $this->passwordHashChanged = true;

            return true;
        }

        /**
         * Whether this object has changed the user's password and not yet saved it: a save of it is the change of the password
         * (the session that makes it takes the new fingerprint).
         *
         * @return bool
         */
        function hasUnsavedPasswordChange()
        {
            return $this->passwordMarkerChanged;
        }

        /**
         * Verifies that the supplied password matches this user's password. A hash that is not as
         * a new one would be (another cost, or one PHP made by cutting a long password) is replaced
         * by a new one when the password is right; a failure to store it never fails the check.
         *
         * @param  string $password
         * @return true|false
         */
        function checkPassword($password)
        {
            if (empty(trim($password))) {
                return false;
            }
            $current = (string)$this->password;
            $result  = self::passwords()->verifyAndRehash($current, (string)$password);
            if ($result === null) {
                return false;
            }
            if ($result !== $current) {
                try {
                    $this->password = $result;
                    $this->passwordHashChanged = true;
                    $this->save(true);
                } catch (\Throwable $e) {
                    \error_log('Idno: could not store a password hash that was upgraded: ' . get_class($e));
                }
            }

            return true;
        }

        /**
         * The password service: the container's, or one built directly where there is no container
         *
         * @return \Idno\Security\Passwords
         */
        private static function passwords()
        {
            try {
                return \Idno\Core\Idno::container()->get(\Idno\Security\Passwords::class);
            } catch (\Throwable $e) {
                return new \Idno\Security\Passwords();
            }
        }

        /**
         * Check that a new password is strong.
         *
         * @param  string $password
         * @return bool
         */
        static function checkNewPasswordStrength($password)
        {

            $default = false;

            // Default "base" password validation
            if (strlen($password) >= 7) {
                $default = true;
            }

            return \Idno\Core\Idno::site()->events()->triggerEvent(
                'user/password/checkstrength', array(
                'password' => $password
                ), $default
            );

        }

        /**
         * Retrieve the current password recovery code - if it's less than three hours old
         *
         * @return string|false
         */
        function getPasswordRecoveryCode()
        {
            if ($code = $this->password_recovery_code) {
                if ($this->password_recovery_code_time > (time() - (3600 * 3))) {
                    return $code;
                }
            }

            return false;
        }

        /**
         * Add a password recovery code to the user
         *
         * @return string The new recovery code, suitable for sending in an email
         */
        function addPasswordRecoveryCode()
        {
            $token = new \Idno\Core\TokenProvider();

            $auth_code                         = bin2hex($token->generateToken(16));
            $this->password_recovery_code      = $auth_code;
            $this->password_recovery_code_time = time();

            return $auth_code;
        }

        /**
         * Clears this user's password recovery code (eg if they log in and don't need it anymore).
         */
        function clearPasswordRecoveryCode()
        {
            $this->password_recovery_code = false;
        }

        /**
         * Does this user have everything he or she needs to be a fully-fledged
         * Idno member? This method checks to make sure the minimum number of
         * fields are filled in.
         *
         * @return true|false
         */

        function isComplete()
        {
            $handle = $this->getHandle();
            $title  = $this->getTitle();
            if (!empty($handle) && !empty($title)) {
                return true;
            }

            return false;
        }

        /**
         * Count the number of posts this user has made
         *
         * @return int
         */
        function countPosts()
        {
            $types = \Idno\Common\ContentType::getRegisteredClasses();
            return \Idno\Common\Entity::countFromX($types, array('owner' => $this->getUUID()));
        }

        /**
         * Given a user entity (or a UUID), marks them as being followed by this user.
         * Remember to save this user entity.
         *
         * @param  \Idno\Entities\User|string $user
         * @return bool
         */
        function addFollowing($user)
        {
            if ($user instanceof \Idno\Entities\User) {
                $users = $this->getFollowingUUIDs();
                if (!in_array($user->getUUID(), $users, true)) {
                    $users[$user->getUUID()] = array('name' => $user->getTitle(), 'icon' => $user->getIcon(), 'url' => $user->getURL());
                    $this->following         = $users;

                    // Create/modify ACL for following user
                    $acl = \Idno\Entities\AccessGroup::getOne(
                        array(
                        'owner'             => $this->getUUID(),
                        'access_group_type' => 'FOLLOWING'
                        )
                    );

                    if (empty($acl)) {
                        $acl                    = new \Idno\Entities\AccessGroup();
                        $acl->title             = "People I follow...";
                        $acl->access_group_type = 'FOLLOWING';
                    }

                    $acl->addMember($user->getUUID());
                    $acl->save(true);

                    \Idno\Core\Idno::site()->events()->triggerEvent('follow', array('user' => $this, 'following' => $user));

                    return true;
                }
            }

            return false;
        }

        /**
         * Get a list of user UUIDs that this user marks as following
         *
         * @return array|null
         */
        function getFollowingUUIDs()
        {
            if (!empty($this->following)) {
                return array_keys($this->following);
            } else {
                return array();
            }
        }

        /**
         * Returns a list of users that this user marks as following, where the UUID is the array key, and
         * the array is of the form ['name' => 'Name', 'url' => 'Profile URL', 'icon' => 'Icon URI']
         *
         * @return array|null
         */
        function getFollowingArray()
        {
            if (!empty($this->following)) {
                return $this->following;
            } else {
                return array();
            }
        }

        /**
         * Given a user entity (or a UUID), removes them from this user's followed list.
         * Remember to save this user entity.
         *
         * @param  \Idno\Entities\User|string $user
         * @return bool
         */
        function removeFollowing($user)
        {
            if ($user instanceof \Idno\Entities\User) {
                $users = $this->getFollowingUUIDs();
                unset($users[$user->getUUID()]);
                $this->following = $users;

                $acl = \Idno\Entities\AccessGroup::getOne(
                    array(
                    'owner'             => $this->getUUID(),
                    'access_group_type' => 'FOLLOWING'
                    )
                );

                if (!empty($acl)) {
                    $acl->removeMember($user->getUUID());
                    $acl->save(true);
                }

                \Idno\Core\Idno::site()->events()->triggerEvent('unfollow', array('user' => $this, 'following' => $user));

                return true;
            }

            return false;
        }

        /**
         * Is the given user following this user?
         *
         * @param  \Idno\Entities\User $user
         * @return bool
         */
        function isFollowedBy($user)
        {
            if ($user instanceof \Idno\Entities\User) {
                if ($user->isFollowing($this)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Is the given user a followed by this user?
         *
         * @param  \Idno\Entities\User|string $user
         * @return bool
         */
        function isFollowing($user)
        {
            if ($user instanceof \Idno\Entities\User) {
                if (in_array($user->getUUID(), $this->getFollowingUUIDs())) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Array of access groups that this user can *read* entities
         * from
         *
         * @return array
         */

        function getReadAccessGroups()
        {
            return $this->getXAccessGroups('read');
        }

        /**
         * Get an array of access groups that this user has arbitrary permissions for
         *
         * @param  string $permission The type of permission
         * @return array
         */
        function getXAccessGroups($permission)
        {
            $return = array('PUBLIC', 'SITE', $this->getUUID());
            if ($groups = \Idno\Core\Idno::site()->db()->getObjects('Idno\\Entities\\AccessGroup', array('members.' . $permission => $this->getUUID()), null, PHP_INT_MAX, 0)) {
                $return = array_merge($return, $groups);
            }

            return \Idno\Core\Idno::site()->events()->triggerEvent("permission:$permission:entities", ['user' => $this], $return);
        }

        /**
         * Array of access groups that this user can *write* entities
         * to
         *
         * @return array
         */

        function getWriteAccessGroups()
        {
            return $this->getXAccessGroups('write');
        }

        /**
         * Array of access group IDs that this user can *read* entities
         * from
         *
         * @return array
         */

        function getReadAccessGroupIDs()
        {
            return $this->getXAccessGroupIDs('read');
        }

        /**
         * Get an array of access group IDs that this user has an arbitrary permission for
         *
         * @param  string $permission Permission type
         * @return array
         */
        function getXAccessGroupIDs($permission)
        {
            $return = array('PUBLIC', 'SITE', $this->getUUID());
            if ($groups = \Idno\Core\Idno::site()->db()->getRecords(
                array('uuid' => true),
                array(
                    'entity_subtype'         => 'Idno\\Entities\\AccessGroup',
                    $permission => $this->getUUID()),
                PHP_INT_MAX,
                0
            )
            ) {
                foreach ($groups as $group) {
                    $return[] = $group['uuid'];
                }
            }

            return \Idno\Core\Idno::site()->events()->triggerEvent("permission:$permission:ids", ['user' => $this], $return);
        }

        /**
         * Array of access group IDs that this user can *write* entities
         * to
         *
         * @return type
         */

        function getWriteAccessGroupIDs()
        {
            return $this->getXAccessGroupIDs('write');
        }

        /**
         * Does this user have the given permission.
         *
         * @param string $permission
         */
        function hasPermission($permission)
        {
            $permissions = $this->permissions;
            if (empty($permissions)) {
                $permissions = [];
            }

            $key = array_search($permission, $permissions);

            if ($key!==false) {
                return true;
            }

            return false;
        }

        /**
         * Grant access to a specific permission.
         *
         * @param string $permission
         */
        function grantPermission($permission)
        {

            $permissions = $this->permissions;
            if (empty($permissions)) {
                $permissions = [];
            }

            $permissions[] = $permission;

            $this->permissions = array_unique($permissions);

            return $this->save();
        }

        /**
         * Revoke a given permission.
         *
         * @param type $permission
         */
        function revokePermission($permission)
        {
            $permissions = $this->permissions;
            if (empty($permissions)) {
                $permissions = [];
            }

            $key = array_search($permission, $permissions);
            if ($key !== false) {
                unset($permissions[$key]);
            }

            $this->permissions = array_unique($permissions);

            return $this->save();
        }

        /**
         * Users are activity streams objects of type "person".
         *
         * @return string
         */
        function getActivityStreamsObjectType()
        {
            $uuid = $this->getUUID();
            if (!empty($uuid)) {
                return 'person';
            }

            return false;
        }

        /**
         * Return the total size of all files owned by this user
         *
         * @return int
         */
        function getFileUsage()
        {
            $bytes = 0;

            // Gather bytes

            return $bytes;
        }

        /**
         * Flag this user for "Restricted Processing" as defined under the GDPR
         *
         * @see   https://techblog.bozho.net/gdpr-practical-guide-developers/
         * @param type $restrict
         */
        public function setRestrictedProcessing($restrict = true)
        {
            $this->restrictedProcessing = $restrict;
        }

        /**
         * Get the "Restricted processing" status of this user.
         *
         * @return boolean
         */
        public function getRestrictedProcessing()
        {

            if (!empty($this->restrictedProcessing)) {
                return true;
            }

            return false;
        }

        /**
         * @deprecated Notifications have been removed. This method is a no-op for backward compatibility.
         * @param mixed $notification
         * @return bool
         */
        public function notify($notification)
        {
            return true;
        }

        /**
         * @deprecated Notifications have been removed. Always returns 0.
         * @return int
         */
        public function countUnreadNotifications()
        {
            return 0;
        }

        /**
         * Save form input
         *
         * @param  \Idno\Common\Page $page
         * @return bool|string|null
         */
        function saveDataFromInput()
        {

            if (!$this->canEdit()) {
                return false;
            }

            $profile = \Idno\Core\Idno::site()->currentPage()->getInput('profile');
            if (!empty($profile)) {
                $this->profile = $profile;
            }
            if ($name = \Idno\Core\Idno::site()->currentPage()->getInput('name')) {
                $this->setName($name);
            }
            if (!empty($_FILES['avatar'])) {
                if (in_array($_FILES['avatar']['type'], array('image/png', 'image/jpg', 'image/jpeg', 'image/gif'))) {
                    if (getimagesize($_FILES['avatar']['tmp_name'])) {
                        if ($icon = \Idno\Entities\File::createThumbnailFromFile($_FILES['avatar']['tmp_name'], $_FILES['avatar']['name'], 300, true)) {
                            $this->icon = (string)$icon;
                        } else if ($icon = \Idno\Entities\File::createFromFile($_FILES['avatar']['tmp_name'], $_FILES['avatar']['name'])) {
                            $this->icon = (string)$icon;
                        }
                    }
                }
            }

            return $this->save();

        }

        /**
         * Wrapper for Entity::save()
         * @return false|\Idno\Core\id
         */
        function save($overrideAccess = true)
        {
            // Entity::save() writes the whole document, so an object loaded before another request changed the password (an
            // autosave, a settings form) would write the OLD hash and marker back. The hash and the marker are written only
            // when THIS object changed them; otherwise an existing user takes both from the stored record, just before it is
            // written (one read for each save of a user, never for a request). A new user has no stored record.
            if (!empty($this->_id) && !($this->passwordHashChanged && $this->passwordMarkerChanged)) {
                $stored = \Idno\Core\Idno::site()->db()->getRecord($this->_id);
                if (is_array($stored)) {
                    if (!$this->passwordHashChanged) {
                        $this->password = $stored['password'] ?? null;
                    }
                    if (!$this->passwordMarkerChanged) {
                        $this->password_generation = $stored['password_generation'] ?? null;
                    }
                }
            }
            $result = parent::save($overrideAccess);
            if ($result) {
                $this->passwordHashChanged   = false;
                $this->passwordMarkerChanged = false;
            }

            return $result;
        }

        /// How many of an account's entities are looked up and deleted at a time
        const CONTENT_BATCH = 100;

        /// The event the worker runs to remove a closed account: its data is the `uuid`
        const REMOVE_EVENT = 'account/remove';

        /// The collections whose records an account owns (the site's own `site` collection is the site's, not a member's)
        const CONTENT_COLLECTIONS = ['entities', 'reader'];

        /**
         * Whether this account is closed: it is being deleted, or the deletion stopped before it was done. THE one question that decides whether an account
         * may sign in or act: a closed account cannot log on (a password, a session, a signed request, a bearer token, a queued event run as it), has no
         * API key, and its profile and posts answer as missing to everyone but an administrator. It is the first stored step of delete(), made before
         * anything is removed.
         *
         * @return bool
         */
        function isClosed()
        {
            return !empty($this->account_closed);
        }

        /**
         * Whether the account with this uuid is closed, without loading it where that can be avoided: a post is readable only while its owner's account is
         * not (Entity::canRead()), and asking costs no statement once the answer is in the site's cache. The answer for an open account is kept for five
         * minutes, and close() replaces it at once.
         *
         * @param  mixed $uuid
         * @return bool
         */
        static function uuidIsClosed($uuid)
        {
            if (!is_string($uuid) || $uuid === '') {
                return false;
            }
            $cache = \Idno\Core\Idno::site()->cache();
            $key   = 'account-closed-' . md5($uuid);
            $known = $cache->load($key);
            if (is_bool($known)) {
                return $known;
            }
            $user   = static::getByUUID($uuid);
            $closed = $user instanceof User && $user->isClosed();
            $cache->store($key, $closed, $closed ? 86400 * 30 : 300);

            return $closed;
        }

        /**
         * Closes the account: the first step of deleting it, made before anything is removed, in one save. It is marked as being deleted, its password
         * stops working and the marker its sessions are bound to changes (every session of it ends, as at a change of password), its API key, a password
         * recovery code and every IndieAuth code and token are removed, and the event `account/closed` is triggered once (the ActivityPub plugin tells the followers that the actor
         * is gone, signing with the key the account still has). Closing a closed account does nothing and is true. The handle and the email address stay
         * taken until the account is deleted.
         *
         * @return bool Whether the account is closed (false: it could not be saved, and nothing was changed)
         */
        function close()
        {
            if ($this->isClosed()) {
                return true;
            }
            $previous = [$this->account_closed, $this->password, $this->password_generation, $this->apikey, $this->indieauth_codes, $this->indieauth_tokens, $this->password_recovery_code];
            $this->account_closed      = time();
            $this->password            = '!'; // a hash nothing verifies
            $this->passwordHashChanged = true;
            $this->password_generation = bin2hex(random_bytes(16));
            $this->passwordMarkerChanged = true;
            $this->apikey              = '';
            $this->indieauth_codes     = [];
            $this->indieauth_tokens    = [];
            $this->clearPasswordRecoveryCode();
            if (!$this->save(true)) {
                [$this->account_closed, $this->password, $this->password_generation, $this->apikey, $this->indieauth_codes, $this->indieauth_tokens, $this->password_recovery_code] = $previous;
                $this->passwordHashChanged   = false;
                $this->passwordMarkerChanged = false;

                return false;
            }
            \Idno\Core\Idno::site()->cache()->store('account-closed-' . md5((string) $this->getUUID()), true, 86400 * 30);
            try {
                \Idno\Core\Idno::site()->events()->triggerEvent('account/closed', ['object' => $this]);
            } catch (\Throwable $e) {
                // The account is closed whatever a plugin's listener does about it
                \Idno\Core\Idno::site()->logging()->error('A listener of account/closed failed: ' . get_class($e));
            }

            return true;
        }

        /**
         * A closed account's profile is for administrators alone, and nobody else reads its record.
         *
         * @param  string $user_id
         * @return true|false
         */
        function canRead($user_id = '')
        {
            if ($this->isClosed()) {
                if (empty($user_id)) {
                    $user_id = \Idno\Core\Idno::site()->session()->currentUserUUID();
                }
                $viewer = $user_id ? static::getByUUID($user_id) : false;

                return $viewer instanceof User && $viewer->isAdmin();
            }

            return parent::canRead($user_id);
        }

        /**
         * Delete this account, and everything it owns. Deleting an account is a job in two stages, and the account is closed (close()) before anything
         * is removed. Then the content goes entity by entity (deleteContent()), and the account record last, only when nothing it owns is left.
         *
         * With the asynchronous queue the content is removed by a worker (the event `account/remove`, run as nobody, naming the account by its uuid; it
         * queues itself again while content remains): the account is closed and false is returned. With the synchronous queue the job runs here, for
         * a bounded time; when there is more than it can do in that time, or something refuses to be deleted, the account stays closed and false is
         * returned, and deleting it again carries on. Code that calls this with no page (a test's tearDown, a console command) has the same: on a site
         * with the synchronous queue it does the whole job, with no time limit where PHP has none.
         *
         * Every step is idempotent. Per-post announcements are not made (AccountContent::whileRemoving()): the followers are told once, when the
         * account is closed, that the actor is gone.
         *
         * @return bool Was the account deleted?
         */
        function delete()
        {
            $site    = \Idno\Core\Idno::site();
            $session = $site->session();
            $lang    = $site->language();
            if (!$this->close()) {
                $session->addErrorMessage($lang->esc_("%s could not be closed, so nothing was deleted. Please try again.", [$this->getTitle()]));

                return false;
            }
            if ($site->queue()->isAsynchronous()) {
                if ($site->queue()->enqueue('default', self::REMOVE_EVENT, ['uuid' => $this->getUUID()], false) === false) {
                    $session->addErrorMessage($lang->esc_("%s is closed, but removing the account could not be started. Delete it again to carry on.", [$this->getTitle()]));
                } else {
                    $session->addMessage($lang->esc_("%s is closed and is being deleted: its content is removed in the background, and the account when nothing of it is left.", [$this->getTitle()]));
                }

                return false;
            }
            $outcome = $this->removeAccount();
            if ($outcome === 'removed') {
                return true;
            }
            if ($outcome === 'time') {
                $session->addErrorMessage($lang->esc_("%s is closed and is being deleted, but there is more to delete than one request can do. Delete it again to carry on.", [$this->getTitle()]));
            } else {
                $session->addErrorMessage($lang->esc_("%s is closed and is being deleted, but something that belongs to it could not be deleted (a plugin may have refused). Delete it again to carry on.", [$this->getTitle()]));
            }

            return false;
        }

        /**
         * The job of deleting a closed account, as far as it gets in $seconds: its content (deleteContent()) and, when nothing is left, the account
         * record, quietly (AccountContent::whileRemoving()).
         *
         * @param  int        $batch
         * @param  float|null $seconds see deleteContent()
         * @return string 'removed' (the account is gone), 'time' (more to do: call again) or 'refused' (something refuses to be deleted)
         */
        function removeAccount($batch = self::CONTENT_BATCH, $seconds = null)
        {
            return \Idno\Core\AccountContent::whileRemoving(
                function () use ($batch, $seconds) {
                    $outcome = $this->deleteContent($batch, $seconds);
                    if ($outcome !== 'done') {
                        return $outcome;
                    }

                    return parent::delete() ? 'removed' : 'refused';
                }
            );
        }

        /**
         * The avatar is a stored file of the account, and goes with it.
         */
        function deleteData()
        {
            $id = strtok((string) $this->icon, '/');
            if (is_string($id) && preg_match('/^[a-f0-9]{24}(?:[a-f0-9]{8})?$/i', $id)) {
                try {
                    if ($file = \Idno\Entities\File::getByID($id)) {
                        $file->delete();
                    }
                } catch (\Throwable $e) {
                    \Idno\Core\Idno::site()->logging()->warning('Could not delete the avatar of a deleted account: ' . get_class($e));
                }
            }

            return true;
        }

        /**
         * Delete what this account owns, one entity at a time, until it is all gone or the time is up. What it owns is every RAW RECORD of the entity
         * collections whose owner is the account, found through the storage layer (so a record whose class no longer exists, or whose class cannot be
         * built, is found and counted too), except the site's own content (AccountContent). An entity that can be built is deleted through its own delete(),
         * so its hooks run and its files go; one that cannot is removed as a record, with the files its document names. One that refuses (a listener says
         * no) stays, and the job goes on past it, so it cannot loop for ever. Access is ignored for the lookups and restored afterwards. The time is checked
         * after each entity, and at least one is done for each call.
         *
         * 'done' is said only when a COUNT of what the account still owns equals what was walked past and kept on purpose, so the account is removed
         * only when nothing of it is left.
         *
         * @param  int        $batch   How many records to look up at a time
         * @param  float|null $seconds The time to work for (null: well inside PHP's time limit for a request, or half a minute where there is none)
         * @return string 'done', 'time' (call again to carry on) or 'refused' (something stays because a listener refused to delete it)
         */
        function deleteContent($batch = self::CONTENT_BATCH, $seconds = null)
        {
            $batch    = max(1, (int) $batch);
            $limit    = (int) ini_get('max_execution_time');
            $seconds  = $seconds ?? ($limit > 0 ? $limit * 0.6 : 30.0);
            $deadline = microtime(true) + $seconds;
            $uuid     = (string) $this->getUUID();
            $db       = \Idno\Core\Idno::site()->db();
            $before   = $db->getIgnoreAccess();
            $abort    = ignore_user_abort(true); // the entity the request is in the middle of is finished, with its files
            $db->setIgnoreAccess(true);
            try {
                $kept    = 0;
                $refused = 0;
                $worked  = 0;
                foreach (self::CONTENT_COLLECTIONS as $collection) {
                    $offset = 0; // the records of the collection that stay where they are: the next batch begins after them
                    while ($records = $db->getRecords([], ['owner' => $uuid], $batch, $offset, $collection)) {
                        foreach ($records as $record) {
                            if ($worked > 0 && microtime(true) >= $deadline) {
                                return 'time';
                            }
                            if (($record['uuid'] ?? '') === $uuid || \Idno\Core\AccountContent::keeps($record)) {
                                $kept++;
                                $offset++;
                            } else if ($this->removeOwnedRecord($record, $collection)) {
                                $worked++;
                            } else {
                                $refused++;
                                $offset++;
                            }
                        }
                    }
                }
                if ($refused > 0) {
                    return 'refused';
                }
                $owned = 0;
                foreach (self::CONTENT_COLLECTIONS as $collection) {
                    $owned += (int) $db->countRecords(['owner' => $uuid], $collection);
                }

                return $owned === $kept ? 'done' : 'time';
            } finally {
                $db->setIgnoreAccess($before);
                ignore_user_abort($abort);
            }
        }

        /**
         * Removes one record the account owns. An entity that can be built is deleted through its own delete() (false when a listener refuses); a record
         * that cannot be built is removed as a record, and the files its document names go with it.
         *
         * @param  array  $record
         * @param  string $collection
         * @return bool Whether it is gone
         */
        private function removeOwnedRecord(array $record, $collection)
        {
            $db     = \Idno\Core\Idno::site()->db();
            $entity = false;
            if ($collection === 'entities') {
                try {
                    $entity = $db->rowToEntity($record);
                } catch (\Throwable $e) {
                    \Idno\Core\Idno::site()->logging()->warning('A record of a deleted account could not be loaded as an entity and is removed as a record: ' . get_class($e));
                }
            }
            if ($entity instanceof Entity) {
                try {
                    return (bool) $entity->delete();
                } catch (\Throwable $e) {
                    \Idno\Core\Idno::site()->logging()->error('Deleting an entity of an account failed: ' . get_class($e));

                    return false;
                }
            }
            if (!$db->deleteRecord((string) ($record['_id'] ?? ''), $collection)) {
                return false;
            }
            foreach ((array) ($record['attachments'] ?? []) as $attachment) {
                $id = is_array($attachment) ? (string) ($attachment['_id'] ?? '') : '';
                if ($id !== '' && ($file = \Idno\Entities\File::getByID($id))) {
                    $file->delete();
                }
            }

            return true;
        }

        /**
         * Tells the administrator who asked, in the session's messages, why this account was not deleted or demoted (see whyNotRemovableBy()).
         *
         * @param string $why 'self' or 'last'
         */
        function explainWhyNotRemovable($why)
        {
            $session  = \Idno\Core\Idno::site()->session();
            $language = \Idno\Core\Idno::site()->language();
            if ($why === 'self') {
                $session->addErrorMessage($language->_("You can't take away your own administration rights or delete your own account here. Another administrator can."));
            } else {
                $session->addErrorMessage($language->esc_("%s is the only administrator, so nothing was changed.", [$this->getTitle()]));
            }
        }

        /**
         * Why an administrator may not delete this account or take its rights away: 'self' (their own), 'last' (the last administrator), or ''
         * when they may. canBeDemotedOrDeletedBy() is this as a yes or no.
         *
         * @param  string $by_uuid The UUID of the administrator who asks
         * @return string
         */
        function whyNotRemovableBy($by_uuid)
        {
            if ((string) $by_uuid === (string) $this->getUUID()) {
                return 'self';
            }
            // (an account that is closed was let through when it was closed: carrying on with its deletion is always allowed)
            if (!$this->isClosed() && !empty($this->admin) && static::countAdministrators() <= 1) {
                return 'last';
            }

            return '';
        }

        public function jsonSerialize(): mixed
        {
            $data          = parent::jsonSerialize();

            unset($data['updated']);
            unset($data['published']);

            $data['image'] = array('url' => $this->getIcon());
            if (!empty($this->profile['url'])) {
                $data['sameAs'] = $this->profile['url'];
            }

            return $data;
        }

        /**
         * The member as schema.org structured data, which every page of the member and of their posts publishes: it holds the member's name and never the account's email address,
         * which is private (an address the member lists on their profile is a link there).
         */
        public function jsonLDSerialise(array $params = array())
        {
            $json = [
                '@context' => 'http://schema.org',
                '@type' => 'Person',
                'name' => $this->getName(),
                'image' => $this->getIcon(),
                'url' => $this->getURL(),
            ];
            if (!empty($this->profile['url'])) {
                $json['sameAs'] = $this->profile['url'];
            }

            return $json;
        }

    }

}
