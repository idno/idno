<?php

/**
 * Generic, backup viewer for entities
 */

namespace Idno\Pages\File {

    /**
     * Default class to serve the homepage
     */
    class View extends \Idno\Common\Page
    {

        // Handle GET requests to the entity

        function getContent()
        {
            if (!empty($this->arguments[0])) {
                $object = \Idno\Entities\File::getByID($this->arguments[0]);
            }

            if (empty($object)) {
                $this->noContent();
            }

            // Enforce access control: if file is attached to an entity, check canRead()
            if (!empty($object->file['attached_entity'])) {
                $parentEntity = \Idno\Common\Entity::getByUUID($object->file['attached_entity']);
                if ($parentEntity && !$parentEntity->canRead()) {
                    $this->setResponse(403);
                    echo '';
                    return;
                }
            }

            session_write_close();  // Close the session early
            //header("Pragma: public");
            // Determine uploaded timestamp
            if ($object instanceof \MongoGridFSFile) {
                $upload_ts = $object->file['uploadDate']->sec;
            } else if (!empty($object->updated)) {
                $upload_ts = $object->updated;
            } else if (!empty($object->created)) {
                $upload_ts = $object->created;
            } else {
                $upload_ts = time();
            }

            $this->lastModifiedGatekeeper($upload_ts); // 304 if we've not updated the object

            header("Pragma: public");
            header("Cache-Control: public");
            header('Expires: ' . date(\DateTime::RFC1123, time() + (86400 * 30))); // Cache files for 30 days!
            $this->setLastModifiedHeader($upload_ts);

            $mimeType = !empty($object->file['mime_type']) ? $object->file['mime_type'] : 'application/octet-stream';
            header('Content-type: ' . $mimeType);

            // Force download for MIME types that could execute scripts in the browser
            $safeMimeTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf',
                'audio/mpeg', 'audio/ogg', 'audio/wav',
                'video/mp4', 'video/ogg', 'video/webm',
            ];
            if (!in_array($mimeType, $safeMimeTypes)) {
                $filename = !empty($this->arguments[1]) ? basename($this->arguments[1]) : 'download';
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }

            header('Accept-Ranges: bytes');

            // Partial content
            if (isset($_SERVER['HTTP_RANGE'])) {

                $size = $object->getSize();
                $start = 0;
                $end = $size; // - 1;

                $c_start = (empty($start) || $end < abs(intval($start))) ? 0 : max(abs(intval($start)), 0);//$start;
                $c_end = (empty($end)) ? ($size - 1) : min(abs(intval($end)), ($size - 1)); //$end;

                // Parse range
                list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);

                if ($range[0] == '-') {
                    // Range form "-123"
                    $c_start = (int)($size - (int)substr($range, 1));
                } else {
                    // Range form "123-" or "123-345"
                    $range = explode('-', $range);
                    $c_start = (int)$range[0];

                    if (isset($range[1]) && is_numeric($range[1])) {
                        $c_end = (int)$range[1];
                    }
                }

                \Idno\Core\Idno::site()->logging()->debug("Partial content request for $c_start - $c_end bytes from $size available bytes");

                // Validate range
                if (($c_start > $c_end)  // Start after end
                    || ($c_end > $size)  // End after size
                    || ($c_start < 0) // Start less than zero
                ) {
                    $this->setResponse(416);
                    \Idno\Core\Idno::site()->logging()->debug('Requested Range Not Satisfiable');
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    exit;
                }

                // Now output headers and partial content
                $this->setResponse(206);
                header('Content-Length: ' . ($c_end-$c_start));
                header("Content-Range: bytes $c_start-$c_end/$size");

                \Idno\Core\Idno::site()->logging()->debug('Content-Length: ' . ($c_end-$c_start));
                \Idno\Core\Idno::site()->logging()->debug("Content-Range: bytes $c_start-$c_end/$size");

                if ($stream = $object->getResource()) {
                    @fseek($stream, $c_start);
                    $buffer = "";
                    while ( strlen($buffer)< $c_end-$c_start) {
                        $buffer .= fread($stream, $c_end-strlen($buffer));
                    }
                    //$data =  fread($stream, $c_end-$c_start);

                    echo $buffer;
                } else {
                    \Idno\Core\Idno::site()->logging()->error('Could not open stream.');
                }

            } else {

                header('Content-Length: ' . $object->getSize());

                if (is_callable(array($object, 'passThroughBytes'))) {
                    $object->passThroughBytes();
                } else {
                    if ($stream = $object->getResource()) {
                        while (!feof($stream)) {
                            echo fread($stream, 8192);
                        }
                    }
                }
            }
        }

    }

}
