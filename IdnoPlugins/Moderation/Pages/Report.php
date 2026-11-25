<?php

namespace IdnoPlugins\Moderation\Pages {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * The report form: /moderation/report?type=post|comment|profile&id=...[&comment=...]
     *
     * For signed-in members using the site in a browser. A request made with an API key or
     * an access token is refused: a credential for publishing must not be enough to file
     * reports in someone's name.
     */
    class Report extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->memberGatekeeper();

            $target = Moderation::resolveTarget((string) $this->getInput('type'), (string) $this->getInput('id'), (string) $this->getInput('comment'));
            if (!$target) {
                $this->noContent();
            }
            $this->drawForm($target, '');
        }

        function postContent()
        {
            $this->memberGatekeeper();

            $type = (string) $this->getInput('type');
            $id = (string) $this->getInput('id');
            $comment = (string) $this->getInput('comment');
            $result = Moderation::fileReport(
                Idno::site()->session()->currentUser(), $type, $id, $comment,
                (string) $this->getInput('reason'), (string) $this->getInput('description')
            );

            if ($this->getInput('response') === 'json') {
                $this->setResponse($result['code']);
                http_response_code($result['code']);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
                exit;
            }

            if ($result['ok']) {
                Idno::site()->session()->addMessage($result['message'] . ' ' . Idno::site()->language()->_("A moderator will review it, and we'll email you when it has been looked at."));
                $this->forward($result['case']->getTargetURL() ?: Idno::site()->config()->getDisplayURL());
            }

            $target = Moderation::resolveTarget($type, $id, $comment);
            if (!$target) {
                Idno::site()->session()->addErrorMessage($result['message']);
                $this->forward(Idno::site()->config()->getDisplayURL());
            }
            $this->setResponse($result['code']);
            http_response_code($result['code']);
            $this->drawForm($target, $result['message']);
        }

        protected function memberGatekeeper()
        {
            $session = Idno::site()->session();
            if ($session->isAPIRequest() || !$session->isLoggedOn()) {
                $this->deniedContent();
            }
        }

        protected function drawForm(array $target, $error)
        {
            $language = Idno::site()->language();
            $own = $target['subject_uuid'] !== '' && $target['subject_uuid'] === Idno::site()->session()->currentUserUUID();
            if ($own && $error === '') {
                $error = $language->_("You can't report yourself or something you posted.");
            }
            $titles = [
                'post' => $language->_('Report this post'),
                'comment' => $language->_('Report this comment'),
                'profile' => $language->_('Report %s', [$target['snapshot']['author_name']]),
            ];
            $t = Idno::site()->template();
            $t->__(
                [
                'title' => $titles[$target['type']],
                'body' => $t->__(
                    [
                    'target' => $target,
                    'heading' => $titles[$target['type']],
                    'error' => $error,
                    'own' => $own,
                    'id' => (string) $this->getInput('id'),
                    'comment' => (string) $this->getInput('comment'),
                    'reason' => (string) $this->getInput('reason'),
                    'description' => Moderation::cleanDescription($this->getInput('description')),
                    ]
                )->draw('moderation/report'),
                ]
            )->drawPage();
        }
    }

}
