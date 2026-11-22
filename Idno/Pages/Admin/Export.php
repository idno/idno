<?php

namespace Idno\Pages\Admin {

    use Idno\Common\Page;
    use Idno\Core\Idno;
    use Idno\Portability\PortabilityJobs;

    class Export extends Page
    {

        function getContent()
        {

            $this->adminGatekeeper();

            // An archive that is a week old is removed here: nothing else would
            $state = Idno::container()->get(PortabilityJobs::class)->exportState();

            $t = \Idno\Core\Idno::site()->template();
            $t->__(
                array(
                'title' => \Idno\Core\Idno::site()->language()->_('Export data'),
                'body'  => $t->__(
                    array(
                    'export_last_requested' => $state['created'],
                    'export_in_progress'    => $state['inProgress'],
                    'export_filename'       => $state['filename'],
                    'export_file_id'        => $state['fileId'],
                    'zip_available'         => Idno::container()->get(\Idno\Portability\ArchiveExporter::class)->zipAvailable(),
                    )
                )->draw('admin/export'),
                )
            )->drawPage();

        }

    }

}
