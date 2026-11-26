<?php

declare(strict_types=1);

namespace Tests\Unit\Markdown;

use Idno\Core\PageHandler;
use PHPUnit\Framework\TestCase;

/**
 * `PageHandler::handlerFor()` decides, before the router runs, what the router will choose for a path (that is how `.md` is
 * recognised without changing any address). It must be the router's own lookup: for every pattern and path here both are
 * asked, the router by serving the path (Toro::serve(), with handlers that record themselves), and the answers compared.
 */
final class RouteLookupTest extends TestCase
{
    /** @var array<string, string> patterns as the site registers them (PageHandler::addRoute() has replaced its tokens), in order */
    private const ROUTES = [
        '/' => 'home',
        '/view/([A-Za-z0-9\-]+)/?' => 'view',
        '/[0-9]{4}/([^/.]+)/?' => 'permalink',
        '/entry/([A-Za-z0-9\-]+)/.*' => 'entry',
        '/pages/([^/.]+)/?' => 'page',
        '/profile/([^\/]+)/?' => 'profile',
        '/tag/([^\/]+)/?' => 'tag',
        'admin/styles/?' => 'styles',
        '/file/([A-Za-z0-9]+)(/.*)?' => 'file',
        '/account/settings/?' => 'settings',
        '/number/:number' => 'number',
        '/word/:string/:alpha/?' => 'word',
        '/feed\.xml' => 'feed',
        '/exact.md' => 'exact',
        '/.*\.txt' => 'text',
    ];

    private const PATHS = [
        '/', '', '/view/abc-123', '/view/abc-123/', '/view/abc.md', '/2026/hello', '/2026/hello/', '/2026/hello.md', '/2026/caf%C3%A9',
        '/2026/a/b', '/20261/hello', '/entry/abc/title', '/entry/abc/title.md', '/entry/abc/', '/entry/abc', '/pages/about', '/pages/about.md',
        '/profile/ada', '/profile/ada.md', '/tag/idno', 'tag/idno', '/admin/styles', 'admin/styles/', '/admin/styles.md', '/file/abc123',
        '/file/abc123/photo.md', '/account/settings', '/account/settings/', '/number/42', '/number/x', '/word/abc/a-b_c', '/word/abc/a.b',
        '/feed.xml', '/feedxxml', '/exact.md', '/exactxmd', '/robots.txt', '/a/b/c.txt', '/nothing', '/nothing.md', '/.md', '//', '/2026/hello?x=1',
        "/2026/hello\n", '/2026/Hello', '/VIEW/abc',
    ];

    /** @var list<string> */
    public static array $served = [];

    public function testTheLookupIsTheRoutersForEveryPatternAndPath(): void
    {
        $routes = [];
        foreach (self::ROUTES as $pattern => $name) {
            $class = 'RouteLookupHandler_' . $name;
            if (!class_exists(__NAMESPACE__ . '\\' . $class, false)) {
                eval('namespace ' . __NAMESPACE__ . '; final class ' . $class . ' { public function get(): void { RouteLookupTest::$served[] = \'' . $name . '\'; } }');
            }
            $routes[$pattern] = __NAMESPACE__ . '\\' . $class;
        }

        $saved = $_SERVER;
        try {
            $matched = 0;
            foreach (self::PATHS as $path) {
                self::$served = [];
                unset($_SERVER['ORIG_PATH_INFO']);
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_SERVER['PATH_INFO'] = $path;
                $_SERVER['REQUEST_URI'] = $path === '' ? '/' : $path;
                unset($_SERVER['HTTP_X_REQUESTED_WITH']);
                \Toro::serve($routes);

                // (the router takes an empty PATH_INFO for no PATH_INFO, and reads the request's address)
                $handler = PageHandler::handlerFor($routes, $path === '' ? '/' : $path);
                $expected = self::$served === [] ? null : __NAMESPACE__ . '\\RouteLookupHandler_' . self::$served[0];
                $this->assertSame($expected, $handler, 'the path ' . json_encode($path));
                $matched += $expected === null ? 0 : 1;
            }
            $this->assertGreaterThan(20, $matched, 'the paths exercise the patterns');
            $this->assertLessThan(count(self::PATHS), $matched, 'and some match none');
        } finally {
            $_SERVER = $saved;
        }
    }

