<?php

/**
 * User-created file representation
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Entities {

    class File
    {

        /**
         * Write data to temporary file.
         * This function writes a temporary file, returning filename on success.
         *
         * @param type $data
         */
        public static function writeTmpFile($data)
        {
            $tmpname = tempnam(\Idno\Core\Idno::site()->config()->getTempDir(), 'known');

            if ($tmpname) {
                file_put_contents($tmpname, $data);

                return $tmpname;
            }

            return false;
        }

        /**
         * Given a path to an image on disk, generates and saves a thumbnail with maximum dimension $max_dimension.
         *
         * @param string $file_path Path to the file.
         * @param string $filename Filename that the file should have on download.
         * @param int $max_dimension The maximum number of pixels the thumbnail image should be along its longest side.
         * @param bool $square If this is set to true, the thumbnail will be made square.
         * @return bool|id
         */
        public static function createThumbnailFromFile($file_path, $filename, $max_dimension = 800, $square = false)
        {

            $thumbnail = false;

            // Rotate image where appropriate
            if (is_callable('exif_read_data')) {
                try {
                    if ($exif = exif_read_data($file_path)) {
                        if (!empty($exif['Orientation'])) {
                            $orientation = $exif['Orientation'];
                        }
                    }
                } catch (\Exception $e) {
                }
            }

            if ($photo_information = getimagesize($file_path)) {
                if ($photo_information[0] > $max_dimension || $photo_information[1] > $max_dimension) {
                    switch ($photo_information['mime']) {
                        case 'image/jpeg':
                            $image = imagecreatefromjpeg($file_path);
                            break;
                        case 'image/png':
                            if ($image = imagecreatefrompng($file_path)) {
                                $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
                                imagecolortransparent($image, $background);
                            }
                            break;
                        case 'image/gif':
                            if ($image = imagecreatefromgif($file_path)) {
                                $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
                                imagecolortransparent($image, $background);
                            }
                            break;
                    }
                    if (!empty($image)) {
                        if (isset($orientation)) {
                            switch ($orientation) {
                                case 8:
                                    $image = imagerotate($image, 90, 0);
                                    break;
                                case 3:
                                    $image = imagerotate($image, 180, 0);
                                    break;
                                case 6:
                                    $image = imagerotate($image, -90, 0);
                                    break;
                            }
                        }
                        $existing_width = imagesx($image);
                        $existing_height = imagesy($image);
                        if ($existing_width > $existing_height) {
                            $width = $max_dimension;
                            $height = round($existing_height * ($max_dimension / $existing_width));
                        } else {
                            $height = $max_dimension;
                            $width = round($existing_width * ($max_dimension / $existing_height));
                        }
                        if ($square) {
                            if ($width > $height) {
                                $new_height = $max_dimension;
                                $new_width = $max_dimension;
                                $original_height = $existing_height;
                                $original_width = $existing_height;
                                $offset_x = round(($existing_width - $existing_height) / 2);
                                $offset_y = 0;
                            } else {
                                $new_height = $max_dimension;
                                $new_width = $max_dimension;
                                $original_height = $existing_width;
                                $original_width = $existing_width;
                                $offset_x = 0;
                                $offset_y = round(($existing_height - $existing_width) / 2);
                            }
                        } else {
                            $new_height = $height;
                            $new_width = $width;
                            $original_height = $existing_height;//$photo_information[1];
                            $original_width = $existing_width; //$photo_information[0];
                            $offset_x = 0;
                            $offset_y = 0;
                        }
                        $image_copy = imagecreatetruecolor($new_width, $new_height);
                        imagealphablending($image_copy, false);
                        imagesavealpha($image_copy, true);
                        imagecopyresampled($image_copy, $image, 0, 0, $offset_x, $offset_y, $new_width, $new_height, $original_width, $original_height);

                        $tmp_dir = dirname($file_path);
                        switch ($photo_information['mime']) {
                            case 'image/jpeg':
                                imagejpeg($image_copy, $tmp_dir . '/' . $filename . '.jpg', 85);
                                $thumbnail = \Idno\Entities\File::createFromFile($tmp_dir . '/' . $filename . '.jpg', "thumb_{$max_dimension}.jpg", 'image/jpeg') . '/thumb.jpg';
                                @unlink($tmp_dir . '/' . $filename . '.jpg');
                                break;
                            case 'image/png':
                                imagepng($image_copy, $tmp_dir . '/' . $filename . '.png');
                                $thumbnail = \Idno\Entities\File::createFromFile($tmp_dir . '/' . $filename . '.png', "thumb_{$max_dimension}.png", 'image/png') . '/thumb.png';
                                @unlink($tmp_dir . '/' . $filename . '.png');
                                break;
                            case 'image/gif':
                                imagegif($image_copy, $tmp_dir . '/' . $filename . '.gif');
                                $thumbnail = \Idno\Entities\File::createFromFile($tmp_dir . '/' . $filename . '.gif', "thumb_{$max_dimension}.gif", 'image/gif') . '/thumb.gif';
                                @unlink($tmp_dir . '/' . $filename . '.gif');
                                break;
                        }
                    }
                } else {

                }

                return $thumbnail;

            }

            return false;
        }

        /**
         * Save a file to the filesystem and return the ID
         *
         * @param string $file_path Full local path to the file
         * @param string $filename Filename to store
         * @param string $mime_type MIME type associated with the file
         * @param bool $return_object Return the file object? If set to false (as is default), will return the ID
         * @param bool $destroy_exif When true, if an image is uploaded the exif data will be destroyed.
         * @return bool|\id Depending on success
         */
        public static function createFromFile($file_path, $filename, $mime_type = 'application/octet-stream', $return_object = false, $destroy_exif = false)
        {
            if (file_exists($file_path) && !empty($filename)) {
                if ($fs = \Idno\Core\Idno::site()->filesystem()) {
                    $file = new File();

                    // Get image filesize
                    if (self::isImage($file_path)) {
                        $photo_information = getimagesize($file_path);
                        if (!empty($photo_information[0]) && !empty($photo_information[1])) {
                            $metadata['width'] = $photo_information[0];
                            $metadata['height'] = $photo_information[1];
                        }
                    }

                    // Is this an image?
                    if (!empty($photo_information)) {

                        $extension = strtolower(substr($filename, strrpos($filename, '.') + 1));
                        switch ($photo_information['mime']) {
                            case 'image/jpeg':
                                $mime_type = 'image/jpeg'; // Hardcode file metadata
                                if (!in_array($extension, ['jpg','jpeg'])) $filename .= '.jpg';
                                break;
                            case 'image/gif':
                                $mime_type = 'image/gif'; // Hardcode file metadata
                                if ($extension != 'gif') $filename .= '.gif';
                                break;
                            case 'image/png':
                                $mime_type = 'image/png'; // Hardcode file metadata
                                if ($extension != 'png') $filename .= '.png';
                                break;
                            case 'image/svg+xml':
                                $mime_type = 'image/svg+xml'; // Hardcode file metadata
                                if ($extension != 'svg') $filename .= '.svg';
                                break;
                            case 'image/webp':
                                $mime_type = 'image/webp'; // Hardcode file metadata
                                if ($extension != 'webp') $filename .= '.webp';
                                break;
                        }

                        // Do we want to remove EXIF data?
                        if ($destroy_exif) {
                            $tmpfname = $file_path;
                            switch ($photo_information['mime']) {
                                case 'image/jpeg':
                                    $image = imagecreatefromjpeg($tmpfname);

                                    // Since we're stripping Exif, we need to manually adjust orientation of main image
                                    try {
                                        if (function_exists('exif_read_data')) {

                                            $exif = exif_read_data($tmpfname);
                                            if (!empty($exif['Orientation'])) {
                                                switch ($exif['Orientation']) {
                                                    case 8:
                                                        $image = imagerotate($image, 90, 0);
                                                        break;
                                                    case 3:
                                                        $image = imagerotate($image, 180, 0);
                                                        break;
                                                    case 6:
                                                        $image = imagerotate($image, -90, 0);
                                                        break;
                                                }
                                            }

                                            $metadata['width'] = imagesx($image);
                                            $metadata['height'] = imagesy($image);
                                        }

                                        imagejpeg($image, $tmpfname);
                                    } catch (\Exception $e) {
                                        \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                                    }
                                    break;
                            }

                        }
                    }

                    $metadata = array(
                        'filename' => $filename,
                        'mime_type' => $mime_type
                    );

                    if ($id = $fs->storeFile($file_path, $metadata, $metadata)) {
                        if (!$return_object) {
                            return $id;
                        } else {
                            return self::getByID($id);
                        }
                    }
                }
            }

            return false;
        }

        /**
         * Determines whether a file is an image or not.
         *
         * @param string $file_path The path to a file
         * @return bool
         */
        public static function isImage($file_path)
        {
            if (!empty($file_path)) {
                if ($photo_information = getimagesize($file_path)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Detects whether the file contains PHP or script tags, eg to check for embedded code in GIFs
         * @param $file_path
         * @param $file_name? Optionally, the file name
         * @return bool
         */
        public static function isFileFreeFromScriptTags($file_path, $file_name = '')
        {
            foreach ([$file_name, file_get_contents($file_path)] as $contents) {
                $contents = strtolower($contents);
                // Check for script tags and PHP tags
                if (stripos($contents, '<script') !== false || stripos($contents, '<?php') !== false) {
                    return false;
                }
                // Check for event handler attributes (XSS vectors in SVG/HTML)
                if (preg_match('/\bon(error|load|click|mouseover|mouseout|focus|blur|submit|change|input|keydown|keyup|keypress)\s*=/i', $contents)) {
                    return false;
                }
                // Check for javascript: URIs
                if (preg_match('/javascript\s*:/i', $contents)) {
                    return false;
                }
            }
            return true;
        }

        /**
         * Retrieve a file by ID
         *
         * @param string $id
         * @return \Idno\Common\Entity|\MongoGridFSFile|null
         */
        static function getByID($id)
        {
            if ($fs = \Idno\Core\Idno::site()->filesystem()) {
                try {
                    return $fs->findOne(array('_id' => \Idno\Core\Idno::site()->db()->processID($id)));
                } catch (\Exception $e) {
                    \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                }
            }

            return false;
        }

        /**
         * Given a file and an original file path, determines whether this file is an SVG
         *
         * @param  $file_path
         * @return bool
         */
        public static function isSVG($file_path, $original_file_path)
        {
            if (pathinfo($original_file_path, PATHINFO_EXTENSION) == 'svg') {
                return true; // TODO better SVG validation would be nice
            }

            return false;
        }

        /**
         * Retrieve a file by UUID
         *
         * @param string $uuid
         * @return bool|\Idno\Common\Entity
         */
        static function getByUUID($uuid)
        {
            if ($fs = \Idno\Core\Idno::site()->filesystem()) {
                return $fs->findOne($uuid);
            }

            return false;
        }

        /**
         * Attempt to extract a file from a URL to it. Will fail with false if the file is external or otherwise
         * can't be retrieved.
         *
         * @param  $url
         * @return \Idno\Common\Entity|\MongoGridFSFile|null
         */
        static function getByURL($url)
        {
            if (substr_count($url, \Idno\Core\Idno::site()->config()->getDisplayURL() . 'file/')) {

                if (preg_match('#' . \Idno\Core\Idno::site()->config()->getDisplayURL() . "file\/([a-zA-Z0-9]+)+#", $url, $matches)) {
                    $url = $matches[1];
                }

                return self::getByID($url);
            }

            return false;
        }

        /**
         * Retrieve file data from an attachment (first trying load from local storage, then from URL)
         *
         * @param  $attachment
         * @return bool|mixed|string
         */
        static function getFileDataFromAttachment($attachment)
        {
            \Idno\Core\Idno::site()->logging()->debug("getting file data from attachment", ['attachment' => $attachment]);
            if (!empty($attachment['_id'])) {
                //\Idno\Core\Idno::site()->logging()->debug("Checking attachment ID");
                if ($bytes = self::getFileDataByID((string)$attachment['_id'])) {
                    //\Idno\Core\Idno::site()->logging()->debug("Retrieved some bytes");
                    if (strlen($bytes)) {
                        //\Idno\Core\Idno::site()->logging()->debug("Bytes! " . $bytes);
                        return $bytes;
                    } else {
                        //\Idno\Core\Idno::site()->logging()->debug("Sadly no bytes");
                    }
                } else {
                    //\Idno\Core\Idno::site()->logging()->debug("No bytes retrieved");
                }
            } else {
                \Idno\Core\Idno::site()->logging()->debug("Empty attachment _id");
            }
            if (!empty($attachment['url'])) {
                try {
                    if ($bytes = @file_get_contents($attachment['url'])) {
                        \Idno\Core\Idno::site()->logging()->debug("Returning bytes");

                        return $bytes;
                    } else {
                        \Idno\Core\Idno::site()->logging()->debug("Couldn't get bytes from " . $attachment['url']);
                    }
                } catch (\Exception $e) {
                    \Idno\Core\Idno::site()->logging()->debug("Couldn't get bytes from " . $attachment['url']);
                }
            } else {
                \Idno\Core\Idno::site()->logging()->debug('Attachment url was empty ' . $attachment['url']);
            }

            return false;
        }

        /**
         * Retrieve file data by ID
         *
         * @param string $id
         * @return mixed
         */
        static function getFileDataByID($id)
        {
            if ($file = self::getByID($id)) {
                try {
                    return $file->getBytes();
                } catch (\Exception $e) {
                    \Idno\Core\Idno::site()->logging()->error($e->getMessage());
                }
            }

            return false;
        }

        /**
         * Return the MIME type associated with this file
         *
         * @return null|string
         */
        function getMimeType()
        {
            $mime_type = $this->mime_type;
            if (!empty($mime_type)) {
                return $this->mime_type;
            }

            return 'application/octet-stream';
        }

        /**
         * Get the publicly visible filename associated with this file
         *
         * @return string
         */
        function getURL()
        {
            if (!empty($this->_id)) {
                return \Idno\Core\Idno::site()->config()->url . 'file/' . $this->_id . '/' . urlencode($this->filename);
            }

            return '';
        }

        function remove()
        {
            return $this->delete();
        }

        function delete()
        {
            // TODO deleting files would be good ...
        }

        /**
         * Passes through the contents of this file.
         */
        function passThroughBytes()
        {
            echo $this->getBytes();
        }

    }

}

