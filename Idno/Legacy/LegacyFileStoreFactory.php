<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\Files\FilesystemFactory;
use Idno\Storage\Files\FileStore;
use League\Flysystem\FilesystemOperator;

/**
 * Builds the container's Flysystem filesystem and FileStore at runtime, when they are first fetched.
 * The filesystem is the one `FilesystemFactory` makes from the boot configuration (a local
 * directory, or S3 for an `s3://` upload path; it throws a \LogicException with no upload path). The
 * store keeps files under the site's base directory, `Config::getFileBaseDirName()`, asked at each
 * call (it can change with the `file_path_host` setting and the `file/path/host` event), so the
 * store needs the legacy application, like the storage services: do not fetch it before it has booted.
 */
final class LegacyFileStoreFactory
{
    public function __construct(private readonly BootConfig $bootConfig)
    {
    }

    public function filesystem(): FilesystemOperator
    {
        return FilesystemFactory::fromConfig($this->bootConfig->all());
    }

    public function store(FilesystemOperator $filesystem): FileStore
    {
        return new FileStore($filesystem, static fn (): string => (string) Idno::site()->config()->getFileBaseDirName());
    }
}
