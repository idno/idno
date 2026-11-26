<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\PageHandler;
use Idno\Legacy\LegacyController;
use Idno\Legacy\LegacyDispatcher;
use Idno\Legacy\LegacyRoutedPages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tests\Fixtures\ClaimingPage;
use Tests\Fixtures\RoutedFixtureController;
use Tests\Fixtures\RoutedFixtureShell;
use Tests\Fixtures\RoutedPlaceholder;
use Tests\Fixtures\SubclassedRoutedShell;
use Tests\Support\RoutedActions;

/** A table that counts how many times it is walked */
final class CountingRouteTable extends PageHandler
{
    public int $walks = 0;

    public function rewind(): void
    {
        ++$this->walks;
        parent::rewind();
    }
}

/**
 * The listener that hands a request the legacy router will serve with a page that names a controller action to that action
 * (`LegacyRoutedPages`), and the rules that keep it honest: no routed action has a `#[Route]`, each is named by one class, the listener leaves every other
 * request alone and asks the table once, and a permalink is routed under each structure the site can be configured with.
 */
final class LegacyRoutedPagesTest extends TestCase
{
    private function table(): CountingRouteTable
    {
        $table = new CountingRouteTable();
        $table->addRoute('/r/:id/?', RoutedPlaceholder::class);
        $table->addRoute('/s/:id/.*', RoutedFixtureShell::class);
        $table->addRoute('/sub/:id/.*', SubclassedRoutedShell::class);
        $table->addRoute('/claimed/:id/?', ClaimingPage::class);

        return $table;
    }

    private function listener(PageHandler $table): LegacyRoutedPages
    {
        $dispatcher = new LegacyDispatcher($table, Idno::site()->config());

        return new LegacyRoutedPages(Idno::container()->get('kernel'), static fn (): LegacyDispatcher => $dispatcher);
    }

    /**
     * What the listener does to the request the catch-all would have served.
     *
     * @param array<string, string> $server
     */
    private function served(PageHandler $table, string $uri, string $method = 'GET', array $server = [], string $route = 'idno_legacy'): Request
    {
        $request = Request::create($uri, $method, [], [], [], $server);
        $request->attributes->add(['_controller' => LegacyController::class, '_route' => $route, '_route_params' => ['path' => ltrim($uri, '/')], 'path' => ltrim($uri, '/')]);
        $this->listener($table)->onRequest(new RequestEvent(Idno::container()->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST));

        return $request;
    }

    public function testNoRoutedActionHasARoute(): void
    {
        $actions = RoutedActions::actions();

        $this->assertNotSame([], $actions, 'the fixtures are found');
        foreach ($actions as $route => $action) {
            $this->assertFalse($action['hasRoute'], $route . ' (' . $action['action'] . ') has a #[Route]: the legacy table routes it');
            $this->assertTrue($action['hasPage'], $route . ' has a #[SitePage]');
        }
    }

    public function testEveryRoutedActionIsNamedByExactlyOneClass(): void
    {
        $namers = RoutedActions::namers();

        foreach (RoutedActions::actions() as $route => $action) {
            $names = array_keys(array_filter($namers, static fn (array $named): bool => $named[0] . '::' . $named[1] === $action['action']));
            $this->assertCount(1, $names, $route . ' is named by ' . json_encode($names));
            $this->assertSame($action['shell'], is_subclass_of($names[0], \Idno\Legacy\PageShell::class), $route . ': a shell names the action of a page that says shell, a placeholder the others');
        }
        $this->assertSame([RoutedFixtureController::class, 'show'], $namers[RoutedPlaceholder::class]);
        $this->assertArrayNotHasKey(SubclassedRoutedShell::class, $namers, 'a subclass inherits the constant and is no page of the action');
    }

    public function testTheListenerRoutesTheRequestTheRouterWouldGiveToAPageThatNamesAnAction(): void
    {
        $request = $this->served($this->table(), '/r/abc');

        $this->assertSame(RoutedFixtureController::class . '::show', $request->attributes->get('_controller'));
        $this->assertSame('idno_test_routed', $request->attributes->get('_route'));
        $this->assertSame(['argument0' => 'abc'], $request->attributes->get('_route_params'));
        $this->assertSame('abc', $request->attributes->get('argument0'));
        $this->assertTrue($request->attributes->get('_idno_legacy_routed'));
        $this->assertFalse($request->attributes->get('_idno_markdown_suffix'));
        $this->assertFalse($request->attributes->has('path'), 'the catch-all\'s parameter is gone');

        $shell = $this->served($this->table(), '/s/abc/anything.md');
        $this->assertSame(RoutedFixtureController::class . '::showShell', $shell->attributes->get('_controller'));
        $this->assertSame(['argument0' => 'abc'], $shell->attributes->get('_route_params'));
        $this->assertTrue($shell->attributes->get('_idno_markdown_suffix'), 'the suffix rule is the router\'s, a Markdown permalink');
    }

