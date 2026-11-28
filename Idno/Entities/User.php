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

    class User extends \Idno\Common\Entity
        implements \Idno\Common\JSONLDSerialisable
    {

        /**
         * Overloading the constructor for users to make it explicit that
         * they don't have owners
         */

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

            // The removal of a closed account's content and then of the account, run by the
            // queue worker (delete() with the asynchronous queue). It queues itself again
            // only when the run removed something.
            \Idno\Core\Idno::site()->events()->addListener(
                self::REMOVE_EVENT, function (\Idno\Core\Event $event) {
                    $uuid = $event->data()['uuid'] ?? '';
                    $user = is_string($uuid) && $uuid !== '' ? User::getByUUID($uuid, false) : false;
                    if (!$user instanceof User || !$user->isClosed()) {
                        return; // gone already, or not closed: a message naming an account that is in use deletes nothing
                    }
                    $outcome = $user->removeAccount(self::CONTENT_BATCH, self::removalSeconds());
                    if ($outcome === 'time') {
                        \Idno\Core\Idno::site()->queue()->enqueue('default', self::REMOVE_EVENT, ['uuid' => $uuid], false);
                    } else if ($outcome === 'refused') {
                        \Idno\Core\Idno::site()->logging()->warning('The removal of an account stopped: something it owns could not be removed (the log says what). "Continue deleting" on the Users page carries on.');
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
            if (!static::isLookupValue($email)) {
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
                if (!self::getByHandle($handle) && !\Idno\Core\AccountRegistry::handleIsReserved($handle)) {
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
            if (!static::isLookupValue($handle)) {
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
            if (!static::isLookupValue($url)) {
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
                return ''; // A closed account has no key, and is given none
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
         * Has this member been banned from the site? A banned member cannot sign in: Session
         * refuses them wherever it refuses a blocked email address, which ends their browser
         * sessions on the next request and stops their API key and access tokens.
         *
         * @return bool
         */
        function isBanned()
        {
            return !empty($this->banned);
        }

        /**
         * Ban this member. The caller saves the member.
         *
         * Nobody can ban themselves, and the last administrator who is not banned cannot be
         * banned. Banning an administrator takes the administrator right away; lifting the
         * ban does not give it back.
         *
         * @param  string $reason       Why, for the administrators. Never shown to anyone else.
         * @param  string $by_uuid      The UUID of the administrator who is banning
         * @param  bool   $hide_profile Whether the member's profile is hidden too (see isHiddenByBan())
         * @return bool False (and nothing changed) if this member must not be banned
         */
        function ban($reason, $by_uuid, $hide_profile = false)
        {
            if (!$this->canBeBannedBy($by_uuid)) {
                return false;
            }

            $this->banned = time();
            $this->ban_reason = trim(strip_tags((string) $reason));
            $this->banned_by = (string) $by_uuid;
            $this->ban_hides_profile = (bool) $hide_profile;
            $this->setAdmin(false);

            return true;
        }

        /**
         * May this member be banned by a given administrator? Nobody can ban themselves, and
         * the last administrator who is not banned cannot be banned.
         *
         * @param  string $by_uuid The UUID of the administrator who would ban
         * @return bool
         */
        function canBeBannedBy($by_uuid)
        {
            if ((string) $by_uuid === (string) $this->getUUID()) {
                return false;
            }
            if (!empty($this->admin) && !$this->isBanned() && !$this->isClosed() && static::countUnbannedAdmins() <= 1) {
                return false;
            }

            return true;
        }

        /**
         * May a given administrator take this member's administration rights away, or delete
         * this member's account? Never their own, and never those of the last administrator
         * who can sign in: a site always keeps somebody who can administer it.
         *
         * @param  string $by_uuid The UUID of the administrator who asks
         * @return bool
         */
        function canBeDemotedOrDeletedBy($by_uuid)
        {
            if ((string) $by_uuid === (string) $this->getUUID()) {
                return false;
            }
            // An account that is closed passed this check when it was closed: carrying on
            // with its deletion is always allowed
            if ($this->isClosed()) {
                return true;
            }

            return $this->canBeBannedBy($by_uuid);
        }

        /**
         * Lift this member's ban. The caller saves the member.
         */
        function unban()
        {
            $this->banned = false;
            $this->ban_reason = '';
            $this->banned_by = '';
            $this->ban_hides_profile = false;
        }

        /**
         * Is this member banned in a way that hides their profile from everyone but the
         * administrators? The profile page then answers as if there were no such member.
         *
         * @return bool
         */
        function isHiddenByBan()
        {
            return $this->isBanned() && !empty($this->ban_hides_profile);
        }

        /**
         * When, why and by whom this member was banned. For administrators' pages only.
         *
         * @return array|null 'time', 'reason' and 'by' (a UUID), or null if the member is not banned
         */
        function getBan()
        {
            if (!$this->isBanned()) {
                return null;
            }

            return ['time' => (int) $this->banned, 'reason' => (string) $this->ban_reason, 'by' => (string) $this->banned_by];
        }

        /**
         * How many administrators the site has who can sign in: not banned, and their account
         * not closed (being deleted)
         *
         * @return int
         */
        static function countUnbannedAdmins()
        {
            $count = 0;
            foreach (static::get(['admin' => true], [], 10000) ?: [] as $user) {
                if (!empty($user->admin) && !$user->isBanned() && !$user->isClosed()) {
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
                $this->password = \password_hash($password, PASSWORD_BCRYPT);

                return true;
            }

            return false;
        }

        /**
         * Verifies that the supplied password matches this user's password
         *
         * @param  string $password
         * @return true|false
         */
        function checkPassword($password)
        {
            if (empty(trim($password))) {
                return false;
            }
            return \password_verify($password, $this->password);
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
            if ($this->isClosed()) {
                return false;
            }
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
         * @return bool|\Idno\Common\false|\Idno\Common\true|\Idno\Core\false|\Idno\Core\MongoID|null
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
                $avatar_error = isset($_FILES['avatar']['error']) ? intval($_FILES['avatar']['error']) : UPLOAD_ERR_OK;
                if ($avatar_error !== UPLOAD_ERR_OK && $avatar_error !== UPLOAD_ERR_NO_FILE) {
                    // PHP refused the picture (too large, cut short): the rest of the profile is
                    // saved, and the member is told why the picture did not change
                    \Idno\Core\Idno::site()->session()->addErrorMessage(
                        \Idno\Core\Upload::errorMessage($avatar_error, (string) ($_FILES['avatar']['name'] ?? ''))
                        . ' ' . \Idno\Core\Idno::site()->language()->_('Your picture was not changed.')
                    );
                } else if ($avatar_error === UPLOAD_ERR_OK && !empty($_FILES['avatar']['tmp_name']) && !in_array($_FILES['avatar']['type'], array('image/png', 'image/jpg', 'image/jpeg', 'image/gif'))) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Your picture was not changed: a profile picture can be JPEG, PNG or GIF.'));
                }
                if ($avatar_error === UPLOAD_ERR_OK && !empty($_FILES['avatar']['tmp_name']) && in_array($_FILES['avatar']['type'], array('image/png', 'image/jpg', 'image/jpeg', 'image/gif'))) {
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
            return parent::save($overrideAccess);
        }

        /// How many of an account's records are looked up at a time while it is removed
        const CONTENT_BATCH = 100;

        /// The event the queue worker runs to remove a closed account: its data is the `uuid`
        const REMOVE_EVENT = 'account/remove';

        /// The collections whose records an account owns
        const CONTENT_COLLECTIONS = ['entities', 'reader'];

        /// What the last deleteContent() could not remove: 'classes' (class => count), 'at' and 'reason'; empty when it left nothing
        private $removalReport = [];

        /**
         * Is this account closed? A closed account is being deleted, or its deletion stopped
         * before it was done. THE one question that decides whether an account may sign in
         * or act: Session refuses a closed account wherever it refuses a banned one (a
         * password, a browser session, a signed request, an access token, a queued event run
         * as it), it has no API key and is sent no password reset, it does not count as an
         * administrator, and its profile and posts are shown to administrators only.
         *
         * @return bool
         */
        function isClosed()
        {
            return !empty($this->account_closed);
        }

        /**
         * Is the account with this UUID closed? Asked of the set of closed accounts
         * (\Idno\Core\AccountRegistry), which costs no lookup of the account.
         *
         * @param  mixed $uuid
         * @return bool
         */
        static function uuidIsClosed($uuid)
        {
            return \Idno\Core\AccountRegistry::isClosed($uuid);
        }

        /**
         * Close this account: the first step of deleting it, made before anything is
         * removed, in one save. The account is marked, its password becomes the hash of a
         * value nobody knows (a login to it costs what a wrong password costs and gets the
         * same answer), and its API key, password recovery code and IndieAuth codes and
         * tokens are removed. Its sessions end at their next request, because Session
         * refuses it. The mark that keeps its posts out of every listing
         * (AccountRegistry::markClosed()) is saved first, and in one transaction with the
         * account where the database has them: a failure of either closes nothing. Then
         * the event account/closed is triggered once (the ActivityPub plugin tells followers'
         * servers that the actor is gone); a listener that fails does not undo the closing.
         *
         * Closing a closed account changes nothing and is true. The username and the email
         * address stay taken until the account is deleted.
         *
         * @return bool Is the account closed?
         */
        function close()
        {
            $uuid = (string) $this->getUUID();
            $logging = \Idno\Core\Idno::site()->logging();
            if ($uuid === '' || empty($this->_id)) {
                return false;
            }
            if ($this->isClosed()) {
                // The mark is asserted again: whatever happened to it, the posts are not listed
                try {
                    \Idno\Core\AccountRegistry::markClosed($uuid);
                } catch (\Throwable $e) {
                    $logging->error('The mark of a closed account could not be put back: ' . get_class($e));
                }

                return true;
            }
            $fields = ['account_closed', 'password', 'apikey', 'indieauth_codes', 'indieauth_tokens', 'password_recovery_code'];
            $previous = [];
            foreach ($fields as $field) {
                $previous[$field] = $this->$field;
            }
            try {
                $closed = \Idno\Core\Idno::site()->db()->transaction(
                    function () use ($uuid) {
                        \Idno\Core\AccountRegistry::markClosed($uuid);
                        $this->account_closed = time();
                        $this->password = \password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
                        $this->apikey = '';
                        $this->indieauth_codes = [];
                        $this->indieauth_tokens = [];
                        $this->clearPasswordRecoveryCode();

                        return $this->save(true) ? true : false;
                    }
                );
            } catch (\Throwable $e) {
                $logging->error('An account could not be closed: ' . get_class($e));
                $closed = false;
            }
            if (!$closed) {
                foreach ($previous as $field => $value) {
                    $this->$field = $value;
                }
                try {
                    \Idno\Core\AccountRegistry::unmarkClosed($uuid); // (where the two saves were not one transaction)
                } catch (\Throwable $e) {
                    $logging->error('The mark of an account that was not closed could not be removed: ' . get_class($e));
                }

                return false;
            }
            \Idno\Core\AccountRegistry::forget();
            try {
                \Idno\Core\Idno::site()->events()->triggerEvent('account/closed', ['object' => $this]);
            } catch (\Throwable $e) {
                $logging->error('A listener of account/closed failed: ' . get_class($e));
            }

            return true;
        }

        /**
         * A closed account's profile is for administrators alone.
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
         * Delete this account and everything it owns. A job in two stages: the account is
         * CLOSED first (close()), then its content is removed entity by entity
         * (deleteContent()), and the account record last, only when nothing it owns is left.
         *
         * With the asynchronous queue the content is removed by the queue worker (the event
         * account/remove, run as nobody, which queues itself again while it makes progress):
         * the account is closed here and false is returned. Otherwise the job runs here for
         * a bounded time (removalSeconds()) and what is left is done after the response
         * (\Idno\Core\AfterResponse); the Users page's "Continue deleting" calls this again
         * when that did not finish. From the command line (a console command, a test) the
         * whole job is done here. Every step can be repeated.
         *
         * What is removed is not announced one post at a time (AccountContent::whileRemoving());
         * followers' servers are told once, when the account is closed. Whether the requester
         * may delete the account is for the page to ask (canBeDemotedOrDeletedBy()).
         *
         * @return bool Is the account gone?
         */
        function delete()
        {
            $site = \Idno\Core\Idno::site();
            $session = $site->session();
            $lang = $site->language();
            if (!$this->isClosed() && \Idno\Core\AccountRegistry::closedIsFull()) {
                $session->addErrorMessage($lang->_("Too many accounts are being deleted at once. Wait until some of them are gone, then try again."));

                return false;
            }
            if (!$this->close()) {
                $session->addErrorMessage($lang->esc_("%s could not be closed, so nothing was deleted. Please try again.", [$this->getTitle()]));

                return false;
            }
            if ($site->queue() instanceof \Idno\Core\AsynchronousQueue) {
                if ($site->queue()->enqueue('default', self::REMOVE_EVENT, ['uuid' => $this->getUUID()], false) === false) {
                    $session->addErrorMessage($lang->esc_("%s is closed, but removing the account could not be started. Delete it again to carry on.", [$this->getTitle()]));
                } else {
                    $session->addMessage($lang->esc_("%s is closed and is being deleted: its content is removed in the background, and the account when nothing of it is left.", [$this->getTitle()]));
                }

                return false;
            }
            if (PHP_SAPI === 'cli') {
                do {
                    $outcome = $this->removeAccount();
                } while ($outcome === 'time');
            } else {
                $outcome = $this->removeAccount(self::CONTENT_BATCH, self::removalSeconds());
            }
            if ($outcome === 'removed') {
                return true;
            }
            if ($outcome === 'time') {
                $uuid = (string) $this->getUUID();
                \Idno\Core\AfterResponse::later(
                    static function () use ($uuid) {
                        do {
                            $user = User::getByUUID($uuid, false);
                            // (each run that says 'time' has removed something, so this ends)
                        } while ($user instanceof User && $user->isClosed() && $user->removeAccount(self::CONTENT_BATCH, 25.0) === 'time');
                    }
                );
                $session->addMessage($lang->esc_("%s is closed and is being deleted: the rest of its content is being removed now.", [$this->getTitle()]));
            } else if ($outcome === 'busy') {
                $session->addMessage($lang->esc_("%s is being deleted by another job already.", [$this->getTitle()]));
            } else {
                $session->addErrorMessage($lang->esc_("%s is closed, but something that belongs to it could not be deleted (a plugin may have refused). The list of users says what is left. Delete it again to try again.", [$this->getTitle()]));
            }

            return false;
        }

        /**
         * How long one run of the removal works for in a web request or a queue worker: the
         * setting account_removal_seconds (config.ini only), kept between 0 and 60% of
         * PHP's time limit (an hour where there is none); null when it is not set, for
         * deleteContent()'s default.
         *
         * @return float|null
         */
        static function removalSeconds()
        {
            $setting = \Idno\Core\Idno::site()->config()->account_removal_seconds;
            if (!is_numeric($setting) || !is_finite((float) $setting)) {
                return null;
            }
            $limit = (int) ini_get('max_execution_time');

            return max(0.0, min((float) $setting, $limit > 0 ? $limit * 0.6 : 3600.0));
        }

        /**
         * The job of deleting a closed account, as far as it gets in $seconds: its content
         * (deleteContent()) and, when nothing is left, the account record, quietly
         * (AccountContent::whileRemoving()). One job runs at a time: it holds a claim on the
         * stored account (account_removing_until, account_removing_by) for five minutes,
         * and a claim that is stale (its job was killed) is taken over.
         *
         * When the account is gone its username is reserved and it leaves the set of closed
         * accounts (\Idno\Core\AccountRegistry). When it is not, what remains and why is
         * stored on it (account_removal), for the Users page to say.
         *
         * @param  int        $batch
         * @param  float|null $seconds see deleteContent()
         * @return string 'removed' (the account is gone), 'time' (some of it was removed and
         *                there is more: call again), 'refused' (something remains that could
         *                not be removed, and this call removed nothing: stop), 'busy'
         *                (another job holds the claim) or 'open' (the account is not closed:
         *                nothing is removed from an account that is in use)
         */
        function removeAccount($batch = self::CONTENT_BATCH, $seconds = null)
        {
            if (!$this->isClosed()) {
                return 'open';
            }
            $uuid = (string) $this->getUUID();
            $handle = (string) $this->handle;
            \Idno\Core\AccountRegistry::markClosed($uuid); // (asserted again by every run)

            $token = bin2hex(random_bytes(8));
            $stored = static::getByUUID($uuid, false);
            if ($stored instanceof User && (int) $stored->account_removing_until > time() && (string) $stored->account_removing_by !== '') {
                return 'busy';
            }
            $this->account_removing_until = time() + 300;
            $this->account_removing_by = $token;
            $this->save(true);
            $stored = static::getByUUID($uuid, false);
            if (!$stored instanceof User || (string) $stored->account_removing_by !== $token) {
                return 'busy'; // another job took the claim in the same moment
            }

            $removed = false;
            try {
                $outcome = \Idno\Core\AccountContent::whileRemoving(
                    function () use ($batch, $seconds) {
                        $result = $this->deleteContent($batch, $seconds);
                        if ($result !== 'done') {
                            return $result;
                        }

                        return parent::delete() ? 'removed' : 'refused';
                    }
                );
                $removed = $outcome === 'removed';

                return $outcome;
            } finally {
                $logging = \Idno\Core\Idno::site()->logging();
                if ($removed) {
                    // Each on its own: a failure of one does not lose the other, or turn a deletion that worked into an error
                    if ($handle !== '') {
                        try {
                            \Idno\Core\AccountRegistry::reserveHandle($handle, $uuid); // (first: a process that stops between the two leaves the username kept back)
                        } catch (\Throwable $e) {
                            $logging->warning('The username of a deleted account could not be reserved: ' . get_class($e));
                        }
                    }
                    try {
                        \Idno\Core\AccountRegistry::unmarkClosed($uuid);
                    } catch (\Throwable $e) {
                        $logging->warning('The mark of a deleted account could not be removed: ' . get_class($e));
                    }
                } else {
                    $this->account_removing_until = 0;
                    $this->account_removing_by = '';
                    $this->account_removal = $this->removalReport ?: false;
                    $this->save(true);
                }
            }
        }

        /**
         * The avatar is a stored file of the account, and goes with it.
         */
        function deleteData()
        {
            $id = strtok((string) $this->icon, '/');
            if (is_string($id) && preg_match('/^[A-Za-z0-9]{8,64}$/', $id)) {
                try {
                    if ($file = \Idno\Entities\File::getByID($id)) {
                        $file->delete();
                    }
                } catch (\Throwable $e) {
                    \Idno\Core\Idno::site()->logging()->warning('The avatar of a deleted account could not be deleted: ' . get_class($e));
                }
            }

            return true;
        }

        /**
         * Delete what this account owns, one entity at a time, until it is all gone or the
         * time is up. What it owns is every RAW RECORD of the entity collections whose owner
         * is the account (read as records, so one whose class is gone or cannot be built is
         * found too), except what belongs to the site (\Idno\Core\AccountContent). An
         * entity that can be built is deleted through its own delete(), so its hooks run and
         * its files go; one that cannot is removed as a record. PROGRESS IS RECORDS GONE:
         * after each delete the record is read again by its id, and one that is still there
         * (a listener refused, a delete() that left it) is refused and not tried again in
         * this call. The time is checked after each entity, and at least one is done for
         * each call.
         *
         * 'done' is said only when a COUNT of what the account still owns equals what was
         * walked past and kept on purpose. A database that cannot be read is not "nothing
         * left": it ends the call.
         *
         * What was refused is logged, one line for each class with the count and a few ids
         * (never content), and kept for the Users page.
         *
         * @param  int        $batch   How many records to look up at a time
         * @param  float|null $seconds The time to work for (null: 60% of PHP's time limit, or half a minute where there is none)
         * @return string 'done'; 'time' (records were removed and there is more, or the time
         *                is up: call again); 'refused' (records remain that cannot be removed,
         *                or the database could not be read, and nothing was removed: stop)
         */
        function deleteContent($batch = self::CONTENT_BATCH, $seconds = null)
        {
            $batch = max(1, (int) $batch);
            $limit = (int) ini_get('max_execution_time');
            $seconds = $seconds ?? ($limit > 0 ? $limit * 0.6 : 30.0);
            $deadline = microtime(true) + $seconds;
            $uuid = (string) $this->getUUID();
            $db = \Idno\Core\Idno::site()->db();
            $logging = \Idno\Core\Idno::site()->logging();
            $before = $db->getIgnoreAccess();
            $abort = ignore_user_abort(true); // the entity the request is in the middle of is finished, with its files
            $db->setIgnoreAccess(true);
            $this->removalReport = [];
            try {
                $kept = 0;
                $removed = 0;
                $refused = [];
                $unreadable = false;
                $timeUp = false;
                $owned = -1;
                try {
                    foreach (self::CONTENT_COLLECTIONS as $collection) {
                        $offset = 0; // the records that stay where they are: the next batch begins after them
                        while (!$timeUp && ($records = $db->getRecords([], ['owner' => $uuid], $batch, $offset, $collection))) {
                            foreach ($records as $record) {
                                if ($removed > 0 && microtime(true) >= $deadline) {
                                    $timeUp = true;
                                    break;
                                }
                                $id = (string) ($record['_id'] ?? '');
                                if (($record['uuid'] ?? '') === $uuid || \Idno\Core\AccountContent::keeps($record)) {
                                    $kept++;
                                    $offset++;
                                    continue;
                                }
                                $this->removeOwnedRecord($record, $collection);
                                if ($id !== '' && !$db->getRecord($id, $collection)) {
                                    $removed++;
                                } else {
                                    $offset++;
                                    $class = (string) ($record['entity_subtype'] ?? '');
                                    $refused[$class === '' ? '(no class)' : $class][] = $id;
                                }
                            }
                        }
                    }
                    $owned = 0;
                    foreach (self::CONTENT_COLLECTIONS as $collection) {
                        $count = $db->countRecords(['owner' => $uuid], $collection);
                        if (!is_int($count)) {
                            throw new \Idno\Exceptions\TechnicalException('The records of the ' . $collection . ' collection could not be counted.');
                        }
                        $owned += $count;
                    }
                } catch (\Throwable $e) {
                    $unreadable = true;
                    $owned = -1;
                    $logging->error('Account removal could not read what the account owns: ' . get_class($e));
                }
                if ($refused) {
                    $this->removalReport = ['classes' => array_map('count', $refused), 'at' => time(), 'reason' => 'refused'];
                    foreach ($refused as $class => $ids) {
                        $ids = array_map(
                            function ($id) {
                                return preg_replace('/[^A-Za-z0-9-]/', '', (string) $id);
                            }, array_slice($ids, 0, 3)
                        );
                        $logging->warning('Account removal: ' . count($refused[$class]) . ' record(s) of ' . preg_replace('/[\x00-\x1f\x7f]+/', ' ', substr($class, 0, 200)) . ' could not be removed (ids ' . implode(', ', $ids) . '): a plugin\'s listener may have refused, or the class\'s delete() leaves the record');
                    }
                } else if ($unreadable) {
                    $this->removalReport = ['classes' => [], 'at' => time(), 'reason' => 'unreadable'];
                }
                if ($timeUp || ($removed > 0 && ($refused || $unreadable))) {
                    return 'time';
                }
                if ($refused || $unreadable) {
                    return 'refused';
                }
                if ($owned === $kept) {
                    return 'done';
                }
                if ($removed > 0) {
                    return 'time';
                }
                $this->removalReport = ['classes' => [], 'at' => time(), 'reason' => 'count'];

                return 'refused';
            } finally {
                $db->setIgnoreAccess($before);
                ignore_user_abort((bool) $abort);
            }
        }

        /**
         * Removes one record the account owns. An entity that can be built is deleted
         * through its own delete(); a record that cannot is removed as a record, with the
         * files its document names. Whether it is gone is for the caller to read.
         *
         * @param array  $record
         * @param string $collection
         */
        private function removeOwnedRecord(array $record, $collection)
        {
            $db = \Idno\Core\Idno::site()->db();
            $logging = \Idno\Core\Idno::site()->logging();
            $entity = false;
            if ($collection === 'entities') {
                try {
                    $entity = $db->rowToEntity($record);
                } catch (\Throwable $e) {
                    $logging->warning('A record of a deleted account could not be loaded as an entity and is removed as a record: ' . get_class($e));
                }
            }
            if ($entity instanceof Entity) {
                try {
                    $entity->delete();
                } catch (\Throwable $e) {
                    $logging->error('Deleting an entity of an account failed: ' . get_class($e));
                }

                return;
            }
            if (!$db->deleteRecord((string) ($record['_id'] ?? ''), $collection)) {
                return;
            }
            foreach ((array) ($record['attachments'] ?? []) as $attachment) {
                $id = is_array($attachment) ? (string) ($attachment['_id'] ?? '') : '';
                try {
                    if ($id !== '' && ($file = \Idno\Entities\File::getByID($id))) {
                        $file->delete();
                    }
                } catch (\Throwable $e) {
                    $logging->warning('A file of a deleted account could not be deleted: ' . get_class($e));
                }
            }
        }

        public function jsonSerialize(): mixed
        {
            $data          = parent::jsonSerialize();

            unset($data['updated']);
            unset($data['published']);
            unset($data['banned'], $data['ban_reason'], $data['banned_by'], $data['ban_hides_profile']);
            unset($data['account_closed'], $data['account_removing_until'], $data['account_removing_by'], $data['account_removal']);
            // The account email is private: it is never part of what is drawn for anybody
            unset($data['email']);

            $data['image'] = array('url' => $this->getIcon());
            if (!empty($this->profile['url'])) {
                $data['sameAs'] = $this->profile['url'];
            }

            return $data;
        }

        /**
         * The member as a schema.org Person, which is printed in the head of their profile and
         * of their posts for every visitor. The account email is private and is never part of
         * it (nor of jsonSerialize()): an address the member wants to show is one of the links
         * of their profile, which are in sameAs.
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
