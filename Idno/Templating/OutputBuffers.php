<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * Ends output buffers, for code that is already handling an exception and must neither loop nor throw.
 */
final class OutputBuffers
{
    /**
     * Ends the output buffers above $level (the innermost first), discarding what they hold. A buffer PHP cannot remove
     * (one opened without the flag PHP_OUTPUT_HANDLER_REMOVABLE, which is all `ob_end_clean()` needs) is left as it is, and so is everything below it: the
     * closing stops there. Nothing is thrown or raised: a handler that throws when its buffer is ended, or an error handler
     * that turns a notice into an exception, ends the closing too, so the exception being handled is not replaced.
     */
    public static function endDownTo(int $level): void
    {
        while (ob_get_level() > $level) {
            $before = ob_get_level();
            $status = ob_get_status(true);
            $top = end($status);
            $flags = is_array($top) ? (int) ($top['flags'] ?? 0) : 0;
            if (($flags & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                return;
            }
            try {
                ob_end_clean();
            } catch (\Throwable) {
                return;
            }
            if (ob_get_level() >= $before) {
                return;
            }
        }
    }
}
