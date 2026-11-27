<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * What a page that a visitor sees under `/account/` (the registration page, the password reset request) needs beyond `PageDrawer`: its web page drawn in
 * the site's own shell whatever its address (`drawPage()` would choose the settings shell for an address under `/account/`, which is for members), and a
 * template drawn so that none of its variables stays on the site's template object. `Idno\Legacy\LegacyPageDrawer` implements both; `PageDrawer` is not
 * changed, so each double of it stays as it is.
 */
interface ExactShellDrawer
{
    /**
     * What `PageDrawer::drawPage()` does, but a page of the `default` type is drawn in the shell named, whatever its address (every other type is chosen as
     * it is for any page: a JSONP request of an `/account` address is still answered as the HTML settings page, never as a script).
     *
     * @param array<string, mixed> $vars
     */
    public function drawPageInExactShell(array $vars, int $status, string $shell): DrawnPage;

    /**
     * One template drawn with these variables on a copy of the site's template object: the variables go to this draw alone, and no page printing every
     * variable of its template (JSON, JSONP) can show them. For values that are credentials (an invitation's code).
     *
     * @param array<string, mixed> $vars
     */
    public function drawTemplateOnACopy(string $name, array $vars): string;
}
