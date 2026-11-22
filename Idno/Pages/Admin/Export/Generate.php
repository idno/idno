<?php

namespace Idno\Pages\Admin\Export {

    use Idno\Common\Page;
    use Idno\Core\AfterResponse;
    use Idno\Core\Idno;
    use Idno\Portability\PortabilityJobs;

    class Generate extends Page
    {

        function getContent()
        {
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/export/');
        }

        function postContent()
        {

            $this->adminGatekeeper();

            $session = Idno::site()->session();
            $back    = Idno::site()->config()->getDisplayURL() . 'admin/export/';
            $jobs    = Idno::container()->get(PortabilityJobs::class);

            if (!Idno::container()->get(\Idno\Portability\ArchiveExporter::class)->zipAvailable()) {
                $session->addErrorMessage(Idno::site()->language()->_('The full archive needs the PHP zip extension, which this server does not have.'));
                $this->forward($back);
                return;
            }

            // One export at a time
            if (!$jobs->beginExport()) {
                $session->addErrorMessage(Idno::site()->language()->_('An export is already being made. Please wait for it to finish: we will email you.'));
                $this->forward($back);
                return;
            }

            $user = (string)$session->currentUserUUID();

            // With the asynchronous queue the worker makes the archive; without it, it is made after this answer.
            $queue = Idno::site()->queue();
            if ($queue->isAsynchronous()) {
                if ($queue->enqueue('default', PortabilityJobs::EXPORT_EVENT, ['user' => $user], $user) === false) {
                    $jobs->cancelExport();
                    $session->addErrorMessage(Idno::site()->language()->_("We couldn't start your export. Please try again."));
                }
                $this->forward($back);
                return;
            }

            AfterResponse::run($this, $back, static function () use ($jobs, $user): void {
                $jobs->runExport($user);
            });

        }

    }

}
