<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Test-environment only: the one implementation of the fixture page that a controller (`FixtureShellController`) and a shell
 * (`FixtureShell`) both serve, for each `case` of the request:
 *  - `conditional`: the page answers a conditional request (last changed at 1700000000: a current copy stops it with a 304) and sends that as its `Last-Modified`;
 *  - `stream`: a streamed response that writes the file `IDNO_TEST_STREAM_MARKER` names (when the stream is sent) and prints `streamed`;
 *  - any other: a drawn page that prints what the context holds.
 */
final class FixtureShellPages
{
    /** The time the page says it last changed. */
    public const CHANGED = 1700000000;

    public function __construct(private readonly PageRenderer $renderer)
    {
    }

    public function draw(PageContext $page): Response
    {
        $case = (string) $page->input('case', '');
        if ($case === 'stream') {
            return new StreamedResponse(static function (): void {
                $marker = getenv('IDNO_TEST_STREAM_MARKER');
                if (is_string($marker) && $marker !== '') {
                    file_put_contents($marker, 'sent');
                }
                echo 'streamed';
            });
        }
        if ($case === 'conditional') {
            $page->answerConditionally(self::CHANGED);
            $page->setLastModified(self::CHANGED);

            return $this->renderer->page($page, ['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $this->renderer->template('pages/home/blurb', ['offset' => 0])]);
        }

        return $this->renderer->page($page, [
            'title' => 'A drawn page',
            'description' => 'A page drawn for a test.',
            'body' => '<p>permalink=' . ($page->isPermalink() ? 'yes' : 'no') . ' status=' . $page->status() . '</p>',
        ]);
    }

    /**
     * The page that takes a request with no form token: who the legacy session says made it.
     */
    public function receive(PageContext $page): Response
    {
        return TokenlessCases::answer($page, $this->renderer);
    }
}
