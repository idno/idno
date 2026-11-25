<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Logging;
use Idno\Http\FrontController;
use Idno\Kernel;
use Idno\Legacy\LegacyFallbackListener;
use Idno\Legacy\LegacyResponse;
use Idno\Legacy\NotInstalledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class FrontControllerTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testNotInstalledRedirectsToWarmup(): void
    {
        $kernel = new class ('test', true) extends Kernel {
            public function boot(): void
            {
                throw new NotInstalledException('not installed');
            }
        };

        $response = FrontController::handle($kernel, Request::create('/'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('./warmup/', $response->headers->get('Location'));
    }

    public function testNotInstalledRedirectIsSentLikeTheLegacyFrontController(): void
    {
        // Before the kernel handled requests, the redirect was header('Location: ./warmup/')
        // and nothing else: no body, and no Symfony headers (Cache-Control, Date).
        $response = FrontController::notInstalledResponse();

        $this->assertInstanceOf(LegacyResponse::class, $response);
        $this->assertSame('./warmup/', $response->headers->get('Location'));
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUnmatchedPathsReachTheLegacyController(): void
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');

        $paths = ['/', '//', '/this.has.dots/and%20space', '/foo/', '/foo%0Abar', '/%FF', '/feed.xml', '/view/x', '/index.php/foo', '/a%2Fb'];
        // the feed is a controller's, for the methods the page serves (the legacy router has the others)
        $served = ['/', '//', '/feed.xml'];
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'PROPFIND'] as $method) {
            foreach ($paths as $path) {
                // As PHP's web server SAPIs build it (Request::create() rejects some of these URIs).
                $request = new Request(server: [
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => $path,
                    'SCRIPT_NAME' => '/index.php',
                    'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php',
                    'HTTP_HOST' => 'localhost',
                ]);
                $match = $router->matchRequest($request);
                // the home page is a controller's: its root, for the methods the page serves (the legacy router has the others)
                if (in_array($path, $served, true) && in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $this->assertSame('idno_legacy', $match['_route'], "$method $path");
                $this->assertSame(\Idno\Legacy\LegacyController::class, $match['_controller'], "$method $path");
            }
        }
    }

    public function testThePortedHomeRoutes(): void
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            foreach (['/', '//'] as $path) {
                $request = new Request(server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php', 'HTTP_HOST' => 'localhost']);
                $this->assertSame('idno_home', $router->matchRequest($request)['_route'], "$method $path");
            }
            foreach (['/content/all', '//content/note/article', '/content/all/'] as $path) {
                $request = new Request(server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php', 'HTTP_HOST' => 'localhost']);
                $match = $router->matchRequest($request);
                $this->assertSame('idno_content', $match['_route'], "$method $path");
            }
        }
        // what the pattern of the legacy page left out: no type, a digit, a deeper dot
        foreach (['/content/', '/content', '/content/a1', '/content/a.b'] as $path) {
            $request = new Request(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php', 'HTTP_HOST' => 'localhost']);
            $this->assertSame('idno_legacy', $router->matchRequest($request)['_route'], $path);
        }
    }

    public function testThePortedFeedSearchAndTagRoutes(): void
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');

        $routes = [
            'idno_feed_xml' => ['/feed.xml', '//feed.xml', '/feed.xml/', '//feed.xml/'],
            'idno_feed' => ['/feed', '//feed/'],
            'idno_rss_xml' => ['/rss.xml', '/rss.xml/', '//rss.xml'],
            'idno_search' => ['/search', '//search?q=a', '/search/'],
            'idno_tag' => ['/tag/books', '//tag/books', '/tag/books/', '/tag/caf%C3%A9', '/tag/%FF', '/tag/a/b'],
        ];
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            foreach ($routes as $name => $paths) {
                foreach ($paths as $path) {
                    $request = new Request(server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php', 'HTTP_HOST' => 'localhost']);
                    $this->assertSame($name, $router->matchRequest($request)['_route'], "$method $path");
                }
            }
        }
        // what is not theirs: no tag, a space in the tag, any other method, a third slash, a longer name
        foreach ([['GET', '/tag/'], ['GET', '/tag'], ['GET', '/tag/a%20b'], ['POST', '/feed.xml'], ['PUT', '/search'], ['DELETE', '/tag/x'], ['PATCH', '/rss.xml'], ['GET', '///feed.xml'], ['GET', '/feeds'], ['GET', '/search/users'], ['GET', '/search/mentions.json']] as [$method, $path]) {
            $request = new Request(server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/index.php', 'HTTP_HOST' => 'localhost']);
            $this->assertSame('idno_legacy', $router->matchRequest($request)['_route'], "$method $path");
        }
    }

    /**
     * @return array<string, array{0: Request}>
     */
    public static function requestsSymfonyRejects(): array
    {
        $server = ['REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];

        return [
            // RouterListener: SuspiciousOperationException "Invalid Host".
            'malformed Host' => [new Request(server: $server + ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'bad host!'])],
            // Request::getMethod(): SuspiciousOperationException "Invalid HTTP method override".
            'invalid method override' => [new Request(server: $server + ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'localhost', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'BAD METHOD'])],
        ];
    }

    /**
     * Symfony rejects these requests while routing (as BadRequestHttpException); the
     * legacy front controller served them. They must still reach legacy code, with one
     * warning in the legacy log format.
     *
     * Uses the kernel that booted the legacy application (Tests/_bootstrap.php), through
     * its http_kernel service, so the warning goes through Idno::site()->logging().
     *
     * @dataProvider requestsSymfonyRejects
     */
    public function testRequestRejectedBeforeRoutingFallsBackToLegacyWithAWarning(Request $request): void
    {
        [$response, $lines] = $this->handleWithLegacyKernel($request);

        $this->assertInstanceOf(LegacyResponse::class, $response);
        $this->assertCount(1, $lines, implode("\n", $lines));
        $this->assertMatchesRegularExpression(
            '/Idno \([^)]*\): warning - Request rejected by Symfony before routing \(SuspiciousOperationException\), served by the legacy router instead: /',
            $lines[0]
        );
    }

    public function testRejectedRequestDetailIsSanitisedAndBounded(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php'];
        foreach (["evil\e[31mred\e[0m\x07bell" => 'evil?[31mred?[0m?bell', str_repeat('a', 6000) . '!' => null] as $host => $expected) {
            [$response, $lines] = $this->handleWithLegacyKernel(new Request(server: $server + ['HTTP_HOST' => $host]));

            $this->assertInstanceOf(LegacyResponse::class, $response);
            $this->assertCount(1, $lines);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $lines[0]);
            $this->assertStringNotContainsString(str_repeat('a', LegacyFallbackListener::MAX_DETAIL_LENGTH), $lines[0]);
            $this->assertLessThan(1000, strlen($lines[0]));
            if ($expected !== null) {
                $this->assertStringContainsString($expected, $lines[0]);
            }
        }
    }

    public function testRejectedRequestIsNotLoggedWhenTheSiteLogLevelIsOff(): void
    {
        $logging = Idno::site()->logging();
        $previous = $logging->loglevel_filter;
        $logging->setLogLevel(Logging::LEVEL_OFF);
        try {
            [$response, $lines] = $this->handleWithLegacyKernel(self::requestsSymfonyRejects()['malformed Host'][0]);
        } finally {
            $logging->setLogLevel($previous);
        }

        $this->assertInstanceOf(LegacyResponse::class, $response);
        $this->assertSame([], $lines);
    }

    /**
     * Handles the request with the kernel that booted the legacy application, without
     * sending the response, and returns it with the lines written to the PHP error log.
     *
     * @return array{0: \Symfony\Component\HttpFoundation\Response, 1: list<string>}
     */
    private function handleWithLegacyKernel(Request $request): array
    {
        $log = tempnam(sys_get_temp_dir(), 'idno-test-log-');
        $previous = ini_set('error_log', $log);
        try {
            $response = Idno::container()->get('http_kernel')->handle($request);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        unlink($log);

        return [$response, $lines];
    }

    public function testOtherExceptionBeforeRoutingIsNotServedByLegacy(): void
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $this->kernel->getContainer()->get('event_dispatcher')->addListener(
            KernelEvents::REQUEST,
            static function (RequestEvent $event): void {
                // Main request only, so that Symfony's error page (a sub-request) can render.
                if ($event->isMainRequest()) {
                    throw new \RuntimeException('broken before routing');
                }
            },
            4096
        );

        // Symfony logs the exception to PHP's error log: keep it out of the test output. The
        // listener must not build a second legacy logger, which would replace the PHP error handler.
        $log = tempnam(sys_get_temp_dir(), 'idno-test-log-');
        $previousLog = ini_set('error_log', $log);
        $handler = set_error_handler(null);
        restore_error_handler();
        try {
            $response = FrontController::handle($this->kernel, Request::create('/'));
            $handlerAfter = set_error_handler(null);
            restore_error_handler();
        } finally {
            ini_set('error_log', (string) $previousLog);
            unlink($log);
        }

        $this->assertSame($handler, $handlerAfter, 'the PHP error handler was replaced');
        $this->assertNotInstanceOf(LegacyResponse::class, $response);
        // Symfony's error page for the RuntimeException.
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('broken before routing', (string) $response->getContent());
    }

    public function testRequestFromGlobalsDoesNotConsumeTheBody(): void
    {
        // Request::createFromGlobals() calls request_parse_body() for PUT, DELETE, PATCH
        // and QUERY, which empties php://input for multipart bodies. Legacy code reads
        // php://input and the superglobals itself, so the front controller must not.
        $saved = [$_SERVER, $_POST, $_GET, $_COOKIE, $_FILES];
        try {
            $_SERVER['REQUEST_METHOD'] = 'PUT';
            $_SERVER['REQUEST_URI'] = '/x';
            $_POST = ['from' => 'superglobal'];
            $_GET = ['q' => '1'];

            $request = FrontController::requestFromGlobals();

            $this->assertSame(['from' => 'superglobal'], $request->request->all());
            $this->assertSame(['q' => '1'], $request->query->all());
            $this->assertSame('PUT', $request->getMethod());
            $this->assertSame('/x', $request->getPathInfo());
        } finally {
            [$_SERVER, $_POST, $_GET, $_COOKIE, $_FILES] = $saved;
        }
    }
}
