<?php

namespace Idno\Entities {

    /**
     * What the site keeps about accounts that are being deleted or are gone (ClosedAccount,
     * ReservedHandle). For administrators alone, as an invitation is: its access is a value
     * no access group matches, it belongs to no member, it serialises to nothing and it is in
     * no listing or feed. Code that looks these records up has decided who may ask, and goes
     * through unrestricted().
     */
    abstract class AccountRecord extends \Idno\Common\Entity
    {

        const ACCESS = 'ACCOUNT_RECORD';

        function save($overrideAccess = true)
        {
            $this->access = self::ACCESS;
            // No member owns it: the administrator who deleted an account must not take it with them
            $this->owner = \Idno\Core\Idno::site()->config()->getURL() . 'accounts';
            $this->setPublishStatus('published');
            if (empty($this->slug)) {
                // An address of its own that says nothing (a handle as a slug would take the slug of the next post with that title)
                $this->slug = 'account-record-' . bin2hex(random_bytes(12));
            }

            return parent::save(true);
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

        function getTitle()
        {
            return '';
        }

        function getDescription()
        {
            return '';
        }

        function getActivityStreamsObjectType()
        {
            return false; // Not content: never federated, never in a feed
        }

        function getURL()
        {
            return \Idno\Core\Idno::site()->config()->getURL() . 'admin/users';
        }

        public function jsonSerialize(): mixed
        {
            return [];
        }

        /**
         * Runs $work with access ignored.
         *
         * @param  callable $work
         * @return mixed What $work returned
         */
        static function unrestricted(callable $work)
        {
            $db = \Idno\Core\Idno::site()->db();
            $before = $db->getIgnoreAccess();
            $db->setIgnoreAccess(true);
            try {
                return $work();
            } finally {
                $db->setIgnoreAccess($before);
            }
        }

    }

}
