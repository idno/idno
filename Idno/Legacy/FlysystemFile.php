<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Storage\Files\FileStore;
use Idno\Storage\Files\FileStoreException;
use Idno\Storage\Files\StoredFile;

/**
 * A stored file, as the legacy Idno\Files\File API shows it: its id, its metadata, `$file` (the
 * metadata plus `_id` and `length`) and `$internal_filename` (the absolute path of the file on a
 * local upload directory; '' on any other filesystem). The bytes are read from the FileStore as
 * streams. The legacy API never threw: a failure is logged and gives the legacy failure value.
 */
final class FlysystemFile extends \Idno\Files\File
{
    public string $internal_filename = '';

    /**
     * @param \Closure(): \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        private readonly FileStore $store,
        StoredFile $stored,
        string $internalFilename,
        private readonly \Closure $logger,
    )
    {
        $this->_id               = $stored->id;
        $this->metadata          = $stored->metadata;
        $this->file              = $stored->metadata;
        $this->file['_id']       = $stored->id;
        $this->file['length']    = $stored->size;
        $this->internal_filename = $internalFilename;
    }

    /**
     * This file's contents. For larger files this might not be wise.
     */
    public function getBytes()
    {
        $stream = $this->getResource();
        if ($stream === false) {
            return null;
        }
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    public function getSize()
    {
        return $this->file['length'];
    }

    /**
     * Output the contents of the file.
     */
    public function passThroughBytes()
    {
        $stream = $this->getResource();
        if ($stream === false) {
            return;
        }
        \Idno\Pages\File\View::endOutputBuffers();
        fpassthru($stream);
        fclose($stream);
    }

    /**
     * A stream of the file's bytes, or false.
     *
     * @return resource|false
     */
    public function getResource()
    {
        try {
            return $this->store->readStream($this->_id);
        } catch (FileStoreException $e) {
            ($this->logger)()->error('Could not read a file', ['error' => $e]);

            return false;
        }
    }

    /**
     * Delete the file and its metadata.
     */
    public function delete()
    {
        try {
            $this->store->delete($this->_id);

            return true;
        } catch (FileStoreException $e) {
            ($this->logger)()->error('Could not delete a file', ['error' => $e]);

            return false;
        }
    }

    /**
     * Write the file's bytes to the path $path.
     *
     * @param  string $path
     * @return bool
     */
    public function write($path)
    {
        $stream = $this->getResource();
        if ($stream === false) {
            return false;
        }
        $target = @fopen((string) $path, 'wb');
        if ($target === false) {
            fclose($stream);

            return false;
        }
        $copied = stream_copy_to_stream($stream, $target);
        fclose($stream);

        return fclose($target) && $copied !== false;
    }

    /**
     * The file's filename: the one in its metadata, else the name of its stored file.
     */
    public function getFilename()
    {
        if (!empty($this->metadata['filename'])) {
            return $this->metadata['filename'];
        }

        return basename($this->store->path($this->_id));
    }
}
