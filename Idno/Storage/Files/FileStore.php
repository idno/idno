<?php

declare(strict_types=1);

namespace Idno\Storage\Files;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Uploaded files on a Flysystem filesystem, in the layout every Idno site has used:
 * `<directory>/<id[0]>/<id[1]>/<id[2]>/<id[3]>/<id>.file` holds the bytes and the `.data` file beside
 * it holds the metadata as `json_encode($metadata)`. A file exists when its `.file` does; a missing or
 * undecodable `.data` is metadata `[]`.
 *
 * An id is 32 hex digits (a local file) or 24 (a GridFS file). It is checked before any path is built
 * from it: anything else is no file, never a path.
 */
final class FileStore
{
    private const ID_PATTERN = '/^[a-f0-9]{24}(?:[a-f0-9]{8})?$/i';

    private readonly \Closure|string $directory;

    /**
     * @param \Closure|string $directory the base directory name, or a closure that returns it (asked at each call)
     */
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        \Closure|string $directory,
    )
    {
        $this->directory = $directory;
    }

    /**
     * Store a stream under a new id and return the id.
     *
     * @param resource             $stream
     * @param array<string, mixed> $metadata
     * @throws FileStoreException
     */
    public function store($stream, array $metadata): string
    {
        $id = bin2hex(random_bytes(16));
        $this->storeAs($id, $stream, $metadata);

        return $id;
    }

    /**
     * Store a stream under an id, replacing any file there.
     *
     * @param resource             $stream
     * @param array<string, mixed> $metadata
     * @throws \InvalidArgumentException for an invalid id
     * @throws FileStoreException
     */
    public function storeAs(string $id, $stream, array $metadata): void
    {
        $path = $this->path($id);
        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FileStoreException('The file metadata cannot be encoded as JSON.', 0, $e);
        }

        try {
            $this->filesystem->writeStream($path, $stream);
            $this->filesystem->write($this->dataPath($path), $json);
        } catch (FilesystemException $e) {
            // Do not leave bytes without metadata behind.
            try {
                $this->filesystem->delete($path);
            } catch (FilesystemException) {
            }

            throw new FileStoreException('The file could not be stored.', 0, $e);
        }
    }

    /**
     * @throws FileStoreException
     */
    public function find(string $id): ?StoredFile
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $path = $this->path($id);
        try {
            if (!$this->filesystem->fileExists($path)) {
                return null;
            }
            $size = $this->filesystem->fileSize($path);
        } catch (FilesystemException $e) {
            throw new FileStoreException('The file could not be read.', 0, $e);
        }

        return new StoredFile($id, $this->readMetadata($path), $size);
    }

    /**
     * @return resource
     * @throws FileStoreException when the id is invalid, the file is not there or it cannot be read
     */
    public function readStream(string $id)
    {
        if (!self::isValidId($id)) {
            throw new FileStoreException('There is no such file.');
        }
        try {
            return $this->filesystem->readStream($this->path($id));
        } catch (FilesystemException $e) {
            throw new FileStoreException('The file could not be read.', 0, $e);
        }
    }

    /**
     * Merge metadata into the file's own. False when the file is not there; true when nothing changed (nothing is written).
     *
     * @param array<string, mixed> $metadata
     * @throws FileStoreException
     */
    public function updateMetadata(string $id, array $metadata): bool
    {
        if (!self::isValidId($id)) {
            return false;
        }
        $path = $this->path($id);
        try {
            if (!$this->filesystem->fileExists($path)) {
                return false;
            }
            $existing = $this->readMetadata($path);
            $merged   = array_merge($existing, $metadata);
            if ($merged == $existing) {
                return true;
            }
            $this->filesystem->write($this->dataPath($path), json_encode($merged, JSON_THROW_ON_ERROR));
        } catch (\JsonException | FilesystemException $e) {
            throw new FileStoreException('The file metadata could not be saved.', 0, $e);
        }

        return true;
    }

    /**
     * Remove the file and its metadata. An invalid id or a missing file is not an error.
     *
     * @throws FileStoreException
     */
    public function delete(string $id): void
    {
        if (!self::isValidId($id)) {
            return;
        }
        $path = $this->path($id);
        try {
            $this->filesystem->delete($path);
            $this->filesystem->delete($this->dataPath($path));
        } catch (FilesystemException $e) {
            throw new FileStoreException('The file could not be deleted.', 0, $e);
        }
    }

    /**
     * @throws FileStoreException
     */
    public function has(string $id): bool
    {
        if (!self::isValidId($id)) {
            return false;
        }
        try {
            return $this->filesystem->fileExists($this->path($id));
        } catch (FilesystemException $e) {
            throw new FileStoreException('The file could not be checked.', 0, $e);
        }
    }

    /**
     * The path of the file's `.file` within the filesystem.
     *
     * @throws \InvalidArgumentException for an invalid id
     */
    public function path(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new \InvalidArgumentException('Not a file id.');
        }
        $directory = $this->directory instanceof \Closure ? ($this->directory)() : $this->directory;

        return $directory . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file';
    }

    private static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    private function dataPath(string $path): string
    {
        return substr($path, 0, -strlen('.file')) . '.data';
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(string $path): array
    {
        try {
            $decoded = json_decode($this->filesystem->read($this->dataPath($path)), true);
        } catch (FilesystemException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
