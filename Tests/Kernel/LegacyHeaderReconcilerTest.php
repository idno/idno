<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRequestListener;
use Idno\Http\Page\SitePage;
use Idno\Kernel;
use Idno\Legacy\HeaderQueue;
use Idno\Legacy\LegacyHeaderReconciler;
use Idno\Legacy\LegacyResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A recording stand-in for PHP's header list: the lines `header()` holds, what is removed and what is sent, in order.
 */
final class RecordingHeaderQueue implements HeaderQueue
{
    /** @var list<string> */
    public array $held;

    /** @var list<string> what was asked of it, in order */
    public array $calls = [];

    /** @param list<string> $held */
    public function __construct(array $held = [], private readonly bool $listed = true)
    {
        $this->held = $held;
    }

    public function listed(): bool
    {
        return $this->listed;
    }

    public function queued(): array
    {
        $this->calls[] = 'queued';

        return $this->held;
    }

    public function remove(string $name): void
    {
        $this->calls[] = 'remove ' . $name;
        $this->held = array_values(array_filter($this->held, static fn (string $line): bool => strcasecmp(strstr($line, ':', true) ?: '', $name) !== 0));
    }

    public function send(string $line, bool $replace): void
    {
        $this->calls[] = ($replace ? 'replace ' : 'add ') . $line;
        if ($replace) {
            $name = strstr($line, ':', true) ?: '';
            $this->held = array_values(array_filter($this->held, static fn (string $held): bool => strcasecmp(strstr($held, ':', true) ?: '', $name) !== 0));
        }
        $this->held[] = $line;
    }
}

#[SitePage('Fixture\ReconcilerPage')]
final class ReconcilerPageController
{
    public function page(): Response
    {
        return new Response('page');
    }
}

/**
 * The rule for the headers of a site page that a controller serves, as one place applies it (Idno\Legacy\LegacyHeaderReconciler): the
 * session's caching lines, queued by PHP, are the page's (a Response computes a `Cache-Control` of its own and a `Date`, and Symfony sends
 * every header beside PHP's); a page that asked for caching sends exactly the lines it asked for; `Last-Modified` and `Content-Type` are the
 * response's; everything else (`Link`, `Vary`, `X-*`, `Date`, `Set-Cookie`) is left as it is.
 */
final class LegacyHeaderReconcilerTest extends TestCase
{
    private const SESSION = [
        'Set-Cookie: sid=1; path=/',
        'Expires: Thu, 19 Nov 1981 08:52:00 GMT',
        'Cache-Control: no-store, no-cache, must-revalidate',
        'Pragma: no-cache',
    ];

    private function kernel(bool $legacyBooted = true): Kernel
    {
        return new class('test', true, false, $legacyBooted) extends Kernel {
            public function __construct(string $environment, bool $debug, bool $bootLegacy, private readonly bool $legacyIsBooted)
            {
                parent::__construct($environment, $debug, $bootLegacy);
            }

            public function legacyBooted(): bool
            {
                return $this->legacyIsBooted;
            }
        };
    }

    private function context(?Request $request = null): PageContext
    {
        $request ??= Request::create('/');
        $context = new PageContext($request, new SitePage('Fixture\ReconcilerPage'));
        $context->attachTo($request);

        return $context;
    }

