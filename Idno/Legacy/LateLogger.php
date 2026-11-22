<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A logger that fetches the logger it writes through when the first line is written: the legacy
 * services that are built before the site's logger exists (the queue) log through it.
 */
final class LateLogger extends AbstractLogger
{
    private ?LoggerInterface $logger = null;

    /**
     * @param \Closure(): LoggerInterface $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        ($this->logger ??= ($this->factory)())->log($level, $message, $context);
    }
}
