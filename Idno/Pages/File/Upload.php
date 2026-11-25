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
            if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                if (!empty($_FILES['file']['tmp_name'])) {
                    if (!\Idno\Core\Idno::site()->events()->triggerEvent("file/upload", [], true)) {
                        exit;
                    }
                    if ($file = \Idno\Entities\File::createFromFile($_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['type'], true, true)) {
                        echo json_encode(\Idno\Core\Idno::site()->config()->url . 'file/' . $file->file['_id']);
                    }
                } else {
                    // Nothing was stored: say why, with a status the caller can see, rather than an empty 200
                    if (\Idno\Core\Upload::requestWasTooLarge()) {
                        $message = \Idno\Core\Upload::requestTooLargeMessage();
                        http_response_code(413);
                    } else {
                        $code = isset($_FILES['file']['error']) && is_scalar($_FILES['file']['error']) ? intval($_FILES['file']['error']) : UPLOAD_ERR_NO_FILE;
                        $message = \Idno\Core\Upload::errorMessage($code, isset($_FILES['file']['name']) && is_string($_FILES['file']['name']) ? $_FILES['file']['name'] : '');
                        if ($message === '') {
                            $message = \Idno\Core\Idno::site()->language()->_('No file arrived, so nothing was saved.');
                        }
                        http_response_code(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) ? 413 : 400);
                    }
                    header('Content-Type: application/json');
                    echo json_encode(['error' => html_entity_decode($message, ENT_QUOTES, 'UTF-8')]);
                }
            }
        }

    }

}