    public function testEveryPatternIsChosenForSomePath(): void
    {
        $chosen = [];
        foreach (self::PATHS as $path) {
            $handler = PageHandler::handlerFor(self::ROUTES, $path);
            if ($handler !== null) {
                $chosen[$handler] = true;
            }
        }

        $this->assertSame([], array_values(array_diff(array_values(self::ROUTES), array_keys($chosen))));
    }

    /** @var array<string, string> a table with the patterns permalinks use, in order; the `permalink*` handlers have a Markdown form */
    private const PERMALINK_ROUTES = [
        '/' => 'home',
        '/[0-9]{4}/([^/.]+)/?' => 'permalink',
        '/[0-9]{4}/[0-9]{2}/([^/.]+)/?' => 'permalinkdated',
        '/view/([A-Za-z0-9\-]+)/?' => 'view',
        '/entry/([A-Za-z0-9\-]+)/.*' => 'entry',
        '/file/(([A-Za-z0-9\-]+))(/.*)?' => 'file',
        '/profile/([^\/]+)/([A-Za-z\-\/]+)+' => 'profilepath',
        '/number/:number/:string' => 'number',
        '/feed\.xml' => 'feed',
        '/exact.md' => 'exact',
        '/staticexact' => 'staticexact',
        '/alpha/:alpha' => 'alpha',
        '/opt(/x)?/([a-z]+)' => 'optional',
    ];

    private const MARKDOWN_HANDLERS = ['permalink', 'permalinkdated', 'entry', 'staticexact'];

    private const ROUTED_PATHS = [
        '/', '/2026/hello', '/2026/hello/', '/2026/hello.md', '/2026/11/hello', '/2026/11/hello.md', '/2026/11/hello/', '/view/abc-1', '/view/abc-1/',
        '/view/abc.md', '/entry/abc/title', '/entry/abc/title.md', '/entry/abc/', '/file/abc', '/file/abc/', '/file/abc/photo.jpg', '/file/abc/photo.md',
        '/profile/ada/a-b', '/profile/ada/a/b/c', '/profile/ada/a-b.md', '/profile/ada', '/number/42/abc', '/number/42/abc.md', '/feed.xml',
        '/exact.md', '/staticexact', '/staticexact.md', '/nothing', '/nothing.md', '/.md', '/2026/a.b.md', '/2026/a/b', '/2026/caf%C3%A9.md',
        '/alpha/ab-c_d', '/alpha/ab.c', '/alpha/AB', '/alpha/ab.md', '/opt/abc', '/opt/x/abc', '/opt/abc.md', '/OPT/abc', '/2026/HELLO', '/2026/HELLO.md',
        '//2026/hello', '//2026/hello.md', '/2026/hello//', "/2026/hello\n", '/2026/hello.MD', '/staticexact.md.md',
    ];

    private static function permalinkTable(): array
    {
        $routes = [];
        foreach (self::PERMALINK_ROUTES as $pattern => $name) {
            $class = 'RouteLookupRecording_' . $name;
            if (!class_exists(__NAMESPACE__ . '\\' . $class, false)) {
                $implements = in_array($name, self::MARKDOWN_HANDLERS, true) ? ' implements \\Idno\\Common\\MarkdownPermalink' : '';
                $record = 'RouteLookupTest::$calls[] = [\'' . $name . '\', func_get_args()];';
                eval('namespace ' . __NAMESPACE__ . '; final class ' . $class . $implements . ' { '
                    . 'public function get(...$a): void { ' . $record . ' } '
                    . 'public function head(...$a): void { ' . $record . ' } '
                    . 'public function post(...$a): void { ' . $record . ' } '
                    . 'public function put(...$a): void { ' . $record . ' } '
                . 'public function delete(...$a): void { ' . $record . ' } }');
            }
            $routes[$pattern] = __NAMESPACE__ . '\\' . $class;
        }

        return $routes;
    }

    /** @var list<array{string, list<mixed>}> */
    public static array $calls = [];

