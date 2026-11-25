<?php

namespace Idno\Pages\Admin {

    use Idno\Common\Page;
    use Idno\Core\Migration;

    class Import extends Page
    {

        function getContent()
        {

            $this->adminGatekeeper();

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

            define('IDNO_NOMENTION', true);
            if (!defined('KNOWN_NOMENTION')) {
                define('KNOWN_NOMENTION', IDNO_NOMENTION);
            }

            $import_type = $this->getInput('import_type');

            if (empty($_FILES['import'])) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("You need to upload an import file to continue."));
            } else if (!($xml = @file_get_contents($_FILES['import']['tmp_name']))) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("We couldn't open the file you uploaded. Please try again."));
            } else {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your import has started. We'll email you when it's done."));
            }

            session_write_close();
            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/import/', false);

            ignore_user_abort(true);    // This is dangerous, but we need export to continue

            header('Connection: close');
            header('Content-length: ' . (string)ob_get_length());

            @ob_end_flush();            // Return output to the browser
            @ob_end_clean();
            @flush();

            sleep(10);                  // Pause

            set_time_limit(0);          // Eliminate time limit - this could take a while

            $imported = false;
            try {
                switch (strtolower($import_type)) {

                    case 'blogger':
                        $imported = Migration::importBloggerXML($xml);
                        break;
                    case 'wordpress':
                        $imported = Migration::importWordPressXML($xml);
                        break;

                }
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->logging()->error($e->getMessage());
            }
            if ($imported) {
                \Idno\Core\Idno::site()->logging()->info("Completed import successfully, sending email to ". \Idno\Core\Idno::site()->session()->currentUser()->email);

                $mail = new \Idno\Core\Email();
                $mail->setBodyFromTemplate('admin/import');
                $mail->addTo(\Idno\Core\Idno::site()->session()->currentUser()->email);
                $mail->send();
            } else {
                \Idno\Core\Idno::site()->logging()->error("Import completed, but may not have been successful");

                $mail = new \Idno\Core\Email();
                $mail->setBodyFromTemplate('admin/import_failure');
                $mail->addTo(\Idno\Core\Idno::site()->session()->currentUser()->email);
                $mail->send();
            }

            exit; // prevent forward
        }

    }

}

