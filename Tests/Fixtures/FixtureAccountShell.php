<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Legacy\PageShell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-environment only (registered by TestEventHooks with IDNO_TEST_FIXTURE_SHELL=1 at `/account/_test-fixture-shell/`): a shell of a page
 * under /account/, whose controller action says it is an account and settings page.
 */
class FixtureAccountShell extends PageShell
{
    protected const SHELL = self::class;

    public const ACTION = [FixtureShellController::class, 'showAccount'];

    public function getContent()
    {
        return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(FixtureShellPages::class)->draw($page));
    }
}
