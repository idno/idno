<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * What a legacy page did around its handler, for a page a controller serves: the same work, in the same order, with the
 * same effects (the events plugins listen to, the template type, the walled garden). The listener that runs it
 * (PageRequestListener) is the only caller; Idno\Legacy\LegacyPageLifecycle implements it over the legacy application.
 */
interface PageLifecycle
{
    /**
     * Makes the request one that is nobody's, before the page is begun: for a page whose state-changing requests are authenticated by
     * something other than the form token (`SitePage::$formTokenReplacedBy`). The legacy session's user is set aside for the request
     * (nothing is written to the session), so no event, template or controller sees the browser's user.
     */
    public function beginStatelessRequest(PageContext $context): void;

    /**
     * Starts the page, as `Page::init()` did: the page becomes the current page, a referrer is never empty, the template type
     * follows `_t` (the page's default type when the request names none), the embedded flag is read and `page/ready` fires.
     */
    public function begin(PageContext $context): void;

    /**
     * Whether the page is an account page and the request an API request: refused, with the message and the warning the legacy
     * router gave.
     */
    public function refusesApiRequest(PageContext $context): bool;

    /**
     * Whether the visitor may see the page: the site is public, or the visitor is logged on, or the page is public on a private site.
     */
    public function mayView(PageContext $context): bool;

    /**
     * What a page's GET did once it let the visitor in: the template type from `_t`, `Accept`, then `page/head` and `page/get`.
     */
    public function enter(PageContext $context): void;

    /**
     * The headers every page sends, by name.
     *
     * @return array<string, string>
     */
    public function standardHeaders(): array;

    /**
     * Reports an exception a page did not handle as a legacy page did: the log, the statistic and the alert to the administrator.
     * Never throws.
     */
    public function reportException(\Throwable $e): void;
}
