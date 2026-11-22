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

        /**
         * Position a stream at a byte offset. A stream that cannot seek (one read over HTTP, from S3,
         * say) is read and discarded up to the offset instead.
         *
         * @param resource $stream
         * @param int      $offset
         */
        public static function seekTo($stream, $offset)
        {
            if ($offset <= 0 || @fseek($stream, $offset) === 0) {
                return;
            }
            // fseek() failed: the stream is still where it was (the start, for a stream just opened)
            $skipped = (int) ftell($stream);
            while ($skipped < $offset && !feof($stream)) {
                $chunk = fread($stream, min(8192, $offset - $skipped));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $skipped += strlen($chunk);
            }
        }

        /**
         * Write the bytes of a range from a stream positioned at its first byte (seekTo()) to the output,
         * 8 KiB at a time, so that a range of any size is never held in memory: the bytes from $start to
         * $end, both positions inclusive, so exactly as many as the response's Content-Length announces;
         * fewer when the stream ends first, or a read returns nothing or fails: it never waits for bytes
         * that do not come.
         *
         * @param  resource      $stream
         * @param  int           $start
         * @param  int           $end
         * @param  callable|null $write  Called with each piece; null echoes it
         * @return int The number of bytes written
         */
        public static function streamRange($stream, $start, $end, $write = null)
        {
            if ($write === null) {
                self::endOutputBuffers();
            }
            $length  = $end - $start + 1;
            $written = 0;
            while ($written < $length) {
                $chunk = fread($stream, min(8192, $length - $written));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if ($write === null) {
                    echo $chunk;
                } else {
                    $write($chunk);
                }
                $written += strlen($chunk);
            }

            return $written;
        }

        /**
         * End every active output buffer, flushing what it holds first (so output that came before is
         * not lost or reordered), so that what a file page echoes next goes out as it is written and is
         * never accumulated in memory. The one place that does it: the file store's pass-through uses it too.
         */
        public static function endOutputBuffers()
        {
            while (ob_get_level() > 0) {
                if (!@ob_end_flush()) {
                    break;
                }
            }
        }

        /**
         * Write a whole stream to the output, 8 KiB at a time. It stops at the end of the stream, or on an
         * empty or failed read, as streamRange() does: it never spins, and never writes `false`.
         *
         * @param  resource      $stream
         * @param  callable|null $write  Called with each piece; null echoes it
         * @return int The number of bytes written
         */
        public static function streamAll($stream, $write = null)
        {
            if ($write === null) {
                self::endOutputBuffers();
            }
            $written = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                if ($write === null) {
                    echo $chunk;
                } else {
                    $write($chunk);
                }
                $written += strlen($chunk);
            }

            return $written;
        }

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
            $restricted = false;
            if (!empty($object->file['attached_entity'])) {
                // Load the entity whether or not the current user can see it: a lookup that
                // respects access would not find it, and the check below would be skipped
                $db = \Idno\Core\Idno::site()->db();
                $ignoreAccess = $db->setIgnoreAccess(true);
                $parentEntity = \Idno\Common\Entity::getOneFromAll(['uuid' => $object->file['attached_entity']]);
                $db->setIgnoreAccess($ignoreAccess);
                if ($parentEntity) {
                    if (!$parentEntity->canRead()) {
                        $this->setResponse(403);
                        echo '';
                        return;
                    }
                    $publishStatus = $parentEntity->getPublishStatus();
                    $restricted = ($parentEntity->access !== 'PUBLIC') || (!empty($publishStatus) && $publishStatus != 'published');
                }
            }

            session_write_close();  // Close the session early
            //header("Pragma: public");
            // Determine uploaded timestamp
            if (!empty($object->updated)) {
                $upload_ts = $object->updated;
            } else if (!empty($object->created)) {
                $upload_ts = $object->created;
            } else {
                $upload_ts = time();
            }

            $this->lastModifiedGatekeeper($upload_ts); // 304 if we've not updated the object

            if ($restricted) {
                // Never let shared caches keep a copy of a file with restricted access
                header("Cache-Control: private, no-cache");
            } else {
                header("Pragma: public");
                header("Cache-Control: public");
                header('Expires: ' . date(\DateTime::RFC1123, time() + (86400 * 30))); // Cache files for 30 days!
            }
            $this->setLastModifiedHeader($upload_ts);

            $mimeType = !empty($object->file['mime_type']) ? $object->file['mime_type'] : 'application/octet-stream';
            $mimeType = preg_replace('/[\r\n]+.*/s', '', (string) $mimeType);
            header('Content-type: ' . $mimeType);
            header('X-Content-Type-Options: nosniff');

            // Force download for MIME types that could execute scripts in the browser
            $safeMimeTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf',
                'audio/mpeg', 'audio/ogg', 'audio/wav',
                'video/mp4', 'video/ogg', 'video/webm',
            ];
            if (!in_array(strtolower(trim(explode(';', $mimeType)[0])), $safeMimeTypes, true)) {
                $filename = !empty($this->arguments[1]) ? basename($this->arguments[1]) : 'download';
                $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }

            header('Accept-Ranges: bytes');

            // Partial content
            $size = (int) $object->getSize();
            $range = isset($_SERVER['HTTP_RANGE']) ? static::parseRange($_SERVER['HTTP_RANGE'], $size) : null;

            if ($range === false) {
                $this->setResponse(416);
                \Idno\Core\Idno::site()->logging()->debug('Requested Range Not Satisfiable');
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes */$size");
                exit;
            }

            if ($range !== null) {

                list($c_start, $c_end) = $range;

                // Both positions are inclusive, so bytes 5-5 is one byte
                $length = $c_end - $c_start + 1;

                \Idno\Core\Idno::site()->logging()->debug("Partial content request for $c_start - $c_end bytes from $size available bytes");

                // Now output headers and partial content
                $this->setResponse(206);
                header('Content-Length: ' . $length);
                header("Content-Range: bytes $c_start-$c_end/$size");

                if ($stream = $object->getResource()) {
                    self::seekTo($stream, $c_start);

                    self::streamRange($stream, $c_start, $c_end);
                } else {
                    \Idno\Core\Idno::site()->logging()->error('Could not open stream.');
                }

            } else {

                header('Content-Length: ' . $object->getSize());

                if (is_callable(array($object, 'passThroughBytes'))) {
                    $object->passThroughBytes();
                } else {
                    if ($stream = $object->getResource()) {
                        self::streamAll($stream);
                    }
                }
            }
        }

        /**
         * Work out which bytes of a file a Range request header asks for (RFC 9110, section 14).
         *
         * @param  string $header The value of the Range header, for example "bytes=0-499", "bytes=500-" or "bytes=-500"
         * @param  int    $size   The size of the file in bytes
         * @return array|false|null The positions of the first and the last byte to send, counted from 0;
         *                          false if the file has none of the bytes asked for (answer 416);
         *                          null if the header is not a single range of bytes, and so is ignored
         */
        static function parseRange($header, $size)
        {
            if (!preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', (string) $header, $matches)) {
                return null;
            }
            list(, $first, $last) = $matches;
            $size = (int) $size;

            if ($first === '') {
                if ($last === '') {
                    return null;
                }

                // "-500": the last 500 bytes, or the whole file if it is smaller
                if ((int) $last == 0 || $size == 0) {
                    return false;
                }

                return [max($size - (int) $last, 0), $size - 1];
            }

            $start = (int) $first;

            // "500-": from byte 500 to the end. An end past the last byte means the last byte.
            $end = ($last === '') ? $size - 1 : min((int) $last, $size - 1);

            if ($start >= $size || ($last !== '' && (int) $last < $start)) {
                return false;
            }

            return [$start, $end];
        }

    }

}
