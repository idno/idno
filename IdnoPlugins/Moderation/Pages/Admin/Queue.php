<?php

namespace IdnoPlugins\Moderation\Pages\Admin {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * The moderation queue: /admin/moderation/?tab=unresolved|resolved|banned|log, and one
     * case at /admin/moderation/case/<id>. Administrators only.
     */
    class Queue extends \Idno\Common\Page
    {

        const PER_PAGE = 30;

        function getContent()
        {
            $this->adminGatekeeper();
            $admin = Idno::site()->session()->currentUser();
            $language = Idno::site()->language();

            $case = null;
            if (!empty($this->arguments[0])) {
                $case = ModerationCase::load((string) $this->arguments[0]);
                if (!$case || !Moderation::canHandle($admin, $case)) {
                    $this->noContent();
                }
            }

            $tab = (string) $this->getInput('tab');
            if ($case) {
                $tab = $case->isOpen() ? 'unresolved' : 'resolved';
            } else if (!in_array($tab, ['unresolved', 'resolved', 'banned', 'log'], true)) {
                $tab = 'unresolved';
            }

            $open = Moderation::cases(ModerationCase::OPEN, $admin);
            $banned = Moderation::bannedMembers();
            $offset = max(0, (int) $this->getInput('offset'));

            $vars = [
                'tab' => $tab,
                'case' => $case,
                'open_count' => count($open),
                'banned_count' => count($banned),
                'offset' => $offset,
                'per_page' => self::PER_PAGE,
                'admin' => $admin,
                'only_admin' => \Idno\Entities\User::countUnbannedAdmins() <= 1,
            ];
            switch ($tab) {
                case 'unresolved':
                    $vars['total'] = count($open);
                    $vars['cases'] = array_slice($open, $offset, self::PER_PAGE);
                    break;
                case 'resolved':
                    $resolved = Moderation::cases(ModerationCase::RESOLVED, $admin);
                    $vars['total'] = count($resolved);
                    $vars['cases'] = array_slice($resolved, $offset, self::PER_PAGE);
                    break;
                case 'banned':
                    $vars['total'] = count($banned);
                    $vars['banned'] = array_slice($banned, $offset, self::PER_PAGE);
                    break;
                case 'log':
                    $entries = array_values(
                        array_filter(
                            Moderation::logEntries([], 1000), function ($entry) use ($admin) {
                                // Nothing about a case this administrator must not see
                                return empty($entry->subject_uuid) || $entry->subject_uuid !== $admin->getUUID() || \Idno\Entities\User::countUnbannedAdmins() <= 1;
                            }
                        )
                    );
                    $vars['total'] = count($entries);
                    $vars['entries'] = array_slice($entries, $offset, self::PER_PAGE);
                    break;
            }
            if ($case) {
                $vars['reports'] = $case->getReports();
                $vars['history'] = array_values(
                    array_filter(
                        Moderation::casesAbout($case->subject_uuid), function ($other) use ($case) {
                            return $other->getID() !== $case->getID();
                        }
                    )
                );
                $vars['case_log'] = Moderation::logEntries(['case_id' => $case->getID()], 200);
                $vars['subject'] = $case->getSubject();
            }

            $t = Idno::site()->template();
            $t->__(['title' => $language->_('Moderation'), 'body' => $t->__($vars)->draw('moderation/admin/queue')])->drawPage();
        }
    }

}
