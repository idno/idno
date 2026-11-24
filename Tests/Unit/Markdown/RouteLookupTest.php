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
}
