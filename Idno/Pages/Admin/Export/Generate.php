<?php

namespace Idno\Pages\Admin\Export {

    use Idno\Common\Page;
    use Idno\Core\Email;
    use Idno\Core\Migration;
    use Idno\Entities\File;

    class Generate extends Page
    {

        function getContent()
        {
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/export/');
        }

        /**
         * Store an export archive in the site's file storage, marked as an export. The mark
         * (`site_export` in the file's metadata) is what makes the file page serve it to
         * administrators only: see \Idno\Pages\File\View::isSiteExport().
         *
         * @param  string $path     Full local path to the archive
         * @param  string $filename The name to store it under
         * @return string|false The ID of the stored file
         */
        static function storeArchive($path, $filename)
        {
            $fs = \Idno\Core\Idno::site()->filesystem();
            if (empty($fs) || empty($filename) || !file_exists($path)) {
                return false;
            }

            $metadata = [
                'filename'    => $filename,
                'mime_type'   => 'application/octet-stream',
                'site_export' => true,
            ];

            return $fs->storeFile($path, $metadata, $metadata);
        }

        function postContent()
        {

            $this->adminGatekeeper();

            // Flag that a site export has been requested
            \Idno\Core\Idno::site()->config()->export_last_requested = time();
            \Idno\Core\Idno::site()->config()->export_in_progress    = 1;
            \Idno\Core\Idno::site()->config()->save();

            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/', false);

            ignore_user_abort(true);    // This is dangerous, but we need export to continue

            session_write_close();

            header('Connection: close');
            header('Content-length: ' . (string)ob_get_length());

            @ob_end_flush();            // Return output to the browser
            @ob_end_clean();
            @flush();

            sleep(10);                  // Pause

            set_time_limit(0);          // Eliminate time limit - this could take a while

            // Remove the previous export file
            if (!empty(\Idno\Core\Idno::site()->config()->export_file_id)) {
                if ($file = File::getByID(\Idno\Core\Idno::site()->config()->export_file_id)) {
                    if ($file instanceof \MongoGridFSFile) {
                        // TODO: Handle this correctly
                    } else {
                        $file->remove();
                    }
                    \Idno\Core\Idno::site()->config()->export_file_id  = false;
                    \Idno\Core\Idno::site()->config()->export_filename = false;
                    \Idno\Core\Idno::site()->config()->save();
                }
            }

            if ($path = Migration::createCompressedArchive()) {

                $filename = \Idno\Core\Idno::site()->config()->host . '.zip';
                /*                    header('Content-disposition: attachment;filename=' . $filename);
                                    if ($fp = fopen($path, 'r')) {
                                        while ($content = fread($fp, 4096)) {
                                            echo $content;
                                        }
                                    }
                                    fclose($fp);*/

                if ($file = static::storeArchive($path, $filename)) {
                    @unlink($path);
                    \Idno\Core\Idno::site()->config()->export_filename    = $filename;
                    \Idno\Core\Idno::site()->config()->export_file_id     = $file;
                    \Idno\Core\Idno::site()->config()->export_in_progress = 0;
                    \Idno\Core\Idno::site()->config()->save();

                    $mail = new Email();
                    $mail->setBodyFromTemplate('admin/export');
                    $mail->addTo(\Idno\Core\Idno::site()->session()->currentUser()->email);
                    $mail->send();
                } else {
                    \Idno\Core\Idno::site()->logging()->error("There was a problem creating file {$path}{$filename}");
                }

                exit;

            } else {
                \Idno\Core\Idno::site()->logging()->error("Export did not return an archive path.");
            }

        }

    }

}

