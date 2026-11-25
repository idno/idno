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
        // status is the response's, so the process's is put back. Once the output has begun the status cannot be set (PHP warns).
        $settable = !headers_sent();
        $previous = http_response_code();
        if ($settable) {
            http_response_code($status);
        }
        $before = headers_list();
        $drawn = null;
        $set = false;
        $changes = null;
        try {
            // The type's headers are sent as `drawPage()` sent them, before the shell is drawn, so that what the draw does to them (a plugin's
            // `header_remove()`, a header added beside one, a replacement) is PHP's own and is read back from its list
            $drawn = $template->renderPage($status, $shell, $lists ? static function (array $headers): void {
                foreach ($headers as [$name, $value]) {
                    header($name . ': ' . $value, ShellHeaders::replaces($name));
                }
            } : null);
            // A status the draw set (`http_response_code()` in a template or a shell) is the answer's
            $set = http_response_code();
        } finally {
            if ($settable) {
                self::restoreStatus($previous);
            }
            if ($lists) {
                // What was sent, the type's and the draw's alike (also by a draw that failed: the error page that answers is not its page),
                // is the response's to send, once and with the replace or append each had: the lines that are new in PHP's list, taken out
                // of it (what PHP held of those names put back, and a cookie never taken)
                $changes = AddedHeaders::between($before, headers_list());
                foreach ($changes['remove'] as $name) {
                    header_remove($name);
                }
                foreach ($changes['keep'] as $line) {
                    header($line, false);
                }
            }
        }

        if ($changes === null) {
            return new DrawnPage($drawn['body'], $drawn['headers']);
        }

        return new DrawnPage($drawn['body'], $changes['added'], is_int($set) && $set !== $status ? $set : null);
    }

    /**
     * Puts the process's status back. A process that had none (the CLI's is false) has none again: PHP has no call that unsets it, and a
     * status line of no code does.
     */
    private static function restoreStatus(int|bool $previous): void
    {
        if (is_int($previous)) {
            http_response_code($previous);
        } elseif (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            header('HTTP/1.1 0');
        } else {
            http_response_code(200);
        }
    }
}
