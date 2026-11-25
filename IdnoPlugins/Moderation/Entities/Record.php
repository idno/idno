<?php

namespace IdnoPlugins\Moderation\Entities {

    /**
     * What the three kinds of moderation record (case, report, log entry) have in common:
     * only administrators can read them, whatever the route.
     *
     * Their access is a value that no access group matches, so the database gives them to
     * nobody but an administrator, and canRead() says the same for the pages that load an
     * entity by its id. The plugin's own lookups go through
     * \IdnoPlugins\Moderation\Moderation::unrestricted(). They belong to no member: deleting
     * a member's account must not delete the record of a report.
     */
    abstract class Record extends \Idno\Common\Entity
    {

        const ACCESS = 'MODERATION';

        function save($overrideAccess = true)
        {
            $this->access = self::ACCESS;
            $this->owner = \Idno\Core\Idno::site()->config()->getURL() . 'moderation';
            $this->setPublishStatus('published');
            if (empty($this->slug)) {
                // No words from a report may end up in an address
                $this->slug = 'moderation-' . bin2hex(random_bytes(12));
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
            $user = \Idno\Entities\User::getByUUID($user_id);

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
            return \Idno\Core\Idno::site()->config()->getURL() . 'admin/moderation/';
        }

        public function jsonSerialize(): mixed
        {
            return [];
        }

        /**
         * Find records of this class
         *
         * @return static[]
         */
        public static function find(array $search = [], $limit = 1000, $offset = 0)
        {
            return \IdnoPlugins\Moderation\Moderation::unrestricted(
                function () use ($search, $limit, $offset) {
                    $found = static::get($search, [], $limit, $offset);

                    return is_array($found) ? $found : [];
                }
            );
        }

        /**
         * @return static|null
         */
        public static function load($id)
        {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]+$/', $id)) {
                return null;
            }

            return \IdnoPlugins\Moderation\Moderation::unrestricted(
                function () use ($id) {
                    $found = static::getByID($id);

                    return $found instanceof static ? $found : null;
                }
            );
        }
    }

}
