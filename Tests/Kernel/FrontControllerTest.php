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

        $paths = ['/', '//', '/this.has.dots/and%20space', '/foo/', '/foo%0Abar', '/%FF', '/feed.xml', '/index.php/foo', '/a%2Fb'];
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
                $this->assertSame('idno_legacy', $match['_route'], "$method $path");
                $this->assertSame(\Idno\Legacy\LegacyController::class, $match['_controller'], "$method $path");
            }
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

        $response = FrontController::handle($this->kernel, Request::create('/'));

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
