<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\PageRedirect;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Test-environment only: the one implementation of the fixture page that a controller (`FixtureShellController`) and a shell
 * (`FixtureShell`) both serve, for each `case` of the request:
 *  - `conditional`: the page answers a conditional request (last changed at 1700000000: a current copy stops it with a 304) and sends that as its `Last-Modified`;
 *  - `stream`: a streamed response that writes the file `IDNO_TEST_STREAM_MARKER` names (when the stream is sent) and prints `streamed`;
 *  - `denied`, `notfound`, `gone` and `redirect`: the refusal, the missing page, the gone page and the forward that a page throws;
 *  - `file`: a file response of the file `IDNO_TEST_SHELL_FILE` names (as `application/octet-stream`);
 *  - any other: a drawn page that prints what the context holds.
 */
final class FixtureShellPages
{
    /** The time the page says it last changed. */
    public const CHANGED = 1700000000;

    public function __construct(private readonly PageRenderer $renderer, private readonly Security $security)
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
        if ($case === 'denied') {
            throw new AccessDeniedHttpException('Not for you');
        }
        if ($case === 'notfound') {
            throw new NotFoundHttpException();
        }
        if ($case === 'gone') {
            throw new GoneHttpException();
        }
        if ($case === 'redirect') {
            throw new PageRedirect('/_test/fixture-shell-target');
        }
        if ($case === 'file') {
            $response = new BinaryFileResponse((string) getenv('IDNO_TEST_SHELL_FILE'));
            $response->headers->set('Content-Type', 'application/octet-stream');

            return $response;
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
        return TokenlessCases::answer($page, $this->renderer, $this->security->isGranted('ROLE_USER'));
    }
}
