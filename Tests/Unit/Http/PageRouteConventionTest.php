<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[SitePage('Fixture\RoutesConvention')]
final class RoutesConventionController
{
    public function page(): void
    {
    }
}

#[SitePage('Fixture\RoutesConventionPost', methods: ['GET', 'POST'])]
final class RoutesConventionPostController
{
    public function page(): void
    {
    }
}

/**
 * The check of the route convention (PageRouteConvention) finds each way a route can break it, and passes the shapes that follow it.
 */
final class PageRouteConventionTest extends TestCase
{
    private static function route(string $path, array $options = [], array $methods = ['GET', 'HEAD', 'OPTIONS'], array $requirements = ['lead' => '/?', 'slash' => '/?'], array $defaults = ['lead' => '', 'slash' => ''], string $controller = RoutesConventionController::class): Route
    {
        return new Route($path, ['_controller' => $controller . '::page'] + $defaults, $requirements, $options, '', [], $methods);
    }

    public function testTheConventionsViolationsAreFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('ok', self::route('/{lead}x{slash}'));
        $routes->add('ok_root', self::route('/{slash}', defaults: ['slash' => '', 'lead' => '']));
        $routes->add('ok_post', self::route('/{lead}x/post{slash}', methods: ['GET', 'HEAD', 'OPTIONS', 'POST'], controller: RoutesConventionPostController::class));
        $routes->add('ok_tag', self::route('/{lead}tag/{name}{slash}', ['utf8' => false], requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '[^/]+']));
        $routes->add('ok_ascii', self::route('/{lead}n/{n}{slash}', requirements: ['lead' => '/?', 'slash' => '/?', 'n' => '[0-9]+']));
        $routes->add('no_lead', self::route('/x{slash}'));
        $routes->add('no_slash', self::route('/{lead}x'));
        $routes->add('bad_requirement', self::route('/{lead}x{slash}', requirements: ['lead' => '/?', 'slash' => '.*']));
        $routes->add('bad_default', self::route('/{lead}x{slash}', defaults: ['lead' => '/', 'slash' => '']));
        $routes->add('missing_method', self::route('/{lead}x{slash}', methods: ['GET']));
        $routes->add('undeclared_method', self::route('/{lead}x{slash}', methods: ['GET', 'HEAD', 'OPTIONS', 'POST']));
        $routes->add('free_but_utf8', self::route('/{lead}tag/{name}{slash}', requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '[^/]+']));
        $routes->add('free_dot', self::route('/{lead}tag/{name}{slash}', requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '.+']));
        $routes->add('not_a_page', new Route('/anything', ['_controller' => 'Idno\Legacy\LegacyController']));

        $found = PageRouteConvention::violations($routes);

        $named = [];
        foreach ($found as $line) {
            $named[strstr($line, ':', true)][] = $line;
        }
        $this->assertSame(['no_lead', 'no_slash', 'bad_requirement', 'bad_default', 'missing_method', 'undeclared_method', 'free_but_utf8', 'free_dot'], array_keys($named), implode("\n", $found));
    }
}
