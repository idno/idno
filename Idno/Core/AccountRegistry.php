<?php

namespace Idno\Core {

    use Idno\Entities\ClosedAccount;
    use Idno\Entities\ReservedHandle;

    /**
     * What the site keeps about accounts that are being deleted or are gone:
     *
     *  - the set of CLOSED accounts: one \Idno\Entities\ClosedAccount record for each account
     *    that is closed (User::close()) and not yet deleted. Both database back ends leave
     *    what these accounts own out of every listing and count for everyone but an
     *    administrator (DataConcierge::closedAccountsExclusion()), and Entity::canRead() asks
     *    the set. It is read once for each process that lists or counts anything (one
     *    statement by class, no join), and again after a few seconds in a process that
     *    lives long.
     *  - the usernames of accounts that were deleted, RESERVED: one
     *    \Idno\Entities\ReservedHandle record each, looked up only when an account is made or
     *    renamed and on the Users page. A reserved username is taken until an administrator
     *    releases it.
     */
    class AccountRegistry
    {

        /// The most accounts that can be closed at once: each adds a value to the exclusion every visitor's listing carries
        const MAX_CLOSED = 500;

        /// How long a process trusts the set it read, in seconds (a queue worker lives for hours)
        const TRUST_SECONDS = 5;

        /// The set as last read, and when
        private static $closed = null;
        private static $read = 0;

        /**
         * The UUIDs of the accounts that are closed.
         *
         * @return string[]
         */
        static function closed()
        {
            if (self::$closed === null || (time() - self::$read) > self::TRUST_SECONDS) {
                $uuids = [];
                foreach (self::marks() as $mark) {
                    if (!empty($mark['account_uuid']) && is_string($mark['account_uuid'])) {
                        $uuids[$mark['account_uuid']] = true;
                    }
                }
                self::$closed = array_map('strval', array_keys($uuids));
                self::$read = time();
            }

            return self::$closed;
        }

        /**
         * Is the account with this UUID closed?
         *
         * @param  mixed $uuid
         * @return bool
         */
        static function isClosed($uuid)
        {
            return is_string($uuid) && $uuid !== '' && in_array($uuid, self::closed(), true);
        }

        /**
         * Read the set again the next time it is asked for.
         */
        static function forget()
        {
            self::$closed = null;
        }

        /**
         * The raw records of the marks (whoever is asking: records, not entities).
         *
         * @return array[]
         */
        private static function marks()
        {
            $records = Idno::site()->db()->getRecords([], ['entity_subtype' => ClosedAccount::class], self::MAX_CLOSED * 2, 0);

            return is_array($records) ? $records : [];
        }

        /**
         * Can no more accounts be closed? A full set is cleaned first, so marks of accounts
         * that are gone never keep it full.
         *
         * @return bool
         */
        static function closedIsFull()
        {
            self::forget();
            if (count(self::closed()) < self::MAX_CLOSED) {
                return false;
            }
            self::pruneDead();

            return count(self::closed()) >= self::MAX_CLOSED;
        }

        /**
         * Removes the marks of accounts that have no record any more (an account that was
         * deleted by a process that stopped before it took its mark away).
         */
        static function pruneDead()
        {
            $db = Idno::site()->db();
            foreach (self::marks() as $mark) {
                $uuid = (string) ($mark['account_uuid'] ?? '');
                if ($uuid === '' || !$db->getRecordByUUID($uuid)) {
                    $db->deleteRecord((string) $mark['_id']);
                }
            }
            self::forget();
        }

        /**
         * Adds the account to the set. The set is what keeps a closed account's posts out of
         * every listing, so a mark that cannot be saved is an exception, never ignored.
         *
         * @param string $uuid
         */
        static function markClosed($uuid)
        {
            self::forget();
            if (self::isClosed($uuid)) {
                return;
            }
            $mark = new ClosedAccount();
            $mark->account_uuid = (string) $uuid;
            if (!$mark->save(true)) {
                throw new \Idno\Exceptions\TechnicalException('The mark of a closed account could not be saved.');
            }
            self::forget();
        }

        /**
         * Takes the account out of the set.
         *
         * @param string $uuid
         */
        static function unmarkClosed($uuid)
        {
            $db = Idno::site()->db();
            foreach (self::marks() as $mark) {
                if ((string) ($mark['account_uuid'] ?? '') === (string) $uuid) {
                    $db->deleteRecord((string) $mark['_id']);
                }
            }
            self::forget();
        }

        /**
         * A username as usernames are compared: trimmed, lower case, accents and
         * compatibility forms folded, as the database's text comparison folds them.
         *
         * @param  string $handle
         * @return string
         */
        static function normaliseHandle($handle)
        {
            $handle = mb_strtolower(trim((string) $handle), 'UTF-8');
            if (class_exists(\Normalizer::class)) {
                $handle = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($handle, \Normalizer::FORM_KD));
            }

            return str_replace('ß', 'ss', $handle);
        }

        /**
         * Is this username kept back because the account that had it was deleted?
         *
         * @param  mixed $handle
         * @return bool
         */
        static function handleIsReserved($handle)
        {
            return is_string($handle) && $handle !== '' && ReservedHandle::forKey(self::normaliseHandle($handle)) !== false;
        }

        /**
         * Keeps the username of a deleted account back.
         *
         * @param string $handle
         * @param string $uuid   The account that had it
         */
        static function reserveHandle($handle, $uuid)
        {
            $key = self::normaliseHandle($handle);
            if ($key === '' || ReservedHandle::forKey($key) !== false) {
                return;
            }
            $reserved = new ReservedHandle();
            $reserved->handle = $key;
            $reserved->display = (string) $handle;
            $reserved->account_uuid = (string) $uuid;
            if (!$reserved->save(true)) {
                throw new \Idno\Exceptions\TechnicalException('The username of a deleted account could not be reserved.');
            }
        }

        /**
         * Gives a reserved username back.
         *
         * @param  string $handle
         * @return bool Whether it was reserved
         */
        static function releaseHandle($handle)
        {
            $reserved = is_string($handle) ? ReservedHandle::forKey(self::normaliseHandle($handle)) : false;
            if ($reserved === false) {
                return false;
            }

            return (bool) Idno::site()->db()->deleteRecord($reserved->getID());
        }

    }

}
