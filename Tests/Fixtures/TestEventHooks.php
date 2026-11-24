<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\EventDispatcher;

/**
 * Test-environment only: the configurator of the legacy event dispatcher (config/services_test.php), which installs
 * the recorders and stand-ins that are inert unless their environment variable is set (EventRecorder, AuthHook).
 */
final class TestEventHooks
{
    public static function install(EventDispatcher $dispatcher): void
    {
        EventRecorder::install($dispatcher);
        AuthHook::install($dispatcher);
    }
}
