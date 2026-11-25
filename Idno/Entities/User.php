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
            if (!empty($this->admin) && !$this->isBanned() && static::countUnbannedAdmins() <= 1) {
                return false;
            }

            return true;
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
         * How many administrators the site has who are not banned
         *
         * @return int
         */
        static function countUnbannedAdmins()
        {
            $count = 0;
            foreach (static::get(['admin' => true], [], 10000) ?: [] as $user) {
                if (!empty($user->admin) && !$user->isBanned()) {
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

        /**
         * Remove this user and all its objects
         *
         * @return bool
         */
        function delete()
        {

            // First, remove all owned objects
            while ($objects = Entity::get(array('owner' => $this->getUUID(), array(), 100))) {
                foreach ($objects as $object) {
                    $object->delete();
                }
            }

            return parent::delete();
        }

        public function jsonSerialize(): mixed
        {
            $data          = parent::jsonSerialize();

            unset($data['updated']);
            unset($data['published']);
            unset($data['banned'], $data['ban_reason'], $data['banned_by'], $data['ban_hides_profile']);

            $data['image'] = array('url' => $this->getIcon());
            if (!empty($this->profile['url'])) {
                $data['sameAs'] = $this->profile['url'];
            }

            return $data;
        }

        public function jsonLDSerialise(array $params = array())
        {
            $json = [
                '@context' => 'http://schema.org',
                '@type' => 'Person',
                'email' => $this->email,
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
