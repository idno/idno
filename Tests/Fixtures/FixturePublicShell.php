<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Legacy\PageShell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-environment only (registered by TestEventHooks with IDNO_TEST_FIXTURE_SHELL=1 at `/_test/fixture-shell-public/`, NOT as a public route): the shell of a page whose action is served to a visitor of a private site.
 */
class FixturePublicShell extends PageShell
{
    protected const SHELL = self::class;

    public const ACTION = [FixtureShellController::class, 'showPublic'];

    public function getContent()
    {
        return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(FixtureShellPages::class)->draw($page));
    }
}
