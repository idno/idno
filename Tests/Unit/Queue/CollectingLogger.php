<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it is given.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = [(string) $level, (string) $message];
    }
}
