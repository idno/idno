<?php

declare(strict_types=1);

namespace Idno\Exceptions;

/**
 * The database could not be written to in time: another process held its lock (SQLite's write lock, a row lock on MySQL or MariaDB) for longer
 * than the wait, or the server picked this statement as the loser of a deadlock. Nothing was changed. A page that gets one says to try again; it
 * never reports it as a refusal of what was asked.
 */
final class DatabaseBusyException extends \RuntimeException implements Friendly
{
}
