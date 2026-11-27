<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\ExactShellDrawer;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Security\StatelessAuthentication;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A drawer that records what it is asked and answers what the test says: no template is drawn.
 */
final class RecordingDrawer implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    /** @var list<array{0: array<string, mixed>, 1: int, 2: string}> */
    public array $pages = [];

    /** @var list<array{0: string, 1: string}> */
    public array $headers = [['Content-type', 'text/html'], ['Access-Control-Allow-Origin', '*']];

    public string $type = 'default';

    public bool $api = false;

    public ?int $drawnStatus = null;

    public int $finished = 0;

    public ?string $trace = null;

    /** @var list<array<int, array<string, mixed>>|null> */
    public array $frames = [];

    public function templateType(): string
    {
        return $this->type;
    }

    public function isApiRequest(): bool
    {
        return $this->api;
    }

    public function finishSession(): void
    {
        ++$this->finished;
    }

    public function forwardTrace(?array $frames): ?string
    {
        $this->frames[] = $frames;

        return $this->trace;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $name . '=' . $value;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        $this->templates[] = [$name, $vars];

        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('page:' . ($vars['title'] ?? '') . ':' . ($vars['body'] ?? ''), $this->headers, $this->drawnStatus);
    }
}

final class PageRendererTest extends TestCase
{
    private RecordingDrawer $drawer;

    private PageRenderer $renderer;

