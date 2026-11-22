<?php

namespace Idno\Pages\Admin {

    use Idno\Common\Page;
    use Idno\Core\AfterResponse;
    use Idno\Core\Idno;
    use Idno\Portability\PortabilityJobs;
    use Idno\Portability\WordPressImporter;

    class Import extends Page
    {

        function getContent()
        {

            $this->adminGatekeeper();

            // Leftovers of an import that was killed are removed when the page is loaded
            Idno::container()->get(PortabilityJobs::class)->sweep();

            $t = \Idno\Core\Idno::site()->template();
            $t->__(
                array(
                'title' => \Idno\Core\Idno::site()->language()->_('Import data'),
                'body'  => $t->draw('admin/import'),
                )
            )->drawPage();

        }

        function postContent()
        {
            $this->adminGatekeeper();

            $session  = Idno::site()->session();
            $language = Idno::site()->language();
            $back     = Idno::site()->config()->getDisplayURL() . 'admin/import/';
            $jobs     = Idno::container()->get(PortabilityJobs::class);

            // Nothing starts unless there is a file to import and a source to read it as: the importers are
            // never called without one.
            $type = strtolower(trim((string)$this->getInput('import_type')));
            if (!in_array($type, PortabilityJobs::IMPORT_TYPES, true)) {
                $session->addErrorMessage($language->_('Choose where your file comes from: WordPress or Blogger.'));
                $this->forward($back);
                return;
            }

            $file  = $_FILES['import'] ?? null;
            $error = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                $session->addErrorMessage($language->_('You need to upload an import file to continue.'));
                $this->forward($back);
                return;
            }
            // PHP refused the upload for its size (upload_max_filesize, the form's limit)
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $session->addErrorMessage($language->_('That file is larger than this server accepts for uploads. Export a shorter period, and import the files one after another.'));
                $this->forward($back);
                return;
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || !is_readable($tmp) || !filesize($tmp)) {
                $session->addErrorMessage($language->_("We couldn't open the file you uploaded. Please try again."));
                $this->forward($back);
                return;
            }
            if (filesize($tmp) > WordPressImporter::MAX_BYTES) {
                $session->addErrorMessage(sprintf($language->_('That file is larger than %d MB, which is the most an import reads. Export a shorter period, and import the files one after another.'), WordPressImporter::MAX_BYTES / 1048576));
                $this->forward($back);
                return;
            }

            // The upload waits in the file store (where only administrators can fetch it) until the import has run.
            try {
                $upload = $jobs->stageUpload($tmp, (string)($file['name'] ?? 'import'));
            } catch (\Throwable $e) {
                Idno::site()->logging()->error('An import upload could not be kept: ' . get_class($e));
                $session->addErrorMessage($language->_("We couldn't keep the file you uploaded. Please try again."));
                $this->forward($back);
                return;
            }

            $user = (string)$session->currentUserUUID();

            // With the asynchronous queue the worker does the import; without it, it is done after this answer.
            $queue = Idno::site()->queue();
            if ($queue->isAsynchronous()) {
                if ($queue->enqueue('default', PortabilityJobs::IMPORT_EVENT, ['user' => $user, 'file' => $upload, 'type' => $type], $user) === false) {
                    $jobs->discardUpload($upload);
                    $session->addErrorMessage($language->_("We couldn't start your import. Please try again."));
                } else {
                    $session->addMessage($language->_("Your import has started. We'll email you when it's done."));
                }
                $this->forward($back);
                return;
            }

            // The message is added before the answer: the session is closed with it.
            $session->addMessage($language->_("Your import has started. We'll email you when it's done."));
            AfterResponse::run($this, $back, static function () use ($jobs, $user, $upload, $type): void {
                $jobs->runImport($user, $upload, $type);
            });
        }

    }

}
