<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: a plugin's subclass of an account shell registered OUTSIDE /account/ (by TestEventHooks with IDNO_TEST_FIXTURE_SHELL=1, at
 * `/_test/fixture-account-shell-sub/`): its page has the flags of the action, whatever route it is on.
 */
class SubclassedFixtureAccountShell extends FixtureAccountShell
{
}
