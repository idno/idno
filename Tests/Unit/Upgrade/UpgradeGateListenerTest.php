<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Config\BootConfig;
use Idno\Upgrade\SchemaGate;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\UpgradeGateListener;
use Idno\Upgrade\UpgradeProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The listener that answers every request but the upgrade step while the schema gate is closed,
 * with a gate whose state comes from fakes (an empty database unless a test says otherwise).
 */
final class UpgradeGateListenerTest extends TestCase
{
    public function testHtmlGetGetsThePageWith503(): void
    {
        $response = $this->handle(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']));

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('<h1>Idno is setting up its database</h1>', (string) $response->getContent());
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/^default-src 'none'; script-src 'nonce-([A-Za-z0-9+\\/=]{20,})'; /", $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        self::assertStringContainsString('<script nonce="' . $m[1] . '">', (string) $response->getContent());

        $again = $this->handle(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']));
        self::assertNotSame($csp, $again?->headers->get('Content-Security-Policy'), 'a nonce per response');
    }

    public function testAHeadRequestGetsTheHeadersOnly(): void
    {
        $response = $this->handle(Request::create('/', 'HEAD', server: ['HTTP_ACCEPT' => 'text/html']));

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertSame('', $response->getContent());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function requestsThatAreNotPageLoads(): iterable
    {
        yield 'POST /inbox' => [Request::create('/inbox', 'POST', server: ['HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'application/activity+json'], content: '{}')];
        yield 'GET with Accept application/activity+json' => [Request::create('/profile/someone', 'GET', server: ['HTTP_ACCEPT' => 'application/activity+json'])];
        yield 'GET /?_t=rss' => [Request::create('/?_t=rss', 'GET', server: ['HTTP_ACCEPT' => 'application/rss+xml'])];
        yield 'GET without Accept' => [Request::create('/', 'GET', server: ['HTTP_ACCEPT' => ''])];
        yield 'GET of the step path' => [Request::create('/_upgrade/step', 'GET', server: ['HTTP_ACCEPT' => 'application/json'])];
        yield 'PUT to the step path' => [Request::create('/_upgrade/step', 'PUT', server: ['HTTP_ACCEPT' => 'application/json'])];
        foreach (['PUT', 'DELETE', 'PATCH', 'nonsense!'] as $override) {
            yield 'POST to the step path overridden to ' . $override => [self::overridden($override)];
        }
        yield 'POST to the step path with a trailing slash' => [Request::create('/_upgrade/step/', 'POST', server: ['HTTP_ACCEPT' => 'application/json'])];
        yield 'HEAD of the step path' => [Request::create('/_upgrade/step', 'HEAD', server: ['HTTP_ACCEPT' => 'application/json'])];
        yield 'OPTIONS of the step path' => [Request::create('/_upgrade/step', 'OPTIONS', server: ['HTTP_ACCEPT' => 'application/json'])];
    }

    /**
     * @dataProvider requestsThatAreNotPageLoads
     */
    public function testNonHtmlRequestsGet503WithRetryAfter(Request $request): void
    {
        $response = $this->handle($request);

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame("Idno is upgrading its database.\n", $response->getContent());
    }

    private static function overridden(string $method): Request
    {
        return Request::create('/_upgrade/step', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_HTTP_METHOD_OVERRIDE' => $method]);
    }

    public function testTheStepRouteIsLetThrough(): void
    {
        self::assertNull($this->handle(Request::create('/_upgrade/step', 'POST')));
        self::assertNull($this->handle(Request::create('/_upgrade/step?html=1', 'POST')));
        // Symfony ignores an override to GET: the router still sees a POST to the step route.
        self::assertNull($this->handle(self::overridden('get')));
        self::assertNull($this->handle(Request::create('/blog/_upgrade/step', 'POST', server: ['SCRIPT_NAME' => '/blog/index.php', 'SCRIPT_FILENAME' => '/srv/blog/index.php'])));
    }

    public function testAnOpenGateDoesNothing(): void
    {
        $gate = $this->gate(['idno_migrations']);
        self::assertFalse($gate->closed());

        self::assertNull($this->handle(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $gate));
    }

    /**
     * A kernel built with bootLegacy: false never asks the gate: the listener does not ask it
     * either (nothing connects).
     */
    public function testAGateNobodyAskedDoesNothing(): void
    {
        $gate = new SchemaGate(new SchemaState(
            new BootConfig(['database' => 'mysql'], false),
            static fn () => throw new \LogicException('nothing may connect'),
            static fn () => throw new \LogicException('nothing may connect'),
            static fn () => throw new \LogicException('nothing may connect'),
            static fn () => throw new \LogicException('nothing may connect'),
        ));

        self::assertNull($this->handle(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $gate, ask: false));
        self::assertFalse($gate->asked());
    }

    public function testThePageInASubdirectoryPostsUnderIt(): void
    {
        $request = Request::create('/blog/2026/a-post', 'GET', server: ['HTTP_ACCEPT' => 'text/html', 'SCRIPT_NAME' => '/blog/index.php', 'SCRIPT_FILENAME' => '/srv/blog/index.php']);

        $response = $this->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('var url = "/blog/_upgrade/step";', (string) $response->getContent());
        self::assertStringContainsString('action="/blog/_upgrade/step?html=1"', (string) $response->getContent());
    }

    public function testAOneXUpgradeShowsItsProgressAndItsStoredError(): void
    {
        $progress = new UpgradeProgress(
            ['entities' => ['source' => 1200, 'copied' => 500, 'rejected' => 0, 'done' => false]],
            false,
            'Something <b>broke</b>',
            UpgradeProgress::COPYING,
        );
        $gate = $this->gate(['entities', 'entities_metadata', 'entities_search', 'versions'], progress: $progress);

        $response = $this->handle(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $gate);

        self::assertNotNull($response);
        self::assertStringContainsString('500 of 1200 copied', (string) $response->getContent());
        self::assertStringContainsString('Something &lt;b&gt;broke&lt;/b&gt;', (string) $response->getContent());
    }

    public function testATooOldDatabaseIsToldSoInPlainTextToo(): void
    {
        $gate = $this->gate(['entities', 'entities_metadata', 'entities_search', 'versions'], legacyVersion: 2019121401);

        $response = $this->handle(Request::create('/inbox', 'POST'), $gate);

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('1.6.4', (string) $response->getContent());
    }

    /**
     * A site configured for MongoDB: a plain-text refusal that names the import command, or a page
     * with no script and no form; Retry-After and no-store as for every closed gate.
     */
    public function testAMongoSiteIsToldWhatToDo(): void
    {
        $never = static fn () => throw new \LogicException('nothing may be fetched');
        $gate = new SchemaGate(new SchemaState(new BootConfig(['database' => 'mongodb'], false), $never, $never, $never, $never));
        $message = \Idno\Upgrade\PublicMessage::mongoRetired()->text;

        $text = $this->handle(Request::create('/inbox', 'POST'), $gate);
        self::assertNotNull($text);
        self::assertSame(503, $text->getStatusCode());
        self::assertSame('text/plain; charset=UTF-8', $text->headers->get('Content-Type'));
        self::assertSame('Idno cannot start: ' . $message . "\n", $text->getContent());
        self::assertSame('30', $text->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $text->headers->get('Cache-Control'));

        $page = $this->handle(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $gate);
        self::assertNotNull($page);
        self::assertSame(503, $page->getStatusCode());
        self::assertSame('30', $page->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        self::assertStringContainsString('idno:import:mongo', (string) $page->getContent());
        self::assertStringNotContainsString('<script', (string) $page->getContent());
        self::assertStringNotContainsString('<form', (string) $page->getContent());
    }

    // ------------------------------------------------------------------ helpers

    private function handle(Request $request, ?SchemaGate $gate = null, bool $ask = true): ?Response
    {
        $gate ??= $this->gate([]);
        if ($ask) {
            $gate->closed();
        }
        $kernel = new class () implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $copier = $ask && $gate->status() === \Idno\Upgrade\SchemaStatus::Legacy ? $this->copierFake : static fn () => throw new \LogicException('not fetched');

        (new UpgradeGateListener($gate, $copier))->onKernelRequest($event);

        return $event->getResponse();
    }

    private ?\Closure $copierFake = null;

    /**
     * A gate on a MySQL site whose tables (and 1.x version and copy progress) are these.
     *
     * @param list<string> $tables
     */
    private function gate(array $tables, ?int $legacyVersion = 2020120301, ?UpgradeProgress $progress = null): SchemaGate
    {
        $copier = new class ($legacyVersion, $progress) {
            public function __construct(private readonly ?int $version, private readonly ?UpgradeProgress $progress)
            {
            }

            public function legacySchemaVersion(): ?int
            {
                return $this->version;
            }

            public function completion(): ?bool
            {
                return null;
            }

            public function progress(): UpgradeProgress
            {
                return $this->progress ?? new UpgradeProgress([], false, null);
            }
        };
        $this->copierFake = static fn (): object => $copier;

        return new SchemaGate(new SchemaState(
            new BootConfig(['database' => 'mysql'], false),
            static fn (): object => new class () {
                public function getServerVersion(): string
                {
                    return '8.0.46';
                }
            },
            static fn (): object => new class ($tables) {
                /** @param list<string> $tables */
                public function __construct(private readonly array $tables)
                {
                }

                /** @return list<string> */
                public function tables(): array
                {
                    return $this->tables;
                }
            },
            static fn (): object => new class () {
                /** @return array{pending: bool, incomplete: bool, complete: bool} */
                public function probe(?string $progressTable = null): array
                {
                    return ['pending' => false, 'incomplete' => false, 'complete' => false];
                }
            },
            $this->copierFake,
        ));
    }
}
