<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\MarkdownPermalink;
use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Legacy\PageShell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-environment only (registered by TestEventHooks with IDNO_TEST_FIXTURE_SHELL=1 at `/_test/fixture-shell/`): a shell, as the deprecated
 * classes of core's moved pages are, whose one handler hands the request to the service `FixtureShellPages` that the controller
 * `FixtureShellController` calls too. Its page is a Markdown permalink (`/_test/fixture-shell.md` is routed to it).
 */
class FixtureShell extends PageShell implements MarkdownPermalink
{
    protected const SHELL = self::class;

    public const ACTION = [FixtureShellController::class, 'show'];

    public function getContent()
    {
        return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(FixtureShellPages::class)->draw($page));
    }
}
