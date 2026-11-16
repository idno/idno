<?php

    /**
     * The tables and rows an upgrade from Idno 1.x left behind: the 1.x tables, renamed legacy_<name>,
     * which the admin may delete; the rows that could not be carried over; and 1.x tables an old Idno
     * process created again after the upgrade.
     */

namespace Idno\Pages\Admin {

    /**
     * /admin/legacytables. GET changes nothing. POST (admins only, with the form's CSRF token, which
     * Page::post() checks before postContent() runs) deletes the legacy tables when the field
     * `confirm` is exactly `delete` and the upgrade is complete; anything else deletes nothing and
     * says so. The work is Idno\Upgrade\LegacyTables (a container service), which fetches nothing on
     * a site whose database is not SQL, so the page shows an empty state there.
     */
    class LegacyTables extends \Idno\Common\Page
    {

        /** Rejects listed per page. */
        const PAGE_SIZE = 100;

        /** @var bool|null whether the admin menu shows the entry, decided once per request */
        private static $inMenu = null;

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only
            $tables = self::service();
            $offset = (int) $this->getInput('offset', 0);
            if ($offset < 0) {
                $offset = 0;
            }
            $count = $tables->rejectCount();
            if ($offset >= $count) {
                $offset = 0;
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->body = $t->__(
                [
                    'available' => $tables->available(),
                    'tables' => $tables->present(),
                    'complete' => $tables->isComplete(),
                    'restored' => $tables->oneXBesideCompletedUpgrade(),
                    'reappeared' => $tables->reappeared(),
                    'rejects' => $count > 0 ? $tables->rejects(self::PAGE_SIZE, $offset) : [],
                    'reject_count' => $count,
                    'offset' => $offset,
                    'page_size' => self::PAGE_SIZE,
                ]
            )->draw('admin/legacytables');
            $t->title = \Idno\Core\Idno::site()->language()->_('Legacy tables');
            $t->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only
            $language = \Idno\Core\Idno::site()->language();
            $session = \Idno\Core\Idno::site()->session();
            $tables = self::service();

            if ($this->getInput('confirm') !== 'delete') {
                $session->addErrorMessage($language->_('Nothing was deleted: type delete in the box to confirm.'));
            } else {
                if ($tables->oneXBesideCompletedUpgrade()) {
                    $session->addErrorMessage($language->_('Nothing was deleted.') . ' ' . \Idno\Upgrade\PublicMessage::oneXBesideCompletedUpgrade()->text);
                } else if ($tables->names() === []) {
                    $session->addMessage($language->_('There were no legacy tables to delete.'));
                } else if (!$tables->isComplete()) {
                    $session->addErrorMessage($language->_('Nothing was deleted: the upgrade from Idno 1.x is not complete.'));
                } else {
                    try {
                        $dropped = $tables->drop();
                        $session->addMessage($language->_('Deleted %d legacy tables: %s.', [$dropped, implode(', ', $tables->lastDropped())]));
                    } catch (\Throwable $e) {
                        \Idno\Core\Idno::site()->logging()->error('Could not delete the legacy tables: ' . \Idno\Upgrade\LegacyDataCopier::clean(get_class($e) . ': ' . $e->getMessage()));
                        $done = $tables->lastDropped() === [] ? '' : ' ' . $language->_('Deleted before the error: %s.', [implode(', ', $tables->lastDropped())]);
                        if ($e instanceof \Idno\Upgrade\LegacyTableReferenced) {
                            $session->addErrorMessage($language->_('A table outside the Idno 1.x tables refers to the legacy table %s (it has a foreign key into it), so that table cannot be deleted. Deal with the table that refers to it first (the server\'s error log names it), then delete the legacy tables again.', [$e->table]) . $done);
                        } else {
                            $session->addErrorMessage($language->_('Not every legacy table could be deleted. The error was logged; try again, or see the server\'s error log.') . $done);
                        }
                    }
                }
            }

            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/legacytables/');
        }

        /**
         * Whether the admin menu shows the entry: only to a logged-in admin, and only while there is
         * something to see (Idno\Upgrade\LegacyTables::worthShowing(): no statement when legacy tables
         * exist, else at most one, a `limit 1` probe of the rejects). Decided once per request; never
         * an exception (the menu must render).
         *
         * @return bool
         */
        static function showInMenu()
        {
            if (self::$inMenu !== null) {
                return self::$inMenu;
            }
            $session = \Idno\Core\Idno::site()->session();
            if (!$session->isLoggedIn() || !$session->currentUser()->isAdmin()) {
                return false;
            }
            try {
                self::$inMenu = self::service()->worthShowing();
            } catch (\Throwable $e) {
                \Idno\Core\Idno::site()->logging()->error('Could not tell whether there are legacy tables: ' . \Idno\Upgrade\LegacyDataCopier::clean(get_class($e) . ': ' . $e->getMessage()));
                self::$inMenu = false;
            }

            return self::$inMenu;
        }

        /**
         * @return \Idno\Upgrade\LegacyTables
         */
        private static function service()
        {
            return \Idno\Core\Idno::container()->get(\Idno\Upgrade\LegacyTables::class);
        }

    }

}
