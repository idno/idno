<?php

    /**
     * File upload callback
     */

namespace Idno\Pages\File {

    class Upload extends \Idno\Common\Page
    {

        function getContent()
        {
            // Not accessible via GET
        }

        function post()
        {
            // A member who may not write to the site stores no file
            if (\Idno\Core\Idno::site()->session()->isLoggedOn() && \Idno\Core\Idno::site()->canWrite()) {
                if (!empty($_FILES['file']['tmp_name'])) {
                    if (!\Idno\Core\Idno::site()->events()->triggerEvent("file/upload", [], true)) {
                        exit;
                    }
                    if ($file = \Idno\Entities\File::createFromFile($_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['type'], true, true)) {
                        echo json_encode(\Idno\Core\Idno::site()->config()->url . 'file/' . $file->file['_id']);
                    }
                }
            }
        }

    }

}

