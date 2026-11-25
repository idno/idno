<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Http\Page\AddedHeaders;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageDrawer;
use Idno\Templating\ShellHeaders;

/**
 * Draws a site page with the site's template object (`Idno::site()->template()`), as a legacy page did
 * (`$t->__([...])->draw(...)`, `->drawPage()`), over the legacy application. Built when the page renderer is; it reads the
 * application only when something is drawn.
 */
final class LegacyPageDrawer implements PageDrawer
{
    public function templateType(): string
    {
        return (string) Idno::site()->template()->getTemplateType();
    }

    public function isApiRequest(): bool
    {
        return (bool) Idno::site()->session()->isAPIRequest();
    }

    public function finishSession(): void
    {
        Idno::site()->session()->finishEarly();
    }

    public function forwardTrace(?array $frames): ?string
    {
        $site = Idno::site();
        if (empty($site->config()->forward_trace) || empty($frames[1])) {
            return null;
        }
        $trace = '';
        if (!empty($frames[0])) {
            $trace .= "Forward at {$frames[0]['file']}:{$frames[0]['line']}";
        }
        $file = !empty($frames[1]['file']) ? $frames[1]['file'] : 'UNKNOWN';
        $line = !empty($frames[1]['line']) ? $frames[1]['line'] : 'xxx';
        $trace .= ", called by {$frames[1]['function']} in {$file}:{$line}";
        $site->logging()->debug($trace);

        return $trace;
    }

    public function displayUrl(): string
    {
        return (string) Idno::site()->config()->getDisplayURL();
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return (string) Idno::site()->template()->getURLWithVar($name, $value, $url);
    }

    public function drawTemplate(string $name, array $vars): string
    {
        return (string) Idno::site()->template()->__($vars)->draw($name);
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $template = Idno::site()->template()->__($vars);
        // PHP keeps a list of the headers `header()` sets under a web server (not the CLI), and refuses them once the output has begun
        $lists = !in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && !headers_sent();
        // Templates read the process's status (the Markdown shell does), as they did when a legacy page had set it; the answer's own
        // status is the response's, so the process's is put back (a process that has none yet answers 200: the CLI's is false).
        $previous = http_response_code();
        http_response_code($status);
        $before = headers_list();
        try {
            // The type's headers are sent as `drawPage()` sent them, before the shell is drawn, so that what the draw does to them (a plugin's
            // `header_remove()`, a header added beside one, a replacement) is PHP's own and is read back from its list
            $drawn = $template->renderPage($status, $shell, $lists ? static function (array $headers): void {
                foreach ($headers as [$name, $value]) {
                    header($name . ': ' . $value, ShellHeaders::replaces($name));
                }
            } : null);
            $after = headers_list();
            // A status the draw set (`http_response_code()` in a template or a shell) is the answer's
            $set = http_response_code();
        } finally {
            http_response_code(is_int($previous) ? $previous : 200);
        }

        if (!$lists) {
            return new DrawnPage($drawn['body'], $drawn['headers']);
        }

        // What was sent, the type's and the draw's alike, is the response's to send, once and with the replace or append each had: the lines
        // that are new in PHP's list, taken out of it (what PHP held of those names put back, and a cookie never taken)
        $changes = AddedHeaders::between($before, $after);
        foreach ($changes['remove'] as $name) {
            header_remove($name);
        }
        foreach ($changes['keep'] as $line) {
            header($line, false);
        }

        return new DrawnPage($drawn['body'], $changes['added'], is_int($set) && $set !== $status ? $set : null);
    }
}
