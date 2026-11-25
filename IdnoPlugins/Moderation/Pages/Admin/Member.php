<?php

namespace IdnoPlugins\Moderation\Pages\Admin {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * A member's moderation record, with ban and lift-ban: /admin/moderation/member/<id>
     */
    class Member extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper();
            $admin = Idno::site()->session()->currentUser();
            $member = $this->member();

            $cases = array_values(
                array_filter(
                    Moderation::casesAbout($member->getUUID()), function ($case) use ($admin) {
                        return Moderation::canHandle($admin, $case);
                    }
                )
            );
            $t = Idno::site()->template();
            $t->__(
                [
                'title' => Idno::site()->language()->_('Moderation'),
                'body' => $t->__(
                    [
                    'member' => $member,
                    'admin' => $admin,
                    'cases' => $cases,
                    'reporter_record' => Moderation::reporterRecord($member->getUUID()),
                    'log' => Moderation::logEntries(['subject_uuid' => $member->getUUID()], 200),
                    'open_count' => Moderation::countOpen($admin),
                    'banned_count' => count(Moderation::bannedMembers()),
                    ]
                )->draw('moderation/admin/member'),
                ]
            )->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper();
            $session = Idno::site()->session();
            $admin = $session->currentUser();
            $member = $this->member();

            switch ((string) $this->getInput('action')) {
                case 'ban':
                    $result = Moderation::banMember(
                        $member, $admin, [
                        'reason' => (string) $this->getInput('ban_reason'),
                        'hide_all' => !empty($this->getInput('hide_all')),
                        'notify' => !empty($this->getInput('notify')),
                        ]
                    );
                    break;
                case 'unban':
                    $result = Moderation::liftBan($member, $admin);
                    break;
                default:
                    $result = ['ok' => false, 'message' => Idno::site()->language()->_("That isn't something you can do here.")];
            }
            if ($result['ok']) {
                $session->addMessage($result['message']);
            } else {
                $session->addErrorMessage($result['message']);
            }
            $back = (string) $this->getInput('back') === 'banned'
                ? Idno::site()->config()->getDisplayURL() . 'admin/moderation/?tab=banned'
                : Idno::site()->config()->getDisplayURL() . 'admin/moderation/member/' . ((string) $member->getID());
            $this->forward($back);
        }

        /**
         * @return User
         */
        protected function member()
        {
            $member = User::getByID((string) ($this->arguments[0] ?? ''));
            if (!($member instanceof User) || $member instanceof \Idno\Entities\RemoteUser) {
                $this->noContent();
            }
            // An administrator does not get to look into reports about themself
            if ($member->getUUID() === Idno::site()->session()->currentUserUUID() && User::countUnbannedAdmins() > 1) {
                $this->noContent();
            }

            return User::getByUUID($member->getUUID(), false);
        }
    }

}