    protected function setUp(): void
    {
        $this->drawer = new RecordingDrawer();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return 'T(' . $text . ')';
            }
        };
        $this->renderer = new PageRenderer($this->drawer, $translation);
    }

    private function context(?Request $request = null): PageContext
    {
        $request ??= Request::create('/x');

        return new PageContext($request, new SitePage('Fixture\Page'));
    }

    public function testPagePutsTheDrawnHeadersOnTheResponse(): void
    {
        $response = $this->renderer->page($this->context(), ['title' => 'A', 'body' => 'B']);

        $this->assertSame('page:A:B', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html', $response->headers->get('Content-Type'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame([[['title' => 'A', 'body' => 'B'], 200, 'shell']], $this->drawer->pages);
    }

    public function testTheShellIsPassedOn(): void
    {
        $this->renderer->page($this->context(), [], 200, 'admin-shell');

        $this->assertSame('admin-shell', $this->drawer->pages[0][2]);
    }

    public function testLinkHeadersAreAppended(): void
    {
        $this->drawer->type = 'markdown';
        $this->drawer->headers = [['Content-Type', 'text/markdown; charset=utf-8'], ['Link', '<https://example.com/a>; rel="canonical"']];

        $response = $this->renderer->page($this->context(), []);

        $response->headers->set('Link', '<https://example.com/b>; rel="next"', false);
        $this->assertSame(['<https://example.com/a>; rel="canonical"', '<https://example.com/b>; rel="next"'], $response->headers->all('link'));
        $this->assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
    }

    public function testALinkDoesNotReplaceOneThatIsThere(): void
    {
        $this->drawer->headers = [['Link', '<https://example.com/a>; rel="canonical"'], ['Link', '<https://example.com/c>; rel="alternate"']];

        $response = $this->renderer->page($this->context(), []);

        $this->assertSame(['<https://example.com/a>; rel="canonical"', '<https://example.com/c>; rel="alternate"'], $response->headers->all('link'));
    }

    public function testStatusFromTheContext(): void
    {
        $context = $this->context();
        $context->setStatus(201);

        $response = $this->renderer->page($context, []);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(201, $this->drawer->pages[0][1]);
    }

    public function testAnExplicitStatusWinsAndTheContextKnowsIt(): void
    {
        $context = $this->context();
        $context->setStatus(201);

        $response = $this->renderer->page($context, [], 418);

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame(418, $context->status());
    }

    public function testTheErrorPageCanHaveAnotherServerErrorStatus(): void
    {
        $context = $this->context();

        $response = $this->renderer->error($context, new \RuntimeException('Down'), 503);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(503, $context->status());
        $this->assertSame('page:Exception:<pages/500>', $response->getContent());
        $this->assertSame(503, $this->drawer->pages[0][1]);
    }

    public function testErrorPagesHaveTheirStatusAndTitle(): void
    {
        $context = $this->context();
        $notFound = $this->renderer->notFound($context);
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame("page:T(This page can't be found.):<pages/404>", $notFound->getContent());
        $this->assertSame(404, $context->status());

        $gone = $this->renderer->gone($this->context());
        $this->assertSame(410, $gone->getStatusCode());
        $this->assertSame("page:T(This content isn't here.):<pages/410>", $gone->getContent());

        $denied = $this->renderer->denied($this->context(), 'No');
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame('page:No:<pages/403>', $denied->getContent());
        $this->assertSame('page::<pages/403>', $this->renderer->denied($this->context())->getContent());

        $exception = new \RuntimeException('Broken');
        $error = $this->renderer->error($this->context(), $exception);
        $this->assertSame(500, $error->getStatusCode());
        $this->assertSame('page:Exception:<pages/500>', $error->getContent());
        $this->assertSame(['pages/500', ['exception' => $exception]], end($this->drawer->templates));
    }

    public function testTemplateDrawsOneTemplate(): void
    {
        $this->assertSame('<some/name>', $this->renderer->template('some/name', ['a' => 1]));
        $this->assertSame([['some/name', ['a' => 1]]], $this->drawer->templates);
    }

    public function testRedirectCarriesTheTemplateType(): void
    {
        $this->drawer->type = 'json';

        $response = $this->renderer->redirect($this->context(), 'https://example.com/next');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/next?_t=json', $response->headers->get('Location'));
    }

    public function testRedirectToADefaultTypeAddsNothingAndKeepsTheStatus(): void
    {
        $response = $this->renderer->redirect($this->context(), 'https://example.com/next', 307);

        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('https://example.com/next', $response->headers->get('Location'));
    }

    public function testRedirectForAnApiRequestIsJson(): void
    {
        // the session says so (a stateless credential that left the request anonymous too): no request attribute is asked
        $this->drawer->api = true;

        $response = $this->renderer->redirect($this->context(), 'https://example.com/next');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Location'));
        $this->assertSame('{"location":"https:\/\/example.com\/next"}', $response->getContent());
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testTheApiAnswerKeepsThePagesStatusAndCarriesTheTemplateType(): void
    {
        $this->drawer->api = true;
        $this->drawer->type = 'json';
        $context = $this->context();
        $context->setStatus(201);

        $response = $this->renderer->redirect($context, 'https://example.com/next');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"location":"https:\/\/example.com\/next?_t=json"}', $response->getContent());
    }

    public function testAForwardThatEndsThePageClosesTheSessionAndOneThatGoesOnDoesNot(): void
    {
        $this->renderer->redirect($this->context(), 'https://example.com/a');
        $this->assertSame(1, $this->drawer->finished);

        $redirect = $this->renderer->forward($this->context(), 'https://example.com/a', false);
        $this->assertInstanceOf(PageRedirect::class, $redirect);
        $this->assertSame(1, $this->drawer->finished);
    }

    public function testAnXhrPostCannotForwardAndForwardsNothing(): void
    {
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $request = Request::create('/x', $method);
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');

            $this->assertNull($this->renderer->forward($this->context($request), 'https://example.com/a'), $method);
        }
        $this->assertSame(0, $this->drawer->finished);
        $this->assertInstanceOf(PageRedirect::class, $this->renderer->forward($this->context(Request::create('/x', 'POST')), 'https://example.com/a'));
        $xhrGet = Request::create('/x');
        $xhrGet->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->assertInstanceOf(PageRedirect::class, $this->renderer->forward($this->context($xhrGet), 'https://example.com/a'));
        $response = $this->renderer->redirect($this->context(Request::create('/x', 'PUT', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])), 'https://example.com/a');
        $this->assertFalse($response->headers->has('Location'), 'the page goes on');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheForwardTraceIsAHeaderOrPartOfTheApiAnswer(): void
    {
        $this->drawer->trace = 'Forward at a.php:1, called by f in b.php:2';
        $context = $this->context();
        $this->renderer->redirect($context, 'https://example.com/a');
        $this->assertSame(['X-Idno-Forward-Trace' => ['Forward at a.php:1, called by f in b.php:2']], $context->headers());

        $this->drawer->api = true;
        $response = $this->renderer->redirect($this->context(), 'https://example.com/a');
        $this->assertSame(['location' => 'https://example.com/a', 'trace' => 'Forward at a.php:1, called by f in b.php:2'], json_decode((string) $response->getContent(), true));
    }

    public function testAThrownRedirectBecomesTheResponseWithoutDerivingItsLocationAgain(): void
    {
        $this->drawer->type = 'json';
        $this->drawer->api = false;

        $redirect = (new PageRedirect('https://example.com/already?_t=json', null, 307))->toResponse();
        $this->assertSame(307, $redirect->getStatusCode());
        $this->assertSame('https://example.com/already?_t=json', $redirect->headers->get('Location'));
        $this->assertSame('', $redirect->getContent());

        $json = (new PageRedirect('https://example.com/x', '{"location":"x","trace":"t"}', 200))->toResponse();
        $this->assertSame(200, $json->getStatusCode());
        $this->assertSame('{"location":"x","trace":"t"}', $json->getContent());
        $this->assertFalse($json->headers->has('Location'));
        $this->assertStringStartsWith('application/json', (string) $json->headers->get('Content-Type'));
    }

    public function testAForwardKeepsTheStatusThePageSetThatPhpWouldKeep(): void
    {
        // a legacy page that had set 201 and forwarded sent 201 with its Location; any other status of a page became a 302
        foreach ([[201, 201], [307, 307], [200, 302], [404, 302], [403, 302]] as [$page, $sent]) {
            $context = $this->context();
            $context->setStatus($page);

            $response = $this->renderer->redirect($context, 'https://example.com/next');

            $this->assertSame($sent, $response->getStatusCode(), 'a page at ' . $page);
            $this->assertSame('https://example.com/next', $response->headers->get('Location'));
        }
    }

    public function testAStatusTheDrawSetIsTheResponsesAndTheContextKnowsIt(): void
    {
        $this->drawer->drawnStatus = 203;
        $context = $this->context();

        $response = $this->renderer->page($context, []);

        $this->assertSame(203, $response->getStatusCode());
        $this->assertSame(203, $context->status());
    }

    public function testALocationTheDrawSentIsAnsweredLikePhpAnswersIt(): void
    {
        $this->drawer->headers = [['Content-type', 'text/html'], ['Location', 'https://example.com/moved', true]];
        $moved = $this->renderer->page($this->context(), []);
        $this->assertSame(302, $moved->getStatusCode(), 'a 200 with a Location is a 302');
        $this->assertSame('https://example.com/moved', $moved->headers->get('Location'));

        $this->drawer->drawnStatus = 201;
        $created = $this->renderer->page($this->context(), []);
        $this->assertSame(201, $created->getStatusCode());

        $this->drawer->drawnStatus = null;
        $this->drawer->headers = [['Content-type', 'text/html']];
        $this->assertSame(200, $this->renderer->page($this->context(), [])->getStatusCode());
    }

    public function testPageSetsTheLengthInBytes(): void
    {
        $this->drawer->headers = [];

        $response = $this->renderer->page($this->context(), ['title' => 'é', 'body' => 'ü']);

        $this->assertSame((string) strlen('page:é:ü'), $response->headers->get('Content-Length'));
        $this->assertSame('10', $response->headers->get('Content-Length'));
    }

    public function testAHeaderThatCarriesItsOwnReplaceFlagIsAddedOrReplaced(): void
    {
        $this->drawer->headers = [['Access-Control-Allow-Origin', '*'], ['Vary', 'Accept'], ['Access-Control-Allow-Origin', 'https://x.example', true], ['Vary', 'Cookie', false], ['Link', '<a>; rel="x"']];

        $response = $this->renderer->page($this->context(), []);

        $this->assertSame(['https://x.example'], $response->headers->all('access-control-allow-origin'));
        $this->assertSame(['Accept', 'Cookie'], $response->headers->all('vary'));
        $this->assertSame(['<a>; rel="x"'], $response->headers->all('link'));
    }

    public function testRedirectToTheDisplayUrlByDefault(): void
    {
        $response = $this->renderer->redirect($this->context());

        $this->assertSame('https://example.com/', $response->headers->get('Location'));
    }

    /**
     * Catches: a page under /account/ that a visitor sees (the registration page) drawn by the ordinary drawer, which puts an address under /account/ in
     * the settings shell meant for members, or answered with a length the page it replaced never sent.
     */
    public function testAnExactShellIsDrawnByTheExactDrawerAndSaysNoLength(): void
    {
        $exact = new class implements ExactShellDrawer {
            /** @var list<array{0: array<string, mixed>, 1: int, 2: string}> */
            public array $pages = [];

            public function drawPageInExactShell(array $vars, int $status, string $shell): DrawnPage
            {
                $this->pages[] = [$vars, $status, $shell];

                return new DrawnPage('exact', [['Access-Control-Allow-Origin', '*']]);
            }

            public function drawTemplateOnACopy(string $name, array $vars): string
            {
                return 'copy:' . $name . ':' . json_encode($vars);
            }
        };
        $renderer = new PageRenderer($this->drawer, new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        }, $exact);

        $response = $renderer->pageInExactShell($this->context(), ['title' => 'A'], 403);

        $this->assertSame('exact', $response->getContent());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame([[['title' => 'A'], 403, 'shell']], $exact->pages);
        $this->assertSame([], $this->drawer->pages, 'the ordinary drawer drew nothing');
        $this->assertSame('copy:x:{"a":1}', $renderer->templateOnACopy('x', ['a' => 1]));
    }

    /**
     * Catches: a renderer built without the exact drawer quietly drawing a registration page in the ordinary shell: it refuses.
     */
    public function testWithoutAnExactShellDrawerThePageIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->renderer->pageInExactShell($this->context(), []);
    }
}
