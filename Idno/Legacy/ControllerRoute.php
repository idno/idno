<?php

declare(strict_types=1);

namespace Idno\Legacy;

/**
 * What the legacy route table holds for a page that moved to a controller: the table is exactly what it was before the page moved (the same
 * pattern, registered in the same place at the same moment of the boot), with this in place of the page class that was deleted.
 *
 * Keeping core's registration keeps every rule the legacy router applies to the table: a plugin's `addRoute()` of the identical pattern replaces
 * this handler in place, its `hijackRoute()` moves the pattern to the front, an exact key beats every pattern, a pattern a plugin registered before
 * core's still wins and one registered after still loses, and a registration core makes later still overwrites an earlier one. The controller
 * serves the page: `LegacyRouteOverride` asks the legacy router which page it would choose for the request, and a ControllerRoute (or the
 * shell a controller lists in `replaces`) is core's place held, so the controller serves it.
 *
 * It serves nothing of its own: it is no `Page` and has no method, so a request the legacy router dispatches to it (a method the controller does
 * not take, or a spelling Symfony's router did not match) fires Toro's `404` hook, the site's 404 page, for every method, as it did for a path with no
 * registration.
 */
final class ControllerRoute
{
}
