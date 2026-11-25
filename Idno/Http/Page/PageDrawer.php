<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * What PageRenderer needs of the site's templates, over the legacy application (Idno\Legacy\LegacyPageDrawer implements it):
 * the template type, a template drawn, and a page drawn in its shell with the headers its type declares.
 */
interface PageDrawer
{
    /**
     * The template type the current page is drawn in (`default`, `json`, `rss`, ...).
     */
    public function templateType(): string;

    /**
     * Whether the request is an API request: authenticated by a stateless credential (the session's own flag, which stays true
     * when the credential left the request anonymous), so it is answered with JSON and never redirected.
     */
    public function isApiRequest(): bool;

    /**
     * Ends the session (a page that forwards and ends closes it before the answer, as `finishEarly()` did).
     */
    public function finishSession(): void;

    /**
     * The trace of a forward, when the site asks for it (`forward_trace`): "Forward at <file>:<line>, called by <function> in
     * <file>:<line>", from the frames of `debug_backtrace()` taken in the code that forwards (the frame list is that of the
     * caller of the forward), also written to the debug log; null when it does not.
     *
     * @param list<array<string, mixed>>|null $frames
     */
    public function forwardTrace(?array $frames): ?string;

    /**
     * The site's address, as a page forwards to it when it names no location.
     */
    public function displayUrl(): string;

    /**
     * The address with a query variable set (what a forward does to carry the template type).
     */
    public function urlWithVar(string $name, string $value, string $url): string;

    /**
     * Sets the variables on the site's template object, then draws the template (the variables stay set, as a legacy page's
     * `$t->__([...])->draw()` left them).
     *
     * @param array<string, mixed> $vars
     */
    public function drawTemplate(string $name, array $vars): string;

    /**
     * Sets the variables, takes the session's messages and flushes them, closes the session and draws the shell, with the
     * headers of the template type for an answer of the status, then the ones the draw sent itself (a plugin's extension of
     * `shell/headers`, a third-party shell), each with the replace flag it had; it sends none, and leaves none in the process's
     * list.
     *
     * @param array<string, mixed> $vars
     */
    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage;
}
