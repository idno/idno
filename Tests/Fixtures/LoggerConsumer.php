<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Psr\Log\LoggerInterface;

/**
 * Test-environment only (config/services_test.php): an autowired service with a
 * LoggerInterface constructor argument, to prove which logger autowiring provides.
 */
final class LoggerConsumer
{
    public function __construct(public readonly LoggerInterface $logger)
    {
    }
}
