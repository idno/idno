<?php

    /**
     * Access group representation
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    #[\Idno\Storage\Attribute\AsEntity('access_group')]
    class AccessGroup extends \Idno\Common\Entity
    {

        /**
         * On initial creation, make sure access groups have a members property
         *
         * @return mixed
         */
        function __construct()
        {
            if (\Idno\Core\Idno::site()->session()->currentUser()) {
                $this->read  = array(\Idno\Core\Idno::site()->session()->currentUser()->getUUID());
                $this->write = array(\Idno\Core\Idno::site()->session()->currentUser()->getUUID());
                $this->admin = array(\Idno\Core\Idno::site()->session()->currentUser()->getUUID());
            } else {
                $this->read  = [];
                $this->write = [];
                $this->admin = [];
            }

            return parent::__construct();
        }

        /**
         * Can the specified user (or the currently logged-in user) access
         * content in this access group?
         *
         * @param  string $user_id The user ID (optional)
         * @return true|false
         */
        function canRead($user_id = '')
        {
            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUser()->uuid;
            }
            if ($this->getOwnerID() == $user_id) {
                return true;
            }
            if ($this->isMember($user_id)) {
                return true;
            }
            if ($this->access == 'PUBLIC') {
                return true;
            }

            return false;
        }

        /**
         * Is the specified user (or the currently logged-in user) a member
         * of this access group?
         *
         * @param  type $user_id
         * @return type
         */
        function isMember($user_id = '', $access = 'read')
        {
            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUser()->uuid;
            }
            if (!empty($this->$access) && is_array($this->$access) && (array_search($user_id, $this->$access) !== false)) {
                return true;
            }

            return false;
        }

        /**
         * Can the specified user (or the currently logged-in user) publish
         * content to this access group?
         *
         * @param  string $user_id The user ID (optional)
         * @return true|false
         */
        function canPublish($user_id = '')
        {
            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUser()->uuid;
            }
            if ($this->getOwnerID() == $user_id) {
                return true;
            }
            if ($this->isMember($user_id, 'write')) {
                return true;
            }

            return false;
        }

        /**
         * Adds a specified user to the access group
         *
         * @param  string $user_id The user UUID
         * @return true|false
         */
        function addMember($user_id, $access = 'read')
        {
            if (($user = \Idno\Core\Idno::site()->db()->getObject($user_id)) && ($user instanceof User)) {
                if (!$this->isMember($user_id, $access)) {
                    array_push($this->$access, $user_id);
                    $this->$access = array_unique($this->$access);
                }

                return true;
            }
            return false;
        }

        /**
         * Can the specified user (or the currently logged-in user) administer
         * this access group?
         *
         * @param  string $user_id The user ID (optional)
         * @return true|false
         */
        function canEdit($user_id = '')
        {
            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUser()->uuid;
            }
            if ($this->getOwnerID() == $user_id) {
                return true;
            }
            if ($this->isMember($user_id, 'admin')) {
                return true;
            }

            return false;
        }

        /**
         * Removes a specified user from the access group
         *
         * @param  string $user_id The user UUID
         * @return true|false
         */
        function removeMember($user_id, $access = 'read')
        {
            $key = array_search($user_id, $this->$access);

            if (!empty($this->$access) && is_array($this->$access) && $key !== false) {
                array_splice($this->$access, $key, 1);

                return true;
            }

            return false;
        }

        /**
         * Get entities by access group.
         *
         * @param  mixed $access_group
         * @param  type  $search
         * @param  type  $fields
         * @param  type  $limit
         * @param  type  $offset
         * @return boolean
         */
        static function getByAccessGroup($access_group, $search = array(), $fields = array(), $limit = 10, $offset = 0)
        {
            if (!empty($access_group)) {

                $search = array_merge($search, ['access' => $access_group]);

                return \Idno\Core\Idno::site()->db()->getObjects('', $search, $fields, $limit, $offset, static::$retrieve_collection);
            }

            return false;
        }

    }

}
