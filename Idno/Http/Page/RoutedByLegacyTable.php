<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * Marks a controller action that is routed by the legacy route table and has no `#[Route]`: its address is one the site decides at run time (a
 * permalink's pattern is a stored setting, and plugins register their own at boot), which a compiled route cannot say. The action carries its
 * `#[SitePage]` as any page's, and is NAMED by exactly one legacy class's public constant `ACTION` (`[Controller::class, 'method']`): its kept shell
 * or, for a page whose class was deleted, a placeholder of its own under `Idno\Legacy\Placeholder\`. `Idno\Legacy\LegacyRoutedPages` hands a request
 * to the action when the legacy router itself would have served the request with that class, with the arguments it would have been called with.
 *
 * `debug:router` does not list these pages; `Tests/Kernel/fixtures/routed-pages.php` does.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RoutedByLegacyTable
{
    /**
     * @param string $route the route's name, as a `#[Route]` has one: what the request's `_route` is while the action serves
     * @throws \InvalidArgumentException for a name that is not `idno_` and lower-case letters, digits and underscores
     */
    public function __construct(public readonly string $route)
    {
        if (preg_match('/^idno_[a-z0-9_]+$/', $route) !== 1) {
            throw new \InvalidArgumentException('The route of a page routed by the legacy table is named idno_ and lower-case letters, digits and underscores.');
        }
    }
}
