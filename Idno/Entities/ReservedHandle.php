<?php

namespace Idno\Entities {

    /**
     * The username of an account that was deleted, kept back so that nobody registers it and
     * is taken for the member who had it (an ActivityPub actor is its address, which is made
     * of the username; so is the address a comment is tied to its author by), until an
     * administrator releases it on the Users page.
     *
     * `handle` holds the username as usernames are compared
     * (\Idno\Core\AccountRegistry::normaliseHandle()), `display` as it was written, and
     * `account_uuid` the account that had it. Looked up only when an account is made or
     * renamed, and on the Users page.
     */
    class ReservedHandle extends AccountRecord
    {

        function getTitle()
        {
            return (string) $this->display;
        }

        /**
         * @param  string $key The normalised username
         * @return ReservedHandle|false
         */
        static function forKey($key)
        {
            if (!is_string($key) || $key === '') {
                return false;
            }

            return static::unrestricted(
                function () use ($key) {
                    return static::getOne(['handle' => $key]);
                }
            ) ?: false;
        }

        /**
         * A page of them, newest first.
         *
         * @return ReservedHandle[]
         */
        static function page($limit, $offset)
        {
            return static::unrestricted(
                function () use ($limit, $offset) {
                    return static::get([], [], max(1, (int) $limit), max(0, (int) $offset));
                }
            ) ?: [];
        }

        /**
         * @return int How many usernames are kept back
         */
        static function total()
        {
            return (int) \Idno\Core\Idno::site()->db()->countRecords(['entity_subtype' => get_called_class()]);
        }

    }

}
