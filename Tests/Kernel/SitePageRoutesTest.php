<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Http\Page\SitePage;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[SitePage('Fixture\RoutesAccount', accountPage: true)]
final class SitePageRoutesAccountController
{
    public function page(): void
    {
    }
}

#[SitePage('Fixture\RoutesAdmin', settingsPage: true)]
final class SitePageRoutesAdminController
{
    public function page(): void
    {
    }
}

#[SitePage('Fixture\RoutesForgotten')]
final class SitePageRoutesForgottenController
{
    public function page(): void
    {
    }
}

#[SitePage('Fixture\RoutesExempt', methods: ['GET', 'POST'], formTokenReplacedBy: 'a test')]
final class SitePageRoutesExemptController
{
    #[IsGranted('ROLE_USER')]
    public function guarded(): void
    {
    }

    public function open(): void
    {
    }
}

/**
 * The flags of #[SitePage] that keep a page closed (an account page is refused to an API request, a settings page is never drawn
 * as Markdown or a script) default to off, so a controller that forgets one would be open. A route under /account or /admin
 * whose controller is a site page must carry the flag: a forgotten flag fails here.
 */
final class SitePageRoutesTest extends TestCase
{
    /**
     * @return list<string> the routes whose path is under /account or /admin and whose #[SitePage] lacks the matching flag
     */
    private static function violations(RouteCollection $routes): array
    {
        $found = [];
        foreach ($routes as $name => $route) {
            $controller = $route->getDefault('_controller');
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page === null) {
                continue;
            }
            // The path as the router accepts it: the optional leading slash, a subdirectory and the trailing slash are placeholders
            $path = preg_replace('~\{(?:lead|slash)\}~', '', $route->getPath());
            $path = ltrim((string) preg_replace('~^(?:/?\{[A-Za-z_]+\})+~', '', (string) $path), '/');
            if (preg_match('~^account(?:/|$)~i', $path) === 1 && !$page->accountPage) {
                $found[] = $name . ' (' . $route->getPath() . '): under /account without accountPage';
            } elseif (preg_match('~^admin(?:/|$)~i', $path) === 1 && !$page->settingsPage && !$page->accountPage) {
                $found[] = $name . ' (' . $route->getPath() . '): under /admin without settingsPage';
            }
        }

        return $found;
    }

    private static function route(string $path, string $controller): Route
    {
        return new Route($path, ['_controller' => $controller . '::page']);
    }

    public function testAForgottenFlagIsFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('ok_account', self::route('/{lead}account/settings{slash}', SitePageRoutesAccountController::class));
        $routes->add('ok_admin', self::route('/admin/email', SitePageRoutesAdminController::class));
        $routes->add('ok_elsewhere', self::route('/somewhere', SitePageRoutesForgottenController::class));
        $routes->add('forgot_account', self::route('/account/password', SitePageRoutesForgottenController::class));
        $routes->add('forgot_lead', self::route('/{lead}account{slash}', SitePageRoutesForgottenController::class));
        $routes->add('forgot_admin', self::route('/{subdirectory}/{lead}admin/{slash}', SitePageRoutesForgottenController::class));
        $routes->add('forgot_upper', self::route('/ADMIN/plugins', SitePageRoutesForgottenController::class));
        $routes->add('admin_with_account_flag', self::route('/admin/x', SitePageRoutesAccountController::class));
        $routes->add('account_with_settings_flag', self::route('/account/x', SitePageRoutesAdminController::class));

        $found = self::violations($routes);

        $this->assertCount(5, $found, implode("\n", $found));
        $this->assertStringStartsWith('forgot_account ', $found[0]);
        $this->assertStringStartsWith('forgot_lead ', $found[1]);
        $this->assertStringStartsWith('forgot_admin ', $found[2]);
        $this->assertStringStartsWith('forgot_upper ', $found[3]);
        $this->assertStringStartsWith('account_with_settings_flag ', $found[4]);
    }

    public function testNoSitePageControllerUnderAccountOrAdminForgetsItsFlag(): void
    {
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        try {
            $routes = $kernel->getContainer()->get('router')->getRouteCollection();
            $sitePages = 0;
            foreach ($routes as $route) {
                $controller = $route->getDefault('_controller');
                if ((is_string($controller) || is_array($controller)) && SitePage::of($controller) !== null) {
                    $sitePages++;
                }
            }

            $this->assertGreaterThan(0, $sitePages, 'the scan saw the routes of site pages (the fixture\'s)');
            $this->assertSame([], self::violations($routes));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return list<string> the routes of an exempt page (one that takes state-changing requests as nobody) whose controller asks for a user or a role
     */
    private static function exemptWithIsGranted(RouteCollection $routes): array
    {
        $found = [];
        foreach ($routes as $name => $route) {
            $controller = $route->getDefault('_controller');
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page === null || $page->formTokenReplacedBy === null) {
                continue;
            }
            [$class, $method] = is_array($controller) ? [is_object($controller[0]) ? $controller[0]::class : $controller[0], $controller[1]] : (str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke']);
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(IsGranted::class);
            if ($reflection->hasMethod($method)) {
                $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(IsGranted::class)];
            }
            if ($attributes !== []) {
                $found[] = $name . ' (' . $class . '::' . $method . '): an exempt page is asked as nobody, so #[IsGranted] would refuse every request';
            }
        }

        return $found;
    }

    public function testAnExemptActionWithIsGrantedIsFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('exempt_guarded', self::route('/x', SitePageRoutesExemptController::class));
        $routes->get('exempt_guarded')->setDefault('_controller', SitePageRoutesExemptController::class . '::guarded');
        $routes->add('exempt_open', new Route('/y', ['_controller' => SitePageRoutesExemptController::class . '::open']));
        $routes->add('not_exempt', self::route('/z', SitePageRoutesForgottenController::class));

        $found = self::exemptWithIsGranted($routes);

        $this->assertCount(1, $found, implode("\n", $found));
        $this->assertStringStartsWith('exempt_guarded ', $found[0]);
    }

    public function testNoExemptActionCarriesIsGranted(): void
    {
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        try {
            $routes = $kernel->getContainer()->get('router')->getRouteCollection();
            $exempt = 0;
            foreach ($routes as $route) {
                $controller = $route->getDefault('_controller');
                if ((is_string($controller) || is_array($controller)) && SitePage::of($controller)?->formTokenReplacedBy !== null) {
                    $exempt++;
                }
            }

            $this->assertGreaterThan(0, $exempt, 'the scan saw an exempt page (the fixture\'s)');
            $this->assertSame([], self::exemptWithIsGranted($routes));
        } finally {
            $kernel->shutdown();
        }
    }
}
