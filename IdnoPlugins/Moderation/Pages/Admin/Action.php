<?php

namespace IdnoPlugins\Moderation\Pages\Admin {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * An administrator acts on a case: POST /admin/moderation/case/<id>/action with
     * 'action' and the 'revision' of the case that the administrator was looking at.
     */
    class Action extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper();
            $this->forward(Idno::site()->config()->getDisplayURL() . 'admin/moderation/case/' . rawurlencode((string) ($this->arguments[0] ?? '')));
        }

        function postContent()
        {
            $this->adminGatekeeper();
            $session = Idno::site()->session();
            $admin = $session->currentUser();

            $case = ModerationCase::load((string) ($this->arguments[0] ?? ''));
            if (!$case || !Moderation::canHandle($admin, $case)) {
                $this->noContent();
            }

            $result = Moderation::act(
                $case, $admin, (string) $this->getInput('action'), [
                'revision' => $this->getInput('revision'),
                'reason' => (string) $this->getInput('ban_reason'),
                'remove_content' => !empty($this->getInput('remove_content')),
                'hide_all' => !empty($this->getInput('hide_all')),
                'notify' => !empty($this->getInput('notify')),
                'text' => (string) $this->getInput('note'),
                ]
            );

            if ($result['ok']) {
                $session->addMessage($result['message']);
            } else {
                $session->addErrorMessage($result['message']);
            }
            $this->forward($case->getAdminURL());
        }
    }

}
