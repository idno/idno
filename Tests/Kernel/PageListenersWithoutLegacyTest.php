<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use Idno\Legacy\LegacyDispatcher;
use Idno\Legacy\LegacyPageLifecycle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The listeners that run around the pages controllers serve do nothing in a kernel that did not boot the legacy
 * application (a `bootLegacy: false` kernel, the schema gate closed): they build nothing that needs it, replace no PHP
 * error handler and leave every other route, the upgrade step included, as it was.
 */
final class PageListenersWithoutLegacyTest extends TestCase
{
    private function kernel(): Kernel
    {
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();

        return $kernel;
    }

    private static function errorHandler(): mixed
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    public function testThePageListenerIsInertWithoutTheLegacyApplication(): void
    {
        $kernel = $this->kernel();
        $handler = self::errorHandler();

        $ping = $kernel->handle(Request::create('/_test/ping'));
        $page = $kernel->handle(Request::create('/_test/site-page/state'));
        $options = $kernel->handle(Request::create('/_test/site-page/state', 'OPTIONS'));

        $this->assertFalse($kernel->legacyBooted());
        $this->assertSame(200, $ping->getStatusCode());
        $this->assertSame(200, $page->getStatusCode(), 'the fixture route answers');
        $this->assertSame(['site_page' => false], json_decode((string) $page->getContent(), true), 'with no page context: the lifecycle did not run');
        $this->assertNull($page->headers->get('X-Clacks-Overhead'), 'no standard header: the lifecycle did not run');
        $this->assertSame(200, $options->getStatusCode(), 'an OPTIONS request is not answered as a page\'s either');
        $this->assertFalse($kernel->getContainer()->initialized(LegacyPageLifecycle::class), 'the lifecycle was never built');
        $this->assertSame($handler, self::errorHandler(), 'no PHP error handler was installed');
        $kernel->shutdown();
    }

    public function testAnExemptPagesPostBuildsNoLifecycleWithoutTheLegacyApplication(): void
    {
        $kernel = $this->kernel();
        $handler = self::errorHandler();

        $page = $kernel->handle(Request::create('/_test/site-page/tokenless', 'POST'));

        $this->assertFalse($kernel->legacyBooted());
        $this->assertSame(200, $page->getStatusCode());
        $this->assertSame(['site_page' => false], json_decode((string) $page->getContent(), true), 'no page context: the lifecycle did not run');
        $this->assertFalse($kernel->getContainer()->initialized(LegacyPageLifecycle::class));
        $this->assertSame($handler, self::errorHandler());
        $kernel->shutdown();
    }

    public function testTheHeadersOfEveryOtherResponseAreExactlyWhatTheyWere(): void
    {
        $kernel = $this->kernel();

        $step = $kernel->handle(Request::create('/_upgrade/step', 'POST'));
        $ping = $kernel->handle(Request::create('/_test/ping'));
        $page = $kernel->handle(Request::create('/_test/site-page/state'));

        // neither the caching rule of a site page nor the removal of the tag Symfony adds in debug mode reaches them
        $this->assertSame('no-store, private', $step->headers->get('Cache-Control'));
        $this->assertSame('noindex', $step->headers->get('X-Robots-Tag'));
        $this->assertSame('no-cache, private', $ping->headers->get('Cache-Control'));
        $this->assertSame('noindex', $ping->headers->get('X-Robots-Tag'));
        $this->assertSame('no-store, private', $page->headers->get('Cache-Control'), 'a fixture route that is a site page, in a kernel with no legacy application: no page context');
        $this->assertSame('noindex', $page->headers->get('X-Robots-Tag'));
        $this->assertFalse($kernel->getContainer()->initialized(LegacyPageLifecycle::class));
        $kernel->shutdown();
    }

    public function testTheRouteOverrideLeavesEveryRouteAloneWithoutTheLegacyApplication(): void
    {
        $kernel = $this->kernel();
        $handler = self::errorHandler();

        // a path a legacy page could have been registered for, a path that is not a site page's, the upgrade step, and a path the catch-all serves
        $claimable = $kernel->handle(Request::create('/_test/claimable'));
        $unclaimed = $kernel->handle(Request::create('/_test/unclaimed/'));
        $doubled = $kernel->handle(new Request([], [], [], [], [], ['REQUEST_URI' => '//_test/unclaimed', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost']));
        $ping = $kernel->handle(Request::create('/_test/ping'));
        $step = $kernel->handle(Request::create('/_upgrade/step', 'POST'));

        $this->assertFalse($kernel->legacyBooted());
        $this->assertSame('controller', $claimable->getContent(), 'the controller\'s route stands: there is no legacy router to prefer');
        $this->assertSame('controller', $unclaimed->getContent());
        $this->assertSame('controller', $doubled->getContent());
        $this->assertSame(200, $ping->getStatusCode());
        $this->assertSame(200, $step->getStatusCode());
        $this->assertFalse($kernel->getContainer()->initialized(LegacyDispatcher::class), 'the legacy router\'s dispatcher (its routes, its configuration) was never built');
        $this->assertSame($handler, self::errorHandler(), 'no PHP error handler was installed');
        $kernel->shutdown();
    }

    public function testTheUpgradeStepIsUntouched(): void
    {
        $kernel = $this->kernel();
        $handler = self::errorHandler();

        $response = $kernel->handle(Request::create('/_upgrade/step', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('current', json_decode((string) $response->getContent(), true)['status']);
        $this->assertFalse($kernel->getContainer()->initialized(LegacyPageLifecycle::class));
        $this->assertSame($handler, self::errorHandler());
        $kernel->shutdown();
    }

    public function testAnExceptionOutsideASitePageBuildsNothingLegacy(): void
    {
        $kernel = $this->kernel();
        $handler = self::errorHandler();
        // Symfony logs what it handles (to PHP's error log): keep it out of the test output
        $log = (string) tempnam(sys_get_temp_dir(), 'idno-listener-log-');
        $previousLog = ini_set('error_log', $log);

        try {
            $thrown = $kernel->handle(Request::create('/_test/throw'));
            // (the fixture page's controller takes the renderer, so it is built once that page is asked for: look now)
            $built = $kernel->getContainer()->initialized(\Idno\Http\Page\PageRenderer::class);
            $page = $kernel->handle(Request::create('/_test/site-page/state'));
        } finally {
            ini_set('error_log', (string) $previousLog);
            $lines = (string) file_get_contents($log);
            unlink($log);
        }

        $this->assertSame(500, $thrown->getStatusCode(), 'as KernelExceptionTest pins: Symfony answers it');
        $this->assertSame(1, substr_count($lines, 'request.CRITICAL'), 'and logs it, once: ' . $lines);
        $this->assertSame(200, $page->getStatusCode());
        $this->assertFalse($kernel->legacyBooted());
        $this->assertFalse($built, 'no renderer was built for an exception that is not a site page\'s');
        $this->assertFalse($kernel->getContainer()->initialized(LegacyPageLifecycle::class), 'no lifecycle was built');
        $this->assertSame($handler, self::errorHandler(), 'no PHP error handler was installed');
        $kernel->shutdown();
    }

    public function testTheHomePageServiceIsBuiltWithoutTheLegacyApplication(): void
    {
        $kernel = $this->kernel();

        // (it takes the site's logger, which needs the legacy application, only when it logs)
        $pages = $kernel->getContainer()->get(\Idno\Http\Controller\Home\HomePages::class);

        $this->assertInstanceOf(\Idno\Http\Controller\Home\HomePages::class, $pages);
        $this->assertFalse($kernel->legacyBooted());
        $kernel->shutdown();
    }
}
