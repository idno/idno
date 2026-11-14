<?php

namespace Idno\Files {

    /*
     * Class LocalFileSystem
     * A file system capable of storing files on the local disk
     * @package Idno\Files
     */

    class LocalFileSystem extends FileSystem
    {

        /**
         * Find a file.
         *
         * @param  $id
         * @return mixed
         */
        public function findOne($id)
        {
            // Get path to load from
            $path = rtrim(\Idno\Core\Idno::site()->config()->uploadpath, ' /') . '/';

            if (is_array($id)) {
                if (!empty($id['_id'])) {
                    $id = $id['_id'];
                }
            }

            $id = (string)$id;

            $upload_file = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file';
            $data_file   = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.data';

            if (file_exists($upload_file)) {
                $file                    = new \Idno\Files\LocalFile();
                $file->_id               = $id;
                $file->internal_filename = $upload_file;
                if ($metadata = file_get_contents($data_file)) {
                    if ($metadata = json_decode($metadata, true)) {
                        $file->metadata       = $metadata;
                        $file->file           = $metadata;
                        $file->file['_id']    = $id;
                        $file->file['length'] = filesize($upload_file);
                    }
                }

                return $file;
            }

            return false;
        }

        /**
         * Merge $metadata into the stored metadata of the file with ID $id
         *
         * @param  string $id
         * @param  array  $metadata
         * @return bool
         */
        public function updateMetadata($id, array $metadata)
        {
            $id = (string) $id;
            if (!preg_match('/^[a-f0-9]{32}$/i', $id)) {
                return false;
            }

            $path      = rtrim(\Idno\Core\Idno::site()->config()->uploadpath, ' /') . '/';
            $data_file = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.data';
            if (!file_exists($data_file)) {
                return false;
            }

            $existing = json_decode((string) file_get_contents($data_file), true);
            if (!is_array($existing)) {
                $existing = [];
            }
            $merged = array_merge($existing, $metadata);
            if ($merged == $existing) {
                return true;
            }

            return @file_put_contents($data_file, json_encode($merged), LOCK_EX) !== false;
        }

        /**
         * Store the file at $file_path with $metadata and $options
         *
         * @param  $file_path
         * @param  $metadata
         * @param  $options
         * @return id of file
         */
        public function storeFile($file_path, $metadata, $options = [])
        {
            if (file_exists($file_path) && $path = \Idno\Core\Idno::site()->config()->uploadpath) {

                // Encode metadata for saving
                $metadata = json_encode($metadata);

                // Generate a random ID
                $id = md5(mt_rand() . microtime(true) . $metadata);

                // Generate save path
                if (substr($path, -1) != '/') {
                    $path .= '/';
                }
                $upload_file = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file';
                $data_file   = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.data';

                try {
                    foreach (array($path . \Idno\Core\Idno::site()->config()->getFileBaseDirName(), $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0], $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0] . '/' . $id[1], $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0] . '/' . $id[1] . '/' . $id[2], $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3]) as $up_path) {
                        if (!is_dir($up_path)) {
                            $result = @mkdir($up_path, 0777, true);
                        }
                    }

                    if (!@copy($file_path, $upload_file)) {
                        throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("There was a problem storing the file data."));
                    }
                    if (!@file_put_contents($data_file, $metadata)) {
                            throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("There was a problem saving the file's metadata"));
                    }

                    return $id;
                } catch (\Exception $e) {

                    // Ensure we capture the real error message
                    \Idno\Core\Idno::site()->logging()->error('Exception while uploading file', ['error' => $e]);

                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Something went wrong saving your file."));
                    if (\Idno\Core\Idno::site()->session()->isAdmin()) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Check that your upload directory is writeable by the web server and try again."));
                    }

                }

            }

            return false;
        }

        public function storeContent($content, $metadata, $options = [])
        {

            if (!empty($content) && $path = \Idno\Core\Idno::site()->config()->uploadpath) {

                // Encode metadata for saving
                $metadata = json_encode($metadata);

                // Generate a random ID
                $id = md5(mt_rand() . microtime(true) . $metadata);

                // Generate save path
                if (substr($path, -1) != '/') {
                    $path .= '/';
                }
                $upload_file = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file';
                $data_file   = $path . \Idno\Core\Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.data';

                try {
                    foreach (array($path . \Idno\Core\Idno::site()->config()->getFileBaseDirName(), $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0], $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0] . '/' . $id[1], $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0] . '/' . $id[1] . '/' . $id[2], $path . \Idno\Core\Idno::site()->config()->host . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3]) as $up_path) {
                        if (!is_dir($up_path)) {
                            $result = @mkdir($up_path, 0777, true);
                        }
                    }

                    if (!@file_put_contents($upload_file, $content)) {
                        throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("There was a problem storing the file data."));
                    }
                    if (!@file_put_contents($data_file, $metadata)) {
                        throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_("There was a problem saving the file's metadata"));
                    }

                    return $id;
                } catch (\Exception $e) {

                    // Ensure we capture the real error message
                    \Idno\Core\Idno::site()->logging()->error('Exception while uploading file', ['error' => $e]);

                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Something went wrong saving your file."));
                    if (\Idno\Core\Idno::site()->session()->isAdmin()) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Check that your upload directory is writeable by the web server and try again."));
                    }

                }

            }

            return false;
        }

    }

}

