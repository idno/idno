<?php

    /**
     * File picker
     */

namespace Idno\Pages\File {

    use Idno\Core\Idno;

    class Picker extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->setAsset("exif-js", \Idno\Core\Idno::site()->config()->getStaticURL() . 'vendor/npm-asset/exif-js/exif.js', 'javascript');

            $template   = 'file/picker/image';
            $t          = \Idno\Core\Idno::site()->template();
            $t->title   = 'Image picker';
            $t->hidenav = true;
            $t->body    = $t->draw($template);
            $t->drawPage();
        }

        function post()
        {
            if (\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                if (!empty($_FILES['file']['tmp_name'])) {
                    if (!\Idno\Core\Idno::site()->events()->triggerEvent("file/upload", [], true)) {
                        exit;
                    }
                    if (\Idno\Entities\File::isImage($_FILES['file']['tmp_name'])) {
                        $return = false;
                        $file   = false;
                        if ($file = \Idno\Entities\File::createThumbnailFromFile($_FILES['file']['tmp_name'], $_FILES['file']['name'], 1024)) {

                            \Idno\Core\Idno::site()->logging()->debug("Creating new file from thumbnail as {$file}");

                            $return           = true;
                            $returnfile       = new \stdClass;
                            $returnfile->file = ['_id' => $file];
                            $file             = $returnfile;
                        } else if ($file = \Idno\Entities\File::createFromFile($_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['type'], true, true)) {
                            \Idno\Core\Idno::site()->logging()->debug("Creating new file");

                            $return = true;
                        }
                        if ($return) {
                            $t       = \Idno\Core\Idno::site()->template();
                            $t->file = $file;
                            echo $t->draw('file/picker/donejs');
                            exit;
                        }
                    } else {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("You can only upload images."));
                    }
                } else if (\Idno\Core\Upload::requestWasTooLarge()) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Upload::requestTooLargeMessage());
                } else {
                    $code = isset($_FILES['file']['error']) && is_scalar($_FILES['file']['error']) ? intval($_FILES['file']['error']) : UPLOAD_ERR_NO_FILE;
                    $message = \Idno\Core\Upload::errorMessage($code, isset($_FILES['file']['name']) && is_string($_FILES['file']['name']) ? $_FILES['file']['name'] : '');
                    \Idno\Core\Idno::site()->session()->addErrorMessage($message !== '' ? $message : \Idno\Core\Idno::site()->language()->_('No file arrived, so nothing was saved.'));
                }
                // Back to the picker, which shows why nothing was added
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'filepicker/?type=image');
            }
        }

    }

}

