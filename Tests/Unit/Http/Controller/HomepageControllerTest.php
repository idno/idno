<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use Idno\Http\Controller\HomepageController;
use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the home page controller declares for each of its actions: the route, the page it stands for (its body class and the name its page
 * events carry), the template type it draws when a request names none and whether it shadows a legacy class. The paths of the feeds, the
 * search and the tags were the legacy pages' own, so their spellings are the router's: the doubled leading slash, the trailing slash and
 * any byte in a tag.
 */
final class HomepageControllerTest extends TestCase
{
    /**
     * @return array{0: Route, 1: SitePage}
     */
    private static function declared(string $action): array
    {
        $method = new \ReflectionMethod(HomepageController::class, $action);
        $routes = $method->getAttributes(Route::class);
        $pages = $method->getAttributes(SitePage::class);
        self::assertCount(1, $routes, $action);
        self::assertCount(1, $pages, $action);

        return [$routes[0]->newInstance(), $pages[0]->newInstance()];
    }

    public function testTheFeedsAreOneActionOnThreePathsThatDrawTheStreamInRss(): void
    {
        $method = new \ReflectionMethod(HomepageController::class, 'feed');
        $routes = array_map(static fn (\ReflectionAttribute $a): Route => $a->newInstance(), $method->getAttributes(Route::class));

        $this->assertSame(
            ['idno_feed_xml' => '/{lead}feed.xml{slash}', 'idno_feed' => '/{lead}feed{slash}', 'idno_rss_xml' => '/{lead}rss.xml{slash}'],
            array_column(array_map(static fn (Route $r): array => [$r->name, $r->path], $routes), 1, 0)
        );
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $route->methods);
            $this->assertSame(['lead' => '/?', 'slash' => '/?'], $route->requirements);
            $this->assertSame(['lead' => '', 'slash' => ''], $route->defaults);
        }
        $page = $method->getAttributes(SitePage::class)[0]->newInstance();
        $this->assertSame('Idno\Pages\Homepage', $page->name);
        $this->assertSame('rss', $page->defaultType);
        $this->assertSame([], $page->replaces, 'the class that served the feed is deleted: no shell is shadowed');
    }

    public function testSearchIsTheHomePagesRedirect(): void
    {
        [$route, $page] = self::declared('search');

        $this->assertSame('idno_search', $route->name);
        $this->assertSame('/{lead}search{slash}', $route->path);
        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $route->methods);
        $this->assertSame('Idno\Pages\Homepage', $page->name);
        $this->assertNull($page->defaultType);
        $this->assertSame([], $page->replaces);
    }

    public function testTheTagTakesTheRestOfThePathAsAnyBytes(): void
    {
        [$route, $page] = self::declared('tag');

        $this->assertSame('idno_tag', $route->name);
        $this->assertSame('/{lead}tag/{tag}', $route->path);
        $this->assertSame('[^\s]+', $route->requirements['tag'], 'what the legacy pattern took: anything but white space, so a tag with a space is no tag page');
        $this->assertFalse($route->options['utf8'] ?? null, 'a path that is not UTF-8 is a tag as it was');
        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $route->methods);
        $this->assertSame('Idno\Pages\Homepage', $page->name);
        $this->assertSame([], $page->replaces);
    }
}
