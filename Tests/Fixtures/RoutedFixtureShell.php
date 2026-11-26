<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\MarkdownPermalink;
use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Legacy\PageShell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-environment only: a kept shell whose page is routed by the legacy table (registered by TestEventHooks with IDNO_TEST_ROUTED=1 at
 * `/_test/routed-shell/:id/.*`): its one handler hands the request to the service the controller's action calls. A Markdown permalink.
 */
class RoutedFixtureShell extends PageShell implements MarkdownPermalink
{
    protected const SHELL = self::class;

    public const ACTION = [RoutedFixtureController::class, 'showShell'];

    public function getContent()
    {
        return $this->delegate(fn (PageContext $page): Response => Idno::container()->get(RoutedFixturePages::class)->draw($page));
    }
}
