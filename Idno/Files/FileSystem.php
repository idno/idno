<?php

namespace Idno\Files {

    /*
     * Class FileSystem
     * Represents a file system capable of storing files
     * @package Idno\Files
     */

    abstract class FileSystem
    {

        /**
         * Find a file.
         *
         * @param  $_id
         * @return mixed
         */
        abstract function findOne($_id);

        /**
         * Store the file at $file_path with $metadata and $options
         *
         * @param  $file_path
         * @param  $metadata
         * @param  $options
         * @return id of file
         */
        abstract function storeFile($file_path, $metadata, $options = []);

        /**
         * Store file from contents already loaded.
         *
         * @param  $contents
         * @param  $metadata
         * @param  $options
         * @return id of file
         */
        abstract function storeContent($content, $metadata, $options = []);

        /**
         * Merge $metadata into the stored metadata of the file with ID $id.
         * Filesystems that can't update metadata may leave this default in
         * place, in which case entity-based file access control is not applied.
         *
         * @param  string $id
         * @param  array  $metadata
         * @return bool True if the metadata is stored (or was already current)
         */
        public function updateMetadata($id, array $metadata)
        {
            return false;
        }

        /**
         * Get a translated error message for PHP Upload errors.
         *
         * @param int $code
         * @see   http://php.net/manual/en/features.file-upload.errors.php
         */
        public static function getUploadErrorCodeMessage($code)
        {

            $code = intval($code);

            $array = [
                UPLOAD_ERR_OK         => 'There is no error, the file uploaded with success',
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            ];

            $language = \Idno\Core\Idno::site()->language();
            if (!empty($language)) {
                $array = [
                    UPLOAD_ERR_OK         => $language->_('There is no error, the file uploaded with success'),
                    UPLOAD_ERR_INI_SIZE   => $language->_('The uploaded file exceeds the upload_max_filesize directive in php.ini'),
                    UPLOAD_ERR_FORM_SIZE  => $language->_('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
                    UPLOAD_ERR_PARTIAL    => $language->_('The uploaded file was only partially uploaded'),
                    UPLOAD_ERR_NO_FILE    => $language->_('No file was uploaded'),
                    UPLOAD_ERR_NO_TMP_DIR => $language->_('Missing a temporary folder'),
                    UPLOAD_ERR_CANT_WRITE => $language->_('Failed to write file to disk.'),
                    UPLOAD_ERR_EXTENSION  => $language->_('A PHP extension stopped the file upload.'),
                ];
            }

            return $array[$code];
        }

    }

}

