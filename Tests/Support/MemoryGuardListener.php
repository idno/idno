<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestSuite;

/**
 * Tells `MemoryGuard` where each test class begins and ends. A PHPUnit 9 test hook runs after the class's
 * `setUpBeforeClass()` and so cannot see what that allocates; the suite events of the class bracket
 * `setUpBeforeClass()` and `tearDownAfterClass()`. Listed in `phpunit.xml` under `<listeners>`.
 */
final class MemoryGuardListener implements TestListener
{
    use TestListenerDefaultImplementation;

    public function startTestSuite(TestSuite $suite): void
    {
        MemoryGuard::suiteStarted($suite->getName());
    }

    public function endTestSuite(TestSuite $suite): void
    {
        MemoryGuard::suiteEnded($suite->getName());
    }
}
