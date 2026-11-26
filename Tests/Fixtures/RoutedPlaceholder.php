<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: the placeholder of a page routed by the legacy table whose class does not exist (as `Idno\Legacy\Placeholder\AnnotationView` is
 * for core's): it names the action that serves the page. Registered by TestEventHooks with IDNO_TEST_ROUTED=1.
 */
final class RoutedPlaceholder
{
    public const ACTION = [RoutedFixtureController::class, 'show'];
}
