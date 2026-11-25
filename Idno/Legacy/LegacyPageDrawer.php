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
    public function __construct(private readonly HeaderQueue $php = new PhpHeaders())
    {
    }

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
        $lists = $this->php->listed();
        // Templates read the process's status (the Markdown shell does), as they did when a legacy page had set it; the answer's own
        // status is the response's, so the process's is put back (once the output has begun a status cannot be set: PHP warns)
        $previous = $this->php->status();
        $this->php->setStatus($status);
        $before = $this->php->queued();
        $drawn = null;
        $set = false;
        $changes = null;
        try {
            // The type's headers are sent as `drawPage()` sent them, before the shell is drawn, so that what the draw does to them (a plugin's
            // `header_remove()`, a header added beside one, a replacement) is PHP's own and is read back from its list
            $php = $this->php;
            $drawn = $template->renderPage($status, $shell, $lists ? static function (array $headers) use ($php): void {
                foreach ($headers as [$name, $value]) {
                    $php->send($name . ': ' . $value, ShellHeaders::replaces($name));
                }
            } : null);
            // A status the draw set (`http_response_code()` in a template or a shell) is the answer's
            $set = $this->php->status();
        } finally {
            if (is_int($previous)) {
                $this->php->setStatus($previous);
            } else {
                $this->php->unsetStatus();
            }
            // What was sent, the type's and the draw's alike (also by a draw that failed: the error page that answers is not its page),
            // is the response's to send, once and with the replace or append each had: the lines that are new in PHP's list, taken out
            // of it (what PHP held of those names put back, and a cookie never taken). Not once the draw has flushed output: PHP's list
            // is gone then, and what it held has been sent.
            if ($lists && $this->php->listed()) {
                $changes = AddedHeaders::between($before, $this->php->queued());
                foreach ($changes['remove'] as $name) {
                    $this->php->remove($name);
                }
                foreach ($changes['keep'] as $line) {
                    $this->php->send($line, false);
                }
            }
        }

        if ($changes === null) {
            return new DrawnPage($drawn['body'], $drawn['headers']);
        }

        return new DrawnPage($drawn['body'], $changes['added'], is_int($set) && $set !== $status ? $set : null);
    }
}
