<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Writes a record's message exactly as given.
 *
 * Idno\Core\Logging builds the complete log line itself, and error_log() used to write it
 * byte for byte. Monolog's LineFormatter would re-substitute placeholders such as
 * %channel% inside the message, so this formatter does no transformation at all.
 */
final class VerbatimLogFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        return $record->message;
    }

    public function formatBatch(array $records): string
    {
        $output = '';
        foreach ($records as $record) {
            $output .= $this->format($record);
        }

        return $output;
    }
}
