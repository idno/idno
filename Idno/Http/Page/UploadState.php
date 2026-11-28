<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * What became of a file field of a request.
 */
enum UploadState
{
    /** PHP received a file through HTTP and it can be read */
    case Received;

    /** nothing was chosen (no field, an empty file input, or a list of files where one was expected) */
    case Missing;

    /** the server refused it for its size (upload_max_filesize, the form's limit, or a body over post_max_size) */
    case TooLarge;

    /** it did not arrive whole or cannot be read (an interrupted upload, no temporary directory, a path that is not an upload) */
    case Failed;
}
