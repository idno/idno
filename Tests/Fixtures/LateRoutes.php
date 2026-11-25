<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Test-environment only (config/services_test.php: `kernel.request` at priority 1000, before the router): the "plugin" registrations
 * that come AFTER every core component has registered its pages, as a page registered by a plugin's late hook (or by a core component built
 * after the plugins, as `Webfinger` is) would be. `IDNO_TEST_CLAIM_ROUTES` names the mode, as for TestEventHooks (`moved-identical`,
 * `moved-hijack`, `moved-exact`). Inert without it, and for a request that is not the legacy router's.
 */
final class LateRoutes
{
    public function __invoke(RequestEvent $event): void
    {
        $mode = getenv('IDNO_TEST_CLAIM_ROUTES');
        if (!is_string($mode) || !str_starts_with($mode, 'moved-') || Idno::site() === null) {
            return;
        }
        $routes = Idno::site()->routes();
        match ($mode) {
            'moved-identical' => $routes->addRoute('/\.well\-known/webfinger/?', ClaimingPage::class),
            'moved-hijack' => $routes->hijackRoute('/\.well\-known/webfinger/?', ClaimingPage::class),
            'moved-exact' => $routes->addRoute('/.well-known/webfinger', ClaimingPage::class),
            default => null,
        };
    }
}