    private function event(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response);
    }

    public function testWithoutTakingOverCachingTheResponseDropsItsCacheControlPragmaAndExpires(): void
    {
        $queue = new RecordingHeaderQueue(self::SESSION);
        $response = new Response('x', 200, ['Pragma' => 'no-cache', 'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT']);
        $response->headers->set('Last-Modified', 'Tue, 14 Nov 2023 22:13:20 GMT');
        $this->assertTrue($response->headers->has('Cache-Control'), 'the response computes one of its own (and adds `private`)');

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $this->context(), false);

        $this->assertFalse($response->headers->has('Cache-Control'));
        $this->assertFalse($response->headers->has('Pragma'));
        $this->assertFalse($response->headers->has('Expires'));
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->headers->get('Last-Modified'));
        $this->assertSame(self::SESSION, $queue->held, 'PHP\'s lines are sent as PHP queued them');
        $this->assertNotContains('remove Cache-Control', $queue->calls);
        $this->assertSame([], array_filter($queue->calls, static fn (string $call): bool => $call !== 'queued'), 'nothing was removed or sent');
    }

    public function testTakingOverCachingRemovesPhpsLines(): void
    {
        $queue = new RecordingHeaderQueue(self::SESSION);
        $context = $this->context();
        $context->setCaching(['Cache-Control' => ['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'], 'Pragma' => 'no-cache']);
        $response = new Response('x');

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $context, false);

        // the names the page set are its own lines, exactly, in order; the one it did not (`Expires`) is PHP's
        $this->assertSame([
            'Set-Cookie: sid=1; path=/',
            'Expires: Thu, 19 Nov 1981 08:52:00 GMT',
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
            'Cache-Control: post-check=0, pre-check=0',
            'Pragma: no-cache',
        ], $queue->held);
        $this->assertSame(
            ['remove Cache-Control', 'replace Cache-Control: no-store, no-cache, must-revalidate, max-age=0', 'add Cache-Control: post-check=0, pre-check=0', 'remove Pragma', 'replace Pragma: no-cache'],
            array_values(array_filter($queue->calls, static fn (string $call): bool => $call !== 'queued'))
        );
        $this->assertFalse($response->headers->has('Cache-Control'), 'never the response\'s own computed one');
        $this->assertFalse($response->headers->has('Pragma'));
    }

    public function testWherePhpKeepsNoListThePagesCachingStaysOnTheResponse(): void
    {
        // the command line has no header list: a response is all there is to carry what the page asked for
        $queue = new RecordingHeaderQueue([], false);
        $context = $this->context();
        $context->setCaching(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Expires' => 'Mon, 26 Jul 1997 05:00:00 GMT']);
        $response = new Response('x');

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $context, false);

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('Mon, 26 Jul 1997 05:00:00 GMT', $response->headers->get('Expires'));
        $this->assertSame([], $queue->calls, 'PHP was not asked for anything');
    }

    public function testAQueuedLastModifiedGivesWayToTheResponses(): void
    {
        $queue = new RecordingHeaderQueue([...self::SESSION, 'Last-Modified: Wed, 01 Jan 2020 00:00:00 GMT']);
        $response = new Response('x');
        $response->headers->set('Last-Modified', 'Tue, 14 Nov 2023 22:13:20 GMT');

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $this->context(), false);

        $this->assertContains('remove Last-Modified', $queue->calls);
        $this->assertNotContains('Last-Modified: Wed, 01 Jan 2020 00:00:00 GMT', $queue->held);
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->headers->get('Last-Modified'));

        // a response with none leaves PHP's
        $queue = new RecordingHeaderQueue([...self::SESSION, 'Last-Modified: Wed, 01 Jan 2020 00:00:00 GMT']);
        $bare = new Response('x');
        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($bare, $this->context(), false);
        $this->assertContains('Last-Modified: Wed, 01 Jan 2020 00:00:00 GMT', $queue->held);
        $this->assertFalse($bare->headers->has('Last-Modified'));
    }

    public function testAThirdPartyTypesContentTypeIsCopied(): void
    {
        // A third-party template type has no declared headers: its shell sent its own `Content-Type`, which a response with none would
        // replace by `text/html` when it is prepared
        $queue = new RecordingHeaderQueue([...self::SESSION, 'Content-Type: application/x-third-party']);
        $response = new Response('x');
        $response->headers->remove('Content-Type');

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $this->context(), false);

        $this->assertSame('application/x-third-party', $response->headers->get('Content-Type'));
        $this->assertNotContains('Content-Type: application/x-third-party', $queue->held);

        // a response that has one, or a type that is known, is left as it is
        $queue = new RecordingHeaderQueue([...self::SESSION, 'Content-Type: application/x-third-party']);
        $own = new Response('x', 200, ['Content-Type' => 'application/json']);
        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($own, $this->context(), false);
        $this->assertSame('application/json', $own->headers->get('Content-Type'));
        $known = new Response('x');
        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($known, $this->context(), true);
        $this->assertFalse($known->headers->has('Content-Type'));
        $this->assertContains('Content-Type: application/x-third-party', $queue->held);
    }

    public function testLinkVaryAndXHeadersAreLeftToPhp(): void
    {
        $held = [...self::SESSION, 'Link: <https://example.com/a>; rel="a"', 'Vary: Cookie', 'X-Powered-By: https://idno.co', 'X-Fixture: 1'];
        $queue = new RecordingHeaderQueue($held);
        $response = new Response('x', 200, ['Link' => '<https://example.com/b>; rel="b"', 'Vary' => 'Accept', 'X-Fixture' => '2']);

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $this->context(), false);

        $this->assertSame($held, $queue->held, 'PHP\'s lines are untouched');
        $this->assertSame('<https://example.com/b>; rel="b"', $response->headers->get('Link'));
        $this->assertSame('Accept', $response->headers->get('Vary'));
        $this->assertSame('2', $response->headers->get('X-Fixture'), 'and the response sends its own beside them');
    }

    public function testDateAndSetCookieAreNeverTouched(): void
    {
        $queue = new RecordingHeaderQueue(self::SESSION);
        $context = $this->context();
        $context->setCaching(['Cache-Control' => 'no-store']);
        $response = new Response('x');
        $response->headers->set('Date', 'Tue, 14 Nov 2023 22:13:20 GMT');
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('a', 'b'));

        (new LegacyHeaderReconciler($this->kernel(), $queue))->reconcile($response, $context, false);

        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $response->headers->get('Date'));
        $this->assertCount(1, $response->headers->getCookies());
        $this->assertContains('Set-Cookie: sid=1; path=/', $queue->held);
        $this->assertNotContains('remove Set-Cookie', $queue->calls);
        $this->assertNotContains('remove Date', $queue->calls);
    }

    public function testNonSitePageAndLegacyResponsesAreLeftAlone(): void
    {
        $reconciler = new LegacyHeaderReconciler($this->kernel(), $queue = new RecordingHeaderQueue(self::SESSION));

        // a request that is not a site page's (no page context)
        $plain = new Response('x', 200, ['Cache-Control' => 'public, max-age=60']);
        $reconciler->onResponse($this->event(Request::create('/'), $plain));
        $this->assertSame('max-age=60, public', $plain->headers->get('Cache-Control'));

        // a sub-request
        $request = Request::create('/');
        $this->context($request);
        $sub = new Response('x');
        $reconciler->onResponse($this->event($request, $sub, HttpKernelInterface::SUB_REQUEST));
        $this->assertTrue($sub->headers->has('Cache-Control'));

        // a response a legacy page writes itself
        $legacy = new LegacyResponse(static function (): void {
        });
        $legacy->headers->set('Cache-Control', 'public');
        $reconciler->onResponse($this->event($request, $legacy));
        $this->assertTrue($legacy->headers->hasCacheControlDirective('public'));

        $this->assertSame([], $queue->calls);

        // and a site page's is reconciled
        $page = new Response('x');
        $reconciler->onResponse($this->event($request, $page));
        $this->assertFalse($page->headers->has('Cache-Control'));
    }

    public function testNoXRobotsTagIsSentWithASitePage(): void
    {
        // Symfony adds `noindex` to every response in debug mode, at the end; a page of the site never had it, and a header the page set stays
        $reconciler = new LegacyHeaderReconciler($this->kernel(), new RecordingHeaderQueue());
        $request = Request::create('/');
        $context = $this->context($request);

        $added = new Response('x', 200, ['X-Robots-Tag' => 'noindex']);
        $reconciler->removeRobotsTag($this->event($request, $added));
        $this->assertFalse($added->headers->has('X-Robots-Tag'));

        $context->addHeader('X-Robots-Tag', 'noarchive');
        $own = new Response('x', 200, ['X-Robots-Tag' => 'noarchive']);
        $reconciler->removeRobotsTag($this->event($request, $own));
        $this->assertSame('noarchive', $own->headers->get('X-Robots-Tag'));

        $other = new Response('x', 200, ['X-Robots-Tag' => 'noindex']);
        $reconciler->removeRobotsTag($this->event(Request::create('/other'), $other));
        $this->assertSame('noindex', $other->headers->get('X-Robots-Tag'), 'the response of anything else is as it was');
    }

    public function testNothingWithoutTheLegacyApplication(): void
    {
        $queue = new RecordingHeaderQueue(self::SESSION);
        $reconciler = new LegacyHeaderReconciler($this->kernel(false), $queue);
        $request = Request::create('/');
        $this->context($request);
        $response = new Response('x');
        $response->headers->set('X-Robots-Tag', 'noindex');

        $reconciler->onResponse($this->event($request, $response));
        $reconciler->removeRobotsTag($this->event($request, $response));

        $this->assertTrue($response->headers->has('Cache-Control'));
        $this->assertTrue($response->headers->has('X-Robots-Tag'));
        $this->assertSame([], $queue->calls, 'PHP\'s list was not even read');
    }

    public function testTheOrderOfTheListeners(): void
    {
        $priority = static function (string $class, string $method): int {
            foreach ((new \ReflectionMethod($class, $method))->getAttributes(AsEventListener::class) as $attribute) {
                return $attribute->newInstance()->priority ?? 0;
            }
            throw new \LogicException('no listener');
        };

        $this->assertSame(8, $priority(LegacyHeaderReconciler::class, 'onResponse'), 'before Symfony\'s ResponseListener, which prepares the response');
        $this->assertGreaterThan(8, $priority(PageRequestListener::class, 'onResponse'), 'after the page\'s own headers are on the response');
        $this->assertLessThan(-255, $priority(LegacyHeaderReconciler::class, 'removeRobotsTag'), 'after the listener that adds the tag');
    }
}
