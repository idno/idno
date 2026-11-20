<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Storage\Files\FileStore;
use Idno\Storage\Files\FileStoreException;
use League\Flysystem\FilesystemException;

/**
 * The legacy Idno\Files\FileSystem API (`Idno::site()->filesystem()`) served by a FileStore: the
 * same ids, the same layout in the upload directory, and the legacy failure values. The legacy API
 * never threw, so a FileStoreException is logged and becomes false; a failed store also adds the
 * session messages the old local filesystem added. So that no failure of the store reaches a visitor
 * as the 500 page (whose message can hold a server path), what is caught is everything the store can
 * throw: its own exception, Flysystem's (an upload directory that does not exist and cannot be
 * created, say) and the factory's \LogicException (no upload path).
 *
 * Everything it needs is a closure fetched at first use: the filesystem is built while the legacy
 * services are, before the logger, the session and the language exist.
 */
final class FlysystemFileSystem extends \Idno\Files\FileSystem
{
    /**
     * @param \Closure(): FileStore                $store      the FileStore service
     * @param \Closure(): \Psr\Log\LoggerInterface $logger     the site's logger
     * @param string                               $uploadRoot the local upload directory, which a file's `internal_filename` is under; '' when
     *                                                         the files are not on a local directory
     */
    public function __construct(
        private readonly \Closure $store,
        private readonly \Closure $logger,
        private readonly string $uploadRoot,
    )
    {
    }

    /**
     * Find a file.
     *
     * @param  string|array<string, mixed> $id the id, or an array holding it as `_id`
     * @return FlysystemFile|false
     */
    public function findOne($id)
    {
        if (is_array($id)) {
            $id = $id['_id'] ?? '';
        }
        if (!is_scalar($id) && !($id instanceof \Stringable)) {
            return false;
        }
        $id = (string) $id;

        try {
            $store  = ($this->store)();
            $stored = $store->find($id);
            if ($stored === null) {
                return false;
            }
            $internal = $this->uploadRoot === '' ? '' : rtrim($this->uploadRoot, ' /') . '/' . $store->path($id);

            return new FlysystemFile($store, $stored, $internal, $this->logger);
        } catch (FileStoreException | FilesystemException | \LogicException $e) {
            ($this->logger)()->error('Could not find a file', ['error' => $e]);

            return false;
        }
    }

    /**
     * Merge $metadata into the stored metadata of the file with ID $id.
     *
     * @param  string $id
     * @param  array  $metadata
     * @return bool
     */
    public function updateMetadata($id, array $metadata)
    {
        try {
            return ($this->store)()->updateMetadata((string) $id, $metadata);
        } catch (FileStoreException | FilesystemException | \LogicException $e) {
            ($this->logger)()->error('Could not update a file\'s metadata', ['error' => $e]);

            return false;
        }
    }

    /**
     * Store the file at $file_path with $metadata.
     *
     * @param  $file_path
     * @param  $metadata
     * @param  $options
     * @return string|false the id of the file
     */
    public function storeFile($file_path, $metadata, $options = [])
    {
        if (!is_string($file_path) || !file_exists($file_path)) {
            return false;
        }
        $stream = @fopen($file_path, 'rb');
        if ($stream === false) {
            $this->storeFailed(new FileStoreException('The file to store could not be opened.'));

            return false;
        }
        try {
            return ($this->store)()->store($stream, (array) $metadata);
        } catch (FileStoreException | FilesystemException | \LogicException $e) {
            $this->storeFailed($e);

            return false;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Store file contents already loaded.
     *
     * @param  $content
     * @param  $metadata
     * @param  $options
     * @return string|false the id of the file
     */
    public function storeContent($content, $metadata, $options = [])
    {
        if (empty($content)) {
            return false;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return false;
        }
        try {
            fwrite($stream, (string) $content);
            rewind($stream);

            return ($this->store)()->store($stream, (array) $metadata);
        } catch (FileStoreException | FilesystemException | \LogicException $e) {
            $this->storeFailed($e);

            return false;
        } finally {
            fclose($stream);
        }
    }

    private function storeFailed(\Throwable $e): void
    {
        ($this->logger)()->error('Exception while uploading file', ['error' => $e]);

        $site = Idno::site();
        $site->session()->addMessage($site->language()->_('Something went wrong saving your file.'));
        if ($site->session()->isAdmin()) {
            $site->session()->addMessage($site->language()->_('Check that your upload directory is writeable by the web server and try again.'));
        }
    }
}
