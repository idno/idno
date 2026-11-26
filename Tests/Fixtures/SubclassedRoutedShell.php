<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: what a plugin wrote against a shell whose page is routed by the legacy table, that it extended and changed nothing of
 * (a `hijackRoute()` of the shell's pattern under IDNO_TEST_CLAIM_ROUTES=routed-hijack): it is served through the shell, as a plugin's page, not by the action.
 */
class SubclassedRoutedShell extends RoutedFixtureShell
{
}