    /**
     * @return array{0: ?array{string, list<mixed>}, 1: bool, 2: string} what Toro called, whether the suffix was remembered, the path it routed
     */
    private function served(array $routes, string $method, string $path, ?string $override = null, bool $xhr = false): array
    {
        $saved = $_SERVER;
        try {
            self::$calls = [];
            unset($_SERVER['ORIG_PATH_INFO'], $_SERVER['HTTP_X_REQUESTED_WITH']);
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['PATH_INFO'] = $path;
            $_SERVER['REQUEST_URI'] = $path === '' ? '/' : $path;
            if ($override !== null) {
                $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = $override;
            }
            if ($xhr) {
                $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            }
            RouteLookupRecorder::remember($routes);
            $markdown = PageHandler::markdownSuffixRequested();
            $routed = (string) $_SERVER['PATH_INFO'];
            \Toro::serve($routes);

            return [self::$calls[0] ?? null, $markdown, $routed];
        } finally {
            $_SERVER = $saved;
        }
    }

    public function testRoutedForIsTheRoutersChoiceArgumentsAndMarkdownFlag(): void
    {
        $routes = self::permalinkTable();
        $found = 0;
        foreach ([['GET', null, false], ['HEAD', null, false], ['POST', 'GET', false], ['POST', null, false], ['PUT', null, false], ['DELETE', null, false], ['GET', null, true], ['POST', null, true]] as [$method, $override, $xhr]) {
            foreach (self::ROUTED_PATHS as $path) {
                [$call, $markdown, $routedPath] = $this->served($routes, $method, $path, $override, $xhr);
                $routed = PageHandler::routedFor($routes, $method, $path);
                $label = $method . ($override !== null ? ' (override ' . $override . ')' : '') . ($xhr ? ' (XHR)' : '') . ' ' . $path;
                // What Toro really reaches, with no help from the code under test: the flag and the routed path it implies
                [$expectedMarkdown, $expectedPath] = $this->expectedMarkdown($routes, $method, $path);
                $this->assertSame($expectedMarkdown, $markdown, 'the router\'s own recording: ' . $label);
                $this->assertSame($expectedPath, $routedPath, 'PATH_INFO as the router recorded it: ' . $label);
                if ($call === null) {
                    // (a request whose class has no such method is not dispatched: the choice is still the lookup's)
                    $this->assertSame(PageHandler::chosenFor($routes, $routedPath), $routed['class'] ?? null, $label);
                    continue;
                }
                $found++;
                $this->assertNotNull($routed, $label);
                $this->assertSame(__NAMESPACE__ . '\\RouteLookupRecording_' . $call[0], $routed['class'], $label);
                $this->assertSame($call[1], $routed['arguments'], $label);
                $this->assertSame($expectedMarkdown, $routed['markdown'], $label);
                $this->assertSame($routedPath, $routed['path'], $label);
                $this->assertSame($routed['class'], ltrim($routes[$routed['pattern']], '\\'), $label);
            }
        }
        $this->assertGreaterThan(80, $found);

        $this->assertNull(PageHandler::routedFor($routes, 'GET', '/nothing'));
        $this->assertSame([], PageHandler::routedFor($routes, 'GET', '/exact.md')['arguments']);
        $this->assertSame('/exact.md', PageHandler::routedFor($routes, 'GET', '/exact.md')['pattern']);
        $this->assertFalse(PageHandler::routedFor($routes, 'GET', '/exact.md')['markdown'], 'an exact key is routed as it is');
        $this->assertSame(['hello'], PageHandler::routedFor($routes, 'GET', '/2026/hello.md')['arguments']);
        $this->assertSame('/2026/hello', PageHandler::routedFor($routes, 'GET', '/2026/hello.md')['path']);
        $this->assertSame(['abc', 'abc'], array_slice(PageHandler::routedFor($routes, 'GET', '/file/abc')['arguments'], 0, 2));
        $this->assertFalse(PageHandler::routedFor($routes, 'POST', '/2026/hello.md')['markdown'] ?? false);
        $this->assertSame('(not a class name)', PageHandler::routedFor(['/x' => static function (): void {
        }], 'GET', '/x')['class']);
    }

    /**
     * The handler Toro itself reaches for a path (no suffix rule applied).
     */
    private function reachedBy(array $routes, string $method, string $path): ?string
    {
        $saved = $_SERVER;
        try {
            self::$calls = [];
            unset($_SERVER['ORIG_PATH_INFO'], $_SERVER['HTTP_X_REQUESTED_WITH']);
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['PATH_INFO'] = $path;
            $_SERVER['REQUEST_URI'] = $path === '' ? '/' : $path;
            \Toro::serve($routes);

            return isset(self::$calls[0]) ? __NAMESPACE__ . '\\RouteLookupRecording_' . self::$calls[0][0] : null;
        } finally {
            $_SERVER = $saved;
        }
    }

