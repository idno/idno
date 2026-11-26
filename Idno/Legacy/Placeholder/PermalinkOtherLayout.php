<?php

declare(strict_types=1);

namespace Idno\Legacy\Placeholder;

/**
 * What the legacy route table holds for a post's address in a layout the site no longer has (`/YYYY/MM/<slug>` and `/YYYY/MM/DD/<slug>`, the two other layouts
 * a site could choose before a post's address had one): it names the controller action that answers them with a permanent redirect to the post's own address
 * (`ACTION`). Core registers these two patterns beside the permalink's, new to the table, so that a plugin's registration decides what it does to them as it does
 * for every key. It serves nothing of its own: it is no `Page` and has no method, so a request the router dispatches to it (a method the action does not take)
 * is the router's 404, as for `Idno\Legacy\ControllerRoute`.
 */
final class PermalinkOtherLayout
{
    /**
     * @var array{0: class-string, 1: string}|null
     */
    public const ACTION = [\Idno\Http\Controller\PermalinkController::class, 'otherLayout'];
}
