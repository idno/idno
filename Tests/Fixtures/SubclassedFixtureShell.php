<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: what a plugin wrote against a shell that it extended and changed nothing of (registered by TestEventHooks with
 * IDNO_TEST_FIXTURE_SHELL=1 at `/_test/fixture-shell-sub/`): the page is the shell's, flags and all.
 */
class SubclassedFixtureShell extends FixtureShell
{
}
