<?php

declare(strict_types=1);

namespace Idno\Legacy\Placeholder;

/**
 * What the legacy route table holds for an annotation's address in a layout the site no longer has (`/YYYY/MM/<slug>/annotations/<id>` and
 * `/YYYY/MM/DD/<slug>/annotations/<id>`: an annotation made while a post had another address is shown at the address the post has now, and a link made then
 * is sent there): it names the controller action that answers them with a permanent redirect (`ACTION`). Core registers these two patterns after the
 * annotation routes, new to the table, so that a plugin's registration decides what it does to them as it does for every key. It serves nothing of its own: it
 * is no `Page` and has no method, so a request the router dispatches to it (a method the action does not take) is the router's 404, as for
 * `Idno\Legacy\ControllerRoute`.
 */
final class AnnotationOtherLayout
{
    public const ACTION = [\Idno\Http\Controller\AnnotationController::class, 'otherLayout'];
}
