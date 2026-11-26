<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Test-environment only (config/routes/test/fixtures.php): pages served by a controller, for the tests of the page
 * lifecycle. Each answers JSON describing the current page as the legacy application sees it (read through
 * `Idno::site()->currentPage()`, which a fixture may use), or `{"site_page": false}` when the request carries no page
 * context (the lifecycle did not run). The routes follow the convention of a page's routes: `{lead}` is the optional
 * leading slash and `{slash}` the optional trailing one.
 */
#[AsController]
#[SitePage(name: 'Tests\Fixtures\SitePageFixture')]
final class SitePageFixtureController
{
    public function __construct(private readonly PageRenderer $renderer, private readonly Security $security)
    {
    }

    #[Route('/{lead}_test/site-page/state{slash}', name: 'idno_test_site_page_state', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    public function state(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    #[Route('/{lead}_test/site-page/public{slash}', name: 'idno_test_site_page_public', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', publicOnPrivateSite: true)]
    public function public(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    #[Route('/{lead}_test/site-page/account{slash}', name: 'idno_test_site_page_account', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', accountPage: true)]
    public function account(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    #[Route('/{lead}_test/site-page/typed{slash}', name: 'idno_test_site_page_typed', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', defaultType: 'json')]
    public function typed(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    #[Route('/{lead}_test/site-page/args/{word}{slash}', name: 'idno_test_site_page_args', requirements: ['lead' => '/?', 'slash' => '/?', 'word' => '[a-z]+'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    public function args(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    /** A page that declares POST: its lifecycle checks the form token before `page/post`. */
    #[Route('/{lead}_test/site-page/post{slash}', name: 'idno_test_site_page_post', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', methods: ['GET', 'POST', 'PUT'])]
    public function post(Request $request): JsonResponse
    {
        if ($request->query->get('construct') !== null) {
            // what the webmention endpoint does for a target: the legacy router builds the page registered for an address (here the shell of a page that takes
            // no form token) without serving it; who the request is must not change
            Idno::site()->routes()->getRoute('/_test/fixture-shell-tokenless/');
            $uuid = Idno::site()->session()->currentUserUUID();

            return new JsonResponse(['user' => $uuid === false ? null : $uuid, 'api' => Idno::site()->session()->isAPIRequest()]);
        }

        return $this->describe($request);
    }

    /**
     * A page that takes POST with no form token, because something else authenticates it (an exemption the guard test lists): what the
     * legacy session, the API flag and Symfony Security make of the request; `?case=` draws a page, a refusal, an error or a redirect
     * (`TokenlessCases`).
     */
    #[Route('/{lead}_test/site-page/tokenless{slash}', name: 'idno_test_site_page_tokenless', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', methods: ['GET', 'POST'], formTokenReplacedBy: 'a test of the exemption')]
    public function tokenless(Request $request): Response
    {
        if (!PageContext::has($request)) {
            return $this->describe($request);
        }

        return TokenlessCases::answer(PageContext::of($request), $this->renderer, $this->security->isGranted('ROLE_USER'));
    }

    /** A page that declares PATCH (a legacy page had none): the form token is asked for before `page/patch`. */
    #[Route('/{lead}_test/site-page/patch{slash}', name: 'idno_test_site_page_patch', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'PATCH'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', methods: ['GET', 'PATCH'])]
    public function patch(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    /** A route that lets a method through that the page never declared. */
    #[Route('/{lead}_test/site-page/undeclared{slash}', name: 'idno_test_site_page_undeclared', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'DELETE'])]
    public function undeclared(Request $request): JsonResponse
    {
        return $this->describe($request);
    }

    /**
     * Draws the page for the `case` of the request through the page renderer, as `LegacyDrawPage` does through the template object
     * (the legacy page at `draw-legacy` in each of the same directories). `SitePageDrawingTest` compares the two.
     */
    #[Route('/{lead}_test/site-page/draw-new{slash}', name: 'idno_test_site_page_draw', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    public function draw(Request $request): Response
    {
        return $this->drawCase($request);
    }

    /** A page with Symfony's `#[Cache]` attribute, which adds its own `Cache-Control` after the page's listeners. */
    #[Route('/{lead}_test/site-page/cache-attribute{slash}', name: 'idno_test_site_page_cache_attribute', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[\Symfony\Component\HttpKernel\Attribute\Cache(maxage: 60, public: true)]
    public function cacheAttribute(Request $request): Response
    {
        return $this->drawCase($request);
    }

    /**
     * A site page that only an administrator may see, by Symfony Security's attribute: its refusal is drawn as the page's own
     * `deniedContent()` is (`SitePageDrawingTest::testEveryRefusalOfASitePageIsDrawnAsALegacyPagesDeniedContent`).
     */
    #[Route('/{lead}_test/site-page/draw-granted{slash}', name: 'idno_test_site_page_draw_granted', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture')]
    #[IsGranted('ROLE_ADMIN')]
    public function drawGranted(Request $request): Response
    {
        return $this->drawCase($request);
    }

    #[Route('/{lead}admin/_test-site-page/draw-new{slash}', name: 'idno_test_site_page_draw_admin', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', settingsPage: true)]
    public function drawAdmin(Request $request): Response
    {
        return $this->drawCase($request);
    }

    #[Route('/{lead}account/_test-site-page/draw-new{slash}', name: 'idno_test_site_page_draw_account', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\SitePageFixture', accountPage: true, settingsPage: true)]
    public function drawAccount(Request $request): Response
    {
        return $this->drawCase($request);
    }

    private function drawCase(Request $request): Response
    {
        $context = PageContext::of($request);
        $renderer = $this->renderer;
        $case = (string) $context->input('case', 'draw');
        switch ($case) {
            case 'messages':
                // a message for the page this redirects to
                Idno::site()->session()->addMessage('A message to be shown once');

                return $renderer->redirect($context, $request->getSchemeAndHttpHost() . '/_test/site-page/draw-new?case=draw');
            case 'drawmessage':
                Idno::site()->session()->addMessage('A message to be shown once');
                break;
            case 'cached':
                $context->addHeader('Last-Modified', \Idno\Core\Time::timestampToRFC2616(1700000000));
                break;
            case 'robots':
                // a page that does not want to be indexed says so on its own response
                $response = $renderer->page($context, ['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $renderer->template('pages/home/blurb', ['offset' => 0])]);
                $response->headers->set('X-Robots-Tag', 'noindex');

                return $response;
            case 'response-caching':
                // caching set on the response itself, not through the page
                $response = $renderer->page($context, ['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $renderer->template('pages/home/blurb', ['offset' => 0])]);
                $response->headers->set('Cache-Control', 'public, max-age=30');
                $response->headers->set('Expires', 'Wed, 01 Jan 2031 00:00:00 GMT');

                return $response;
            case 'conditional-page':
                // through the current page, as legacy code and templates ask it (the same as LegacyDrawPage's)
                Idno::site()->currentPage()->lastModifiedGatekeeper(1700000000);
                Idno::site()->currentPage()->setLastModifiedHeader(1700000000);
                break;
            case 'conditional-missing':
                $missing = null;
                Idno::site()->currentPage()->lastModifiedGatekeeper($missing);
                break;
            case 'queue-message':
                Idno::site()->session()->addMessage('A message to be shown once');

                return new Response('queued');
            case 'conditional':
            case 'conditional-stream':
                // a page that answers a conditional request (the same as LegacyDrawPage's `conditional`, with a streamed body for the second)
                $context->answerConditionally(1700000000);
                $context->setLastModified(1700000000);
                if ($case === 'conditional-stream') {
                    return new \Symfony\Component\HttpFoundation\StreamedResponse(static function (): void {
                        $marker = getenv('IDNO_TEST_STREAM_MARKER');
                        if (is_string($marker) && $marker !== '') {
                            file_put_contents($marker, 'sent');
                        }
                        echo 'streamed';
                    });
                }
                break;
            case 'context-caching':
                // caching asked for the supported way
                $context->setCaching(['Cache-Control' => 'public, max-age=45']);
                break;
            case 'binary':
                // a file, with what a BinaryFileResponse sets by itself (`Cache-Control: public`, a `Last-Modified`)
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse(__FILE__);
            case 'binary-public':
                return (new \Symfony\Component\HttpFoundation\BinaryFileResponse(__FILE__))->setPublic();
            case 'binary-maxage':
                return (new \Symfony\Component\HttpFoundation\BinaryFileResponse(__FILE__))->setMaxAge(60);
            case 'stream':
                return new \Symfony\Component\HttpFoundation\StreamedResponse(static function (): void {
                    echo 'streamed';
                });
            // What the legacy file page (`Idno\Pages\File\View`) sent, by the supported way: a file anyone may keep; one restricted to
            // those who may read it; one for administrators only. `Last-Modified` is the entity's, through the context.
            case 'file-public':
                $context->setCaching(['Pragma' => 'public', 'Cache-Control' => 'public', 'Expires' => 'Fri, 01 Jan 2027 00:00:00 GMT']);
                $context->addHeader('Last-Modified', \Idno\Core\Time::timestampToRFC2616(1700000000));

                return new \Symfony\Component\HttpFoundation\BinaryFileResponse(__FILE__);
            case 'file-restricted':
                $context->setCaching(['Cache-Control' => 'private, no-cache']);
                $context->addHeader('Last-Modified', \Idno\Core\Time::timestampToRFC2616(1700000000));

                return new \Symfony\Component\HttpFoundation\BinaryFileResponse(__FILE__);
            case 'file-admin':
                $context->setCaching(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
                $context->addHeader('Last-Modified', \Idno\Core\Time::timestampToRFC2616(1700000000));

                return new \Symfony\Component\HttpFoundation\BinaryFileResponse(__FILE__);
            case 'robots-context':
                // and through the page's context
                $context->addHeader('X-Robots-Tag', 'noindex');
                break;
            case 'no-cache':
                Idno::site()->currentPage()->setNoCache();
                break;
            case 'permalink':
                $context->setPermalink();
                $user = Idno::site()->session()->currentUser();
                if ($user instanceof \Idno\Entities\User) {
                    $context->setOwner($user);
                }
                break;
            case 'notfound':
                return $renderer->notFound($context);
            case 'gone':
                return $renderer->gone($context);
            case 'denied':
                return $renderer->denied($context, (string) $context->input('title', ''));
            case 'error':
                return $renderer->error($context, LegacyDrawPage::brokenPage());
            case 'technical':
                return $renderer->error($context, LegacyDrawPage::technicalPage());
            // Cases that stop the page as a legacy page did (see LegacyDrawPage): by the call a legacy page made, which a controller's page
            // turns into an exception (`via=page`), or by throwing it. `SitePageDrawingTest` compares each with the legacy page's.
            case 'throw-missing':
                $this->stop($request, 'noContent', new NotFoundHttpException());
                // no break
            case 'throw-gone':
                $this->stop($request, 'goneContent', new GoneHttpException());
                // no break
            case 'throw-denied':
                $title = (string) $context->input('title', '');
                if ($context->input('via') === 'page') {
                    Idno::site()->currentPage()->deniedContent($title);
                }
                throw new AccessDeniedHttpException($title);
            case 'throw-wrapped-denied':
                // code that wraps a refusal of Symfony Security in an exception of its own
                throw new \RuntimeException('A wrapping exception with a message that is not logged', 0, new \Symfony\Component\Security\Core\Exception\AccessDeniedException());
            case 'throw-redirect':
                // what legacy code calls: the current page forwards, which throws the redirect
                Idno::site()->currentPage()->forward((string) $context->input('to', ''));

                return new Response('A forward that did not forward');
            case 'throw-runtime':
                throw LegacyDrawPage::brokenPage();
            case 'throw-technical':
                throw LegacyDrawPage::technicalPage();
            case 'throw-echo':
                echo 'Output before the exception. ';
                throw LegacyDrawPage::brokenPage();
            case 'throw-error':
                throw LegacyDrawPage::phpError();
            case 'throw-5xx':
                throw LegacyDrawPage::unavailable();
            case 'throw-5xx-retry':
                throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(120, 'Down for <b>maintenance</b>');
            case 'throw-4xx':
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('A bad request');
            case 'throw-nested':
                // An exception whose page cannot be drawn: the 500 page prints the exception (the site is in debug mode)
                throw new class('Broken, and so is its page') extends \RuntimeException {
                    public function __toString(): string
                    {
                        throw new \LogicException('The exception cannot be printed');
                    }
                };
            case 'redirect201':
                $context->setStatus(201);

                return $renderer->redirect($context, (string) $context->input('to', ''));
            case 'redirect':
                return $renderer->redirect($context, (string) $context->input('to', ''));
        }

        return $renderer->page($context, ['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $renderer->template('pages/home/blurb', ['offset' => 0])]);
    }

    /**
     * The call a legacy page made to stop (`via=page`: through the current page, which throws what a controller's page throws) or the exception itself.
     */
    private function stop(Request $request, string $method, \Throwable $exception): never
    {
        if (PageContext::of($request)->input('via') === 'page') {
            Idno::site()->currentPage()->{$method}();
        }

        throw $exception;
    }

    /**
     * Routing fixtures (IDNO_TEST_CLAIM_ROUTES, TestEventHooks): `claimable` is the path of a shell (ReplacedPage, which the legacy router
     * still registers: the attribute says `shell`) and `unclaimed` one with no shell. A page another class has been registered for (a plugin's
     * `addRoute()` or `hijackRoute()`) is served by the legacy router, the controller's route notwithstanding.
     */
    #[Route('/{lead}_test/claimable{slash}', name: 'idno_test_claimable', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: ReplacedPage::class, shell: true, markdownPermalink: true)]
    public function claimable(): Response
    {
        return new Response('controller');
    }

    #[Route('/{lead}_test/unclaimed{slash}', name: 'idno_test_unclaimed', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Tests\Fixtures\UnclaimedPage')]
    public function unclaimed(): Response
    {
        return new Response('controller');
    }

    /** A route whose last parameter may hold any byte: it is not UTF-8 only (`utf8: false`), as the legacy router took any path. */
    #[Route('/{lead}_test/site-page/bytes/{rest}{slash}', name: 'idno_test_site_page_bytes', requirements: ['lead' => '/?', 'slash' => '/?', 'rest' => '[^/]+'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'], utf8: false)]
    public function bytes(Request $request): JsonResponse
    {
        return new JsonResponse(['rest' => bin2hex((string) $request->attributes->get('rest'))]);
    }

    /**
     * A permalink with a free last parameter, which takes the `.md` suffix as the legacy pattern of a permalink did: the page says it is a
     * Markdown permalink, so the listener takes the suffix off `name` and the page is drawn in the `markdown` type.
     */
    #[Route('/{lead}_test/markdown/{name}{slash}', name: 'idno_test_markdown', requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '[^/]+'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'], utf8: false)]
    #[SitePage(name: 'Tests\Fixtures\MarkdownFixture', markdownPermalink: true)]
    public function markdown(Request $request): Response
    {
        $context = PageContext::of($request);
        $name = (string) $request->attributes->get('name');
        // what a permalink answers for a post the viewer may not read, or that is gone or never was
        match ($name) {
            'denied' => throw new AccessDeniedHttpException('Private'),
            'gone' => throw new GoneHttpException(),
            'missing' => throw new NotFoundHttpException(),
            default => null,
        };

        return $this->renderer->page($context, ['title' => 'A permalink', 'description' => 'A permalink drawn for a test.', 'body' => '<p>The name is <b>' . htmlspecialchars($name) . '</b>.</p>']);
    }

    private function describe(Request $request): JsonResponse
    {
        $data = ['site_page' => false];
        if (PageContext::has($request)) {
            $page = Idno::site()->currentPage();
            $owner = $page->getOwner();
            $data = [
                'site_page' => true,
                'current' => $page::class,
                'page_class' => $page->pageClassName(),
                'type' => Idno::site()->template()->getTemplateType(),
                'xhr' => $page->xhr(),
                'permalink' => $page->isPermalink(),
                'owner' => is_object($owner) ? $owner->getHandle() : null,
                'input_q' => $page->getInput('q'),
                'arguments' => $page->arguments,
                'method' => $request->getMethod(),
            ];
        }
        $response = new JsonResponse($data);
        if (!PageContext::has($request)) {
            // (a site page asks for caching only through its context: the session's lines are what it sends)
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
