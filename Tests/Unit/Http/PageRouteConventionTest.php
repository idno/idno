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

    public function testTheTagRoutesExemptionIsNarrowAndItStillFailsOnEverythingElse(): void
    {
        $build = static function (string $path, array $requirements, array $defaults, array $methods = ['GET', 'HEAD', 'OPTIONS'], array $options = ['utf8' => false]): RouteCollection {
            $routes = new RouteCollection();
            $routes->add('idno_tag', new Route($path, ['_controller' => RoutesConventionController::class . '::page'] + $defaults, $requirements, $options, '', [], $methods));

            return $routes;
        };
        $good = fn (): array => PageRouteConvention::violations($build('/{lead}tag/{tag}', ['lead' => '/?', 'tag' => '[^\s]+'], ['lead' => '']));

        $this->assertSame([], $good(), 'exactly what the route needs: no {slash}, the last parameter takes the rest of the path');
        // it still fails on {lead}, the methods and the requirements
        $this->assertNotSame([], PageRouteConvention::violations($build('/tag/{tag}', ['tag' => '[^\s]+'], [])), 'no {lead}');
        $this->assertNotSame([], PageRouteConvention::violations($build('/{lead}tag/{tag}', ['lead' => '/?', 'tag' => '[^\s]+'], ['lead' => ''], ['GET'])), 'methods');
        $this->assertNotSame([], PageRouteConvention::violations($build('/{lead}tag/{tag}', ['lead' => '.*', 'tag' => '[^\s]+'], ['lead' => ''])), 'lead requirement');
        $this->assertNotSame([], PageRouteConvention::violations($build('/{lead}tag/{tag}', ['lead' => '/?', 'tag' => '[^\s]+'], ['lead' => ''], options: [])), 'a free tag without utf8: false');
        // narrowed: a {slash} after the tag is the ambiguity the exemption exists for, a path that does not end with a parameter has no reason, and the
        // exemption is one route's, not a name any other takes
        $this->assertNotSame([], PageRouteConvention::violations($build('/{lead}tag/{tag}{slash}', ['lead' => '/?', 'slash' => '/?', 'tag' => '[^\s]+'], ['lead' => '', 'slash' => ''])), 'an exempt route has no {slash}');
        $this->assertNotSame([], PageRouteConvention::violations($build('/{lead}tag/{tag}/more', ['lead' => '/?', 'tag' => '[^\s]+'], ['lead' => ''])), 'it ends with its last parameter');
        $this->assertNotSame([], PageRouteConvention::violations($build('/{lead}tag/{tag}', ['lead' => '/?', 'tag' => '[^/]+'], ['lead' => ''])), 'the last parameter takes the rest of the path');
        $other = new RouteCollection();
        $other->add('another_route', new Route('/{lead}tag/{tag}', ['_controller' => RoutesConventionController::class . '::page', 'lead' => ''], ['lead' => '/?', 'tag' => '[^\s]+'], ['utf8' => false], '', [], ['GET', 'HEAD', 'OPTIONS']));
        $this->assertNotSame([], PageRouteConvention::violations($other), 'another route is not exempt');
    }
}