    /**
     * Whether a request is the Markdown form of a permalink and the path then routed, worked out from what Toro reaches for the path as given and without
     * its suffix, never from the code under test: a GET or HEAD whose last segment has more than `.md`; the path as given reaches a page (it is routed as
     * it is, and is Markdown when that page is a MarkdownPermalink), or reaches none and the path without the suffix reaches a MarkdownPermalink.
     *
     * @return array{0: bool, 1: string}
     */
    private function expectedMarkdown(array $routes, string $method, string $path): array
    {
        if (!in_array($method, ['GET', 'HEAD'], true) || !preg_match('~[^/]\.md$~', $path)) {
            return [false, $path];
        }
        $given = $this->reachedBy($routes, $method, $path);
        if ($given !== null) {
            return [is_a($given, \Idno\Common\MarkdownPermalink::class, true), $path];
        }
        $stripped = substr($path, 0, -3);
        $reached = $this->reachedBy($routes, $method, $stripped);

        return $reached !== null && is_a($reached, \Idno\Common\MarkdownPermalink::class, true) ? [true, $stripped] : [false, $path];
    }

    public function testAMatchingRouteWithNoHandlerIsTheRoutersNothing(): void
    {
        // Toro stops at the first pattern that matches and, with a handler that is false in PHP, serves its 404 hook: it never tries a later pattern
        $routes = ['/zero' => '0', '/zero/?' => self::permalinkTable()['/staticexact'], '/blank' => '', '/blank/?' => self::permalinkTable()['/staticexact']];
        foreach (['/zero', '/blank'] as $path) {
            $this->assertNull($this->reachedBy($routes, 'GET', $path), $path);
            $this->assertNull(PageHandler::routedFor($routes, 'GET', $path), $path);
            $this->assertNull(PageHandler::routedFor($routes, 'GET', $path . '.md'), $path);
        }
    }

    public function testAPathWithoutTheSuffixAsksTheTableNothingBeforeTheRouterDoes(): void
    {
        $table = new class (self::permalinkTable()) implements \ArrayAccess, \IteratorAggregate {
            public int $looked = 0;

            public function __construct(private array $routes)
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                $this->looked++;

                return isset($this->routes[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->routes[$offset];
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }

            public function getIterator(): \Iterator
            {
                $this->looked++;

                return new \ArrayIterator($this->routes);
            }
        };

        $saved = $_SERVER;
        try {
            unset($_SERVER['ORIG_PATH_INFO']);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            foreach (['/2026/hello', '/', '/nothing', '/feed.xml', '/2026/a.mdx'] as $path) {
                $_SERVER['PATH_INFO'] = $path;
                RouteLookupRecorder::remember($table);
                $this->assertSame(0, $table->looked, 'no lookup for ' . $path);
                $this->assertFalse(PageHandler::markdownSuffixRequested());
            }
            $_SERVER['PATH_INFO'] = '/2026/hello.md';
            RouteLookupRecorder::remember($table);
            $this->assertGreaterThan(0, $table->looked, 'a path with the suffix is looked up');
            $this->assertTrue(PageHandler::markdownSuffixRequested());
        } finally {
            $_SERVER = $saved;
        }
    }

    public function testAOneEntryTableIsDispatchedAlike(): void
    {
        $routes = self::permalinkTable();
        foreach (['GET', 'HEAD'] as $method) {
            foreach (self::ROUTED_PATHS as $path) {
                $routed = PageHandler::routedFor($routes, $method, $path);
                if ($routed === null) {
                    continue;
                }
                [$whole] = $this->served($routes, $method, $routed['path']);
                [$alone] = $this->served([$routed['pattern'] => $routed['class']], $method, $routed['path']);
                $this->assertSame($whole, $alone, $method . ' ' . $path);
            }
        }
    }
}

/** exposes the router's own recording of the `.md` suffix */
final class RouteLookupRecorder extends PageHandler
{
    public function __construct()
    {
    }

    public static function remember($routes): void
    {
        static::routeMarkdownSuffix($routes);
    }
}
