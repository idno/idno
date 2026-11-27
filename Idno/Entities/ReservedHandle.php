<?php

    /**
     * A username kept back because the account that had it was deleted
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Entities {

    /**
     * The handle of an account that was deleted, kept back so that nobody registers it (and is taken for the member who had it: an annotation is tied to its
     * author by the profile address, which is made of the handle, and an ActivityPub actor is its address) until an administrator releases it.
     *
     * A record of its own in the `entities` collection, found by its `handle` (the normalised handle, in the index `User` already declares for that field, so no
     * index is created): only when an account is created or renamed, and on the Users page. For administrators alone, as an invitation is: its access is a value no
     * group matches, it serialises to nothing and is in no listing, feed or export of posts.
     */
    #[\Idno\Storage\Attribute\AsEntity('reserved_handle')]
    #[\Idno\Storage\Attribute\IndexedField('handle')]
    class ReservedHandle extends \Idno\Common\Entity
    {
        const ACCESS = 'RESERVED_HANDLE';

        function save($overrideAccess = false)
        {
            $this->access = self::ACCESS;

            return parent::save($overrideAccess);
        }

        function canRead($user_id = '')
        {
            return static::isAdministrator($user_id);
        }

        function canEdit($user_id = '')
        {
            return static::isAdministrator($user_id);
        }

        protected static function isAdministrator($user_id = '')
        {
            $session = \Idno\Core\Idno::site()->session();
            if (empty($user_id)) {
                return $session->isLoggedOn() && $session->currentUser()->isAdmin();
            }
            $user = User::getByUUID($user_id);

            return $user && $user->isAdmin();
        }

        function getActivityStreamsObjectType()
        {
            return false;
        }

        function getTitle()
        {
            return (string) $this->display;
        }

        function getDescription()
        {
            return '';
        }

        function getURL()
        {
            return \Idno\Core\Idno::site()->config()->getURL() . 'admin/users';
        }

        function jsonSerialize(): mixed
        {
            return [];
        }

        /**
         * Runs $work with access ignored (what looks these records up is code that has decided who may ask).
         */
        static function unrestricted(callable $work)
        {
            $db     = \Idno\Core\Idno::site()->db();
            $before = $db->getIgnoreAccess();
            $db->setIgnoreAccess(true);
            try {
                return $work();
            } finally {
                $db->setIgnoreAccess($before);
            }
        }

        /**
         * @param string $key The normalised handle (AccountRegistry::normaliseHandle())
         */
        static function forKey($key)
        {
            if (!is_string($key) || $key === '') {
                return false;
            }

            return static::unrestricted(static fn () => static::getOne(['handle' => $key]));
        }

        /**
         * A page of them, newest first.
         *
         * @return ReservedHandle[]
         */
        static function page($limit, $offset)
        {
            return static::unrestricted(static fn () => static::get([], [], max(1, (int) $limit), max(0, (int) $offset))) ?: [];
        }

        static function total(): int
        {
            return (int) static::unrestricted(static fn () => static::countFromAll([]));
        }

        /**
         * Whether an account of this uuid was deleted and its handle is still reserved (the one lookup that is not by handle).
         */
        static function hasDeletedAccount($uuid)
        {
            return is_string($uuid) && $uuid !== '' && (bool) static::unrestricted(static fn () => static::getOne(['account_uuid' => $uuid]));
        }
    }

}
