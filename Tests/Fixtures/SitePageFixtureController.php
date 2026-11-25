<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

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
    public function __construct(private readonly PageRenderer $renderer)
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
            case 'redirect201':
                $context->setStatus(201);

                return $renderer->redirect($context, (string) $context->input('to', ''));
            case 'redirect':
                return $renderer->redirect($context, (string) $context->input('to', ''));
        }

        return $renderer->page($context, ['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => $renderer->template('pages/home/blurb', ['offset' => 0])]);
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
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
