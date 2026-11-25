<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use Idno\Http\Controller\MentionSearchController;
use Idno\Http\Controller\SiteFilesController;
use Idno\Http\Controller\WebfingerController;
use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the small endpoints' controllers declare: each route and page, whether a visitor of a private site may reach it, and the patterns core
 * registered with the legacy router for it (their classes are deleted, so `replaces` is empty).
 */
final class DiscoveryControllersTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: list<string>, 3: string, 4: bool, 5: list<string>}> action => [controller, method, route paths, page name, public, legacy patterns]
     */
    public static function endpoints(): array
    {
        return [
            'robots.txt' => [SiteFilesController::class, 'robots', ['/{lead}robots.txt{slash}'], 'Idno\Pages\Txt\Robots', false, ['/robots\.txt']],
            'manifest' => [SiteFilesController::class, 'manifest', ['/{lead}chrome/manifest.json{slash}'], 'Idno\Pages\Chrome\Manifest', true, ['/chrome/manifest\.json']],
            'service worker' => [SiteFilesController::class, 'serviceWorker', ['/{lead}service-worker.js{slash}', '/{lead}service-worker.min.js{slash}'], 'Idno\Pages\Chrome\ServiceWorker', true, ['/service-worker(\.min)?\.js']],
            'webfinger' => [WebfingerController::class, '__invoke', ['/{lead}.well-known/webfinger{slash}'], 'Idno\Pages\Webfinger\View', true, ['/\.well\-known/webfinger/?']],
            'mention search' => [MentionSearchController::class, '__invoke', ['/{lead}search/mentions.json{slash}'], 'Idno\Pages\Search\Mentions', false, ['/search/mentions\.json']],
        ];
    }

    /**
     * @dataProvider endpoints
     *
     * @param list<string> $paths
     * @param list<string> $patterns
     */
    public function testWhatTheControllerDeclares(string $controller, string $action, array $paths, string $name, bool $public, array $patterns): void
    {
        $method = new \ReflectionMethod($controller, $action);
        $routes = array_map(static fn (\ReflectionAttribute $a): Route => $a->newInstance(), $method->getAttributes(Route::class));
        $page = $method->getAttributes(SitePage::class)[0]->newInstance();

        $this->assertSame($paths, array_map(static fn (Route $r): string => $r->path, $routes));
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $route->methods);
            $this->assertSame(['lead' => '/?', 'slash' => '/?'], $route->requirements);
            $this->assertSame(['lead' => '', 'slash' => ''], $route->defaults);
        }
        $this->assertSame($name, $page->name);
        $this->assertSame([], $page->replaces, 'the class is deleted: no shell is shadowed');
        $this->assertSame($public, $page->publicOnPrivateSite);
        $this->assertSame($patterns, $page->legacyPatterns);
    }
}
