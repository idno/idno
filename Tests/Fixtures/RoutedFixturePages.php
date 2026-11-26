<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-environment only: the one implementation of the pages routed by the legacy table that a controller (`RoutedFixtureController`) and a shell
 * (`RoutedFixtureShell`) both serve: a page that lists the arguments the action received and whether the Markdown form was asked.
 */
final class RoutedFixturePages
{
    public function __construct(private readonly PageRenderer $renderer)
    {
    }

    public function draw(PageContext $page): Response
    {
        return $this->renderer->page($page, [
            'title' => 'A routed page',
            'description' => 'A page routed by the legacy table.',
            'body' => '<p>arguments=' . htmlspecialchars(json_encode($page->arguments(), JSON_THROW_ON_ERROR), ENT_QUOTES) . ' markdown=' . ($page->markdownSuffixRequested() ? 'yes' : 'no') . '</p>',
        ]);
    }
}
