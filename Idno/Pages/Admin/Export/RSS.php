<?php

namespace Idno\Pages\Admin\Export {

    use Idno\Common\Page;
    use Idno\Core\Idno;
    use Idno\Portability\FeedExporter;

    class RSS extends Page
    {

        function postContent()
        {

            $this->adminGatekeeper();

            set_time_limit(0);

            $hide_private = true;
            if ($private = $this->getInput('allposts')) {
                $hide_private = false;
            }

            $f = Idno::container()->get(FeedExporter::class)->stream($hide_private, null, false);

            if ($f) {
                $stats = fstat($f);

                header('Content-type: text/rss');
                header('Content-disposition: attachment; filename=export.rss');
                header('Content-Length: ' . $stats['size']);

                \Idno\Pages\File\View::endOutputBuffers(); // so that the document is sent as it is read, not held
                fpassthru($f);

                fclose($f);
            } else {
                \Idno\Core\Idno::site()->session()->addMessage(
                    \Idno\Core\Idno::site()->language()->_('There was a problem generating the export. Please try again later.'),
                    'alert-danger'
                );
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/');
            }
            exit;

        }

    }

}
