<?php

namespace IdnoPlugins\Moderation\Pages\Admin {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * Moderation settings: /admin/moderation/settings
     */
    class Settings extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper();
            $admin = Idno::site()->session()->currentUser();
            $t = Idno::site()->template();
            $t->__(
                [
                'title' => Idno::site()->language()->_('Moderation settings'),
                'body' => $t->__(
                    [
                    'contact' => (string) Idno::site()->config()->moderation_contact_email,
                    'effective_contact' => Moderation::contactEmail(),
                    'notify' => Moderation::notifiesAdmins(),
                    'open_count' => Moderation::countOpen($admin),
                    'banned_count' => count(Moderation::bannedMembers()),
                    ]
                )->draw('moderation/admin/settings'),
                ]
            )->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper();
            $config = Idno::site()->config();
            $session = Idno::site()->session();
            $language = Idno::site()->language();

            $contact = trim((string) $this->getInput('moderation_contact_email'));
            if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $session->addErrorMessage($language->_("That doesn't look like an email address."));
            } else {
                $config->moderation_contact_email = $contact;
                $config->moderation_notify_admins = !empty($this->getInput('moderation_notify_admins')) ? 'true' : 'false';
                $config->save();
                $session->addMessage($language->_('Your moderation settings were saved.'));
            }
            $this->forward($config->getDisplayURL() . 'admin/moderation/settings');
        }
    }

}
