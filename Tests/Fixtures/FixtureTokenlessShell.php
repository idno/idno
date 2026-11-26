<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Legacy\PageShell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-environment only (registered by TestEventHooks with IDNO_TEST_FIXTURE_SHELL=1 at `/_test/fixture-shell-tokenless/`): the shell of a page
 * whose action takes a POST with no form token.
 */
class FixtureTokenlessShell extends PageShell
{
    protected const SHELL = self::class;

    public const ACTION = [FixtureShellController::class, 'receive'];

    public function getContent()
    {
        return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(FixtureShellPages::class)->receive($page));
    }

    public function postContent()
    {
        return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(FixtureShellPages::class)->receive($page));
    }
}