    public function testTheListenerLeavesEveryOtherRequestAlone(): void
    {
        $table = $this->table();
        $cases = [
            'a request matched to a #[Route]' => ['/r/abc', 'GET', [], 'idno_home'],
            'a legacy page' => ['/claimed/abc', 'GET', [], 'idno_legacy'],
            'a subclass of a routed shell' => ['/sub/abc/x', 'GET', [], 'idno_legacy'],
            'nothing' => ['/nothing/here', 'GET', [], 'idno_legacy'],
            'a method the page does not allow' => ['/r/abc', 'POST', [], 'idno_legacy'],
            'a PUT' => ['/s/abc/x', 'PUT', [], 'idno_legacy'],
            'a POST overridden to GET' => ['/r/abc', 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET'], 'idno_legacy'],
        ];
        foreach ($cases as $label => [$uri, $method, $server, $route]) {
            $request = $this->served($table, $uri, $method, $server, $route);
            $this->assertSame(LegacyController::class, $request->attributes->get('_controller'), $label);
            $this->assertSame($route, $request->attributes->get('_route'), $label);
            $this->assertFalse($request->attributes->has('_idno_legacy_routed'), $label);
            $this->assertTrue($request->attributes->has('path'), $label . ': the catch-all\'s parameter stays');
        }
    }

    public function testTheListenerAsksTheTableOnceAndTheDispatcherIsGivenTheOneEntry(): void
    {
        $table = $this->table();
        $request = $this->served($table, '/claimed/abc');

        $this->assertSame(1, $table->walks, 'the table was walked once for the request');
        $dispatcher = new LegacyDispatcher($table, Idno::site()->config());
        $choice = $dispatcher->recordedChoice($request);
        $this->assertSame(ClaimingPage::class, $choice['class'] ?? null);
        $this->assertSame('/claimed/([A-Za-z0-9\-]+)/?', $choice['pattern']);
        $this->assertSame(['abc'], $choice['arguments']);
        $this->assertSame(1, $table->walks, 'asking for the recorded choice walks nothing');

        // the choice of another method or path is not this request's
        $other = Request::create('/claimed/abc', 'POST');
        $other->attributes->add($request->attributes->all());
        $this->assertNull($dispatcher->recordedChoice($other), 'recorded for a GET, so not the choice of a POST');
        $this->assertNull($dispatcher->recordedChoice(Request::create('/claimed/abc')), 'a request that nothing asked about has none');

        // what Toro is given: one entry, or none (an `[]` choice); a request without a choice is given the whole table
        $real = Idno::site()->routes();
        $this->assertSame(['/session/login' => $real['/session/login']], self::entriesToroSees(['pattern' => '/session/login']), 'the entry the router chose, with its own handler');
        $this->assertSame([], self::entriesToroSees([]), 'no entry for a path the router has no page for');
        $this->assertSame(iterator_to_array($real), self::entriesToroSees(null), 'the whole table without a choice');
    }

    /**
     * The table the router is given when the real dispatcher serves a request with this choice (a hook of the router, `before_request`,
     * reads it and stops the request before any page runs).
     *
     * @param array<string, mixed>|null $choice
     *
     * @return array<string, mixed> pattern => handler
     */
    private static function entriesToroSees(?array $choice): array
    {
        $dispatcher = Idno::container()->get(LegacyDispatcher::class);
        $saved = $_SERVER;
        $hooks = new \ReflectionProperty(\ToroHook::class, 'hooks');
        $instance = \ToroHook::get_instance();
        $before = $hooks->getValue($instance);
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['PATH_INFO'] = '/session/login';
            $_SERVER['REQUEST_URI'] = '/session/login';
            unset($_SERVER['ORIG_PATH_INFO']);
            PageHandlerHookProbe::arm();
            $dispatcher->dispatch($choice);
        } catch (PageHandlerHookProbe $seen) {
            return $seen->entries;
        } finally {
            $hooks->setValue($instance, $before);
            $_SERVER = $saved;
        }
        self::fail('the router did not reach its first hook');
    }

    public function testThePermalinkIsRoutedUnderEachStructure(): void
    {
        $config = Idno::site()->config();
        $saved = $config->permalink_structure;
        $created = 1700000000;
        try {
            foreach (['/:year/:slug' => 'Y', '/:year/:month/:slug' => 'Y/m', '/:year/:month/:day/:slug' => 'Y/m/d'] as $structure => $dates) {
                $config->permalink_structure = $structure;
                $table = new PageHandler();
                $table->addRoute(Entity::getPermalinkRoute(), \Idno\Pages\Entity\View::class);

                // the address Entity::getURL() builds for an entity with this slug and creation time
                $entity = new \Idno\Entities\GenericDataItem();
                $entity->created = $created;
                $entity->slug = 'hello-world';
                $path = (string) parse_url($entity->getURL(), PHP_URL_PATH);
                $this->assertSame('/' . date($dates, $created) . '/hello-world', $path, $structure . ': the address the entity builds');
                $routed = PageHandler::routedFor($table, 'GET', $path);

                $this->assertSame(\Idno\Pages\Entity\View::class, $routed['class'] ?? null, $structure);
                $this->assertSame(['hello-world'], $routed['arguments'], $structure . ': the slug alone is captured');
                $this->assertNull(PageHandler::routedFor($table, 'GET', '/other/' . 'hello-world/x/y/z'), $structure);
            }
        } finally {
            $config->permalink_structure = $saved;
        }
    }
}

/** Stops the router at its first hook, saying how many entries its table has */
final class PageHandlerHookProbe extends \RuntimeException
{
    /** @var array<string, mixed> */
    public array $entries = [];

    public static function arm(): void
    {
        \ToroHook::add('before_request', static function (array &$params): void {
            $probe = new self();
            $probe->entries = is_array($params['routes']) ? $params['routes'] : iterator_to_array($params['routes']);
            throw $probe;
        });
    }
}
