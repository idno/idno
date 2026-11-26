<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\PageHandler;
use Idno\Http\Page\RoutedByLegacyTable;
use Idno\Http\Page\SitePage;
use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves a request with a controller action that is routed by the legacy route table (`#[RoutedByLegacyTable]`), for the addresses the site decides at
 * run time: a permalink's pattern is a stored setting and plugins register their own at boot, so no compiled `#[Route]` can say them.
 *
 * It acts on a request Symfony matched to the catch-all (`idno_legacy`: the legacy router's, a request `LegacyRouteOverride` handed back included) and asks the
 * legacy router what it will do with it (`PageHandler::choiceFor()`: the page class, the arguments, the `.md` rule, an exact key first and then the patterns in
 * order, Toro's own lookup) ONCE: the answer is kept on the request, and the dispatcher that serves a legacy page reads it, giving Toro the one entry
 * it chose, so a legacy request pays one lookup of the table, not two. When the class chosen is a page that NAMES an action (it declares the public
 * constant `ACTION` itself: a placeholder of its own, or a kept shell and not a subclass of one, which is served through the shell, as a plugin's page), the
 * action carries `#[RoutedByLegacyTable]` and its `#[SitePage]` takes the request's method, the request is routed to it: `_controller`, `_route`, the route's
 * parameters (`argument0`, `argument1`, ...: Toro's captured groups, in order) and the Markdown flag of the `.md` rule. Any other request is left as it was, and
 * so are the methods the action does not take (the real method and the one an override header names: Toro serves the first).
 *
 * It does nothing, and builds nothing, when this kernel did not boot the legacy application, and for a request that is not the catch-all's: the dispatcher
 * is a service closure, fetched only for a request it acts on.
 */
final class LegacyRoutedPages
{
    /** @var array<string, ?array{0: class-string, 1: string}> the action each class names itself, by class */
    private static array $actions = [];

    /**
     * @param \Closure(): LegacyDispatcher $dispatcher
     */
    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        #[AutowireServiceClosure(LegacyDispatcher::class)]
        private readonly \Closure $dispatcher,
    )
    {
    }

    // After RouterListener (32) and LegacyRouteOverride (31), which may hand a request to the catch-all; before the firewall (8)
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted()) {
            return;
        }
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'idno_legacy') {
            return;
        }

        $choice = PageHandler::choiceFor(($this->dispatcher)()->routes(), $request);
        if ($choice === []) {
            return;
        }
        $action = self::actionOf($choice['class']);
        if ($action === null) {
            return;
        }
        $routed = self::routedBy($action);
        $page = SitePage::of($action);
        if ($routed === null || $page === null || !$page->allows($request->getMethod()) || !$page->allows($request->getRealMethod())) {
            return;
        }

        $parameters = [];
        foreach ($choice['arguments'] as $position => $value) {
            $parameters['argument' . $position] = $value;
        }
        $request->attributes->remove('path');
        $request->attributes->add($parameters + [
            '_controller' => $action[0] . '::' . $action[1],
            '_route' => $routed->route,
            '_route_params' => $parameters,
            '_idno_legacy_routed' => true,
            '_idno_markdown_suffix' => $choice['markdown'],
        ]);
    }

    /**
     * The action a page class names in its own `ACTION`: the class declares the constant itself (a subclass inherits it and is no page of that action), is no
     * deprecated shell but with its own class as `SHELL`, and the constant is not null. Null for anything else.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private static function actionOf(string $class): ?array
    {
        if (array_key_exists($class, self::$actions)) {
            return self::$actions[$class];
        }
        $action = null;
        if (class_exists($class) && defined($class . '::ACTION')) {
            $reflection = new \ReflectionClass($class);
            $constant = $reflection->getReflectionConstant('ACTION');
            $value = $constant !== false && $constant->getDeclaringClass()->getName() === $reflection->getName() ? $constant->getValue() : null;
            if (is_array($value) && count($value) === 2 && is_string($value[0]) && is_string($value[1])) {
                $own = true;
                if ($reflection->isSubclassOf(PageShell::class)) {
                    $shell = $reflection->getReflectionConstant('SHELL');
                    $own = $shell !== false && $shell->getValue() === $reflection->getName();
                }
                if ($own) {
                    $action = [$value[0], $value[1]];
                }
            }
        }

        return self::$actions[$class] = $action;
    }

    /**
     * The `#[RoutedByLegacyTable]` of an action, null when the method has none (or does not exist).
     *
     * @param array{0: class-string, 1: string} $action
     */
    private static function routedBy(array $action): ?RoutedByLegacyTable
    {
        if (!class_exists($action[0]) || !method_exists($action[0], $action[1])) {
            return null;
        }
        foreach ((new \ReflectionMethod($action[0], $action[1]))->getAttributes(RoutedByLegacyTable::class) as $attribute) {
            return $attribute->newInstance();
        }

        return null;
    }
}
