<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
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
}
