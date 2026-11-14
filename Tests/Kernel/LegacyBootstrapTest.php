<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The legacy application is a process-wide singleton: once booted, booting it again
 * from another kernel changes nothing.
 */
final class LegacyBootstrapTest extends TestCase
{
    public function testSecondKernelLeavesTheBootedLegacyApplicationInPlace(): void
    {
        // Tests/_bootstrap.php has already booted a kernel with the legacy application.
        $site = Idno::site();
        $container = Idno::container();
        $this->assertNotNull($site);

        $raised = [];
        set_error_handler(static function (int $type, string $message) use (&$raised): bool {
            if (error_reporting() & $type) { // Not silenced with @
                $raised[] = $message;
            }

            return true;
        });
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
        } finally {
            restore_error_handler();
            $kernel->shutdown();
        }

        $this->assertSame($site, Idno::site());
        $this->assertSame($container, Idno::container());
        $this->assertSame([], $raised);
    }
}
