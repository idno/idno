<?php

declare(strict_types=1);

namespace Idno\Storage\Files;

/**
 * A file could not be stored, read or removed. The previous exception is Flysystem's, when there is one.
 */
final class FileStoreException extends \RuntimeException
{
}
