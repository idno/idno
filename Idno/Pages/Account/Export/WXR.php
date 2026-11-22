<?php

namespace Idno\Pages\Account\Export {

    use Idno\Common\Page;
    use Idno\Core\Idno;
    use Idno\Portability\FeedExporter;

    class WXR extends Page
    {

        function postContent()
        {

            $this->gatekeeper();

            set_time_limit(0);

            $hide_private = true;
            if ($private = $this->getInput('allposts')) {
                $hide_private = false;
            }

            $f = Idno::container()->get(FeedExporter::class)->stream(
                $hide_private,
                (string)Idno::site()->session()->currentUserUUID(),
                true // wxr_mode
            );

            if ($f) {
                $stats = fstat($f);

                header('Content-type: application/xml');
                header('Content-disposition: attachment; filename=user_export.xml');
                header('Content-Length: ' . $stats['size']);

                \Idno\Pages\File\View::endOutputBuffers(); // so that the document is sent as it is read, not held
                fpassthru($f);

                fclose($f);
            } else {
                Idno::site()->session()->addMessage(
                    Idno::site()->language()->_('There was a problem generating your WXR export. Please try again later.'),
                    'alert-danger'
                );
                $this->forward(Idno::site()->config()->getDisplayURL() . 'account/export/');
            }
            exit;

        }

    }

}
