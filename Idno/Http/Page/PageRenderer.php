<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Templating\ShellHeaders;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draws a site page into a Response: what `DefaultTemplate::drawPage()` echoed, with the headers the template type
 * declares, and the pages a legacy page answered with (not found, gone, refused, an error) and its forward. A controller
 * (or a page service) that serves a site page returns what this makes; nothing is sent and nothing exits.
 */
final class PageRenderer
{
    public function __construct(
        private readonly PageDrawer $drawer,
        private readonly TemplateTranslation $translation,
        private readonly ?ExactShellDrawer $exactShell = null,
    )
    {
    }

    /**
     * The page in its shell. The status is the context's when the caller asks for 200 and the page set another (a page's
     * `setResponse()`); either way the context ends up knowing it, because the templates read the page's status.
     *
     * @param array<string, mixed> $vars the template variables (`title`, `body`, ...)
     */
    public function page(PageContext $context, array $vars, int $status = 200, string $shell = 'shell'): Response
    {
        return $this->respond($context, $status, fn (int $status): DrawnPage => $this->drawer->drawPage($vars, $status, $shell), true);
    }

    /**
     * The page in the shell named, whatever its address: for a page under `/account/` that a visitor sees (the registration page), which `page()` would
     * draw in the settings shell, and which the legacy page drew by itself (`echo`, then `exit`): the answer says no length, as that one did. Every other template type is chosen as for any page, JSONP included (answered as HTML there, never as a script).
     *
     * @param array<string, mixed> $vars the template variables (`title`, `body`, ...)
     */
    public function pageInExactShell(PageContext $context, array $vars, int $status = 200, string $shell = 'shell'): Response
    {
        $exact = $this->exactShell ?? throw new \LogicException('This renderer was built with no ExactShellDrawer.');

        return $this->respond($context, $status, fn (int $status): DrawnPage => $exact->drawPageInExactShell($vars, $status, $shell), false);
    }

    /**
     * One template drawn so that its variables are on no object a page prints (see `ExactShellDrawer::drawTemplateOnACopy()`): for a value that is a
     * credential.
     *
     * @param array<string, mixed> $vars
     */
    public function templateOnACopy(string $name, array $vars): string
    {
        return ($this->exactShell ?? throw new \LogicException('This renderer was built with no ExactShellDrawer.'))->drawTemplateOnACopy($name, $vars);
    }

    /**
     * @param \Closure(int): DrawnPage $draw
     * @param bool                      $withLength whether the answer says its length: a page a legacy page drew directly (`echo`, then `exit`) never did
     */
    private function respond(PageContext $context, int $status, \Closure $draw, bool $withLength): Response
    {
        if ($status === 200 && $context->status() !== 200) {
            $status = $context->status();
        }
        $context->setStatus($status);
        $drawn = $draw($status);
        // A status the draw set itself is the answer's, as it was the process's
        if ($drawn->status !== null) {
            $status = $drawn->status;
            $context->setStatus($status);
        }
        $response = new Response($drawn->body, $status);
        foreach ($drawn->headers as $header) {
            $response->headers->set($header[0], $header[1], $header[2] ?? ShellHeaders::replaces($header[0]));
        }
        // PHP answered a `Location` with a 302 unless the status was a 201 or a redirect's
        if ($response->headers->has('Location')) {
            $response->setStatusCode(PageRedirect::statusWithLocation($status));
        }
        // What a legacy page sent (`Bonita\Templates::drawPage()`): the length of what it drew, in bytes (a HEAD answer keeps it)
        if ($withLength) {
            $response->headers->set('Content-Length', (string) strlen($drawn->body));
        }

        return $response;
    }

    /**
     * One template, drawn with the variables set on the site's template object.
     *
     * @param array<string, mixed> $vars
     */
    public function template(string $name, array $vars): string
    {
        return $this->drawer->drawTemplate($name, $vars);
    }

    public function notFound(PageContext $context): Response
    {
        $context->setStatus(404);

        return $this->page($context, [
            'body' => $this->template('pages/404', []),
            'title' => $this->translation->translate("This page can't be found."),
        ], 404);
    }

    public function gone(PageContext $context): Response
    {
        $context->setStatus(410);

        return $this->page($context, [
            'body' => $this->template('pages/410', []),
            'title' => $this->translation->translate("This content isn't here."),
        ], 410);
    }

    public function denied(PageContext $context, string $title = ''): Response
    {
        $context->setStatus(403);

        return $this->page($context, ['body' => $this->template('pages/403', []), 'title' => $title], 403);
    }

    /**
     * The 500 page of an exception (at another server error status when the exception is an HTTP one that names it). It reports nothing (a legacy `Page::exception()` logged, counted and alerted the administrator: the
     * lifecycle's `reportException()` does, and the caller that answers an exception calls it).
     */
    public function error(PageContext $context, \Throwable $e, int $status = 500): Response
    {
        $context->setStatus($status);

        return $this->page($context, [
            'body' => $this->template('pages/500', ['exception' => $e]),
            'title' => 'Exception',
        ], $status);
    }

    /**
     * What `Page::forward()` did, as a Response: the forward of `forward()` made into the redirect (or the JSON) it is. A forward that
     * does nothing (an XHR POST, PUT or DELETE cannot forward: the page goes on) is an empty answer at the page's status.
     */
    public function redirect(PageContext $context, string $location = '', int $status = 302): Response
    {
        $redirect = $this->forward($context, $location, true, $status, debug_backtrace());

        // Built by PageRedirect::toResponse(), which answers a thrown redirect too, so the two never differ
        return $redirect === null ? new Response('', $context->status()) : $redirect->toResponse();
    }

    /**
     * The one rule of a forward (`Page::forward()`): an empty location is the site's address, a template type other than
     * `default` is carried in `_t`, and an API request (the session's flag) is not redirected but given the JSON `{"location": ...}`
     * (with the `trace` when the site asks for one) at the page's status. The session is ended first when the page ends
     * (`$end`). The result is null when the page cannot forward: an XHR POST, PUT or DELETE does nothing and goes on. The
     * trace of a redirect is the header `X-Idno-Forward-Trace`, added to the context.
     *
     * @param list<array<string, mixed>>|null $frames the frames of `debug_backtrace()` in the code that forwards, for the trace
     */
    public function forward(PageContext $context, string $location = '', bool $end = true, int $status = 302, ?array $frames = null): ?PageRedirect
    {
        $request = $context->request();
        if ($context->isXhr() && in_array($request->getRealMethod(), ['POST', 'PUT', 'DELETE'], true)) {
            return null;
        }
        if ($location === '') {
            $location = $this->drawer->displayUrl();
        }
        $type = $this->drawer->templateType();
        if ($type !== 'default') {
            $location = $this->drawer->urlWithVar('_t', $type, $location);
        }
        if ($end) {
            $this->drawer->finishSession();
        }
        $trace = $this->drawer->forwardTrace($frames ?? debug_backtrace());

        if ($this->drawer->isApiRequest()) {
            $answer = ['location' => $location];
            if ($trace !== null) {
                $answer['trace'] = $trace;
            }

            return new PageRedirect($location, (string) json_encode($answer), $context->status());
        }
        if ($trace !== null) {
            $context->addHeader('X-Idno-Forward-Trace', $trace);
        }

        // A page that had set a 201 or a redirect's status and forwarded kept it (PHP changes any other to a 302: PageRedirect::toResponse())
        $pageStatus = $context->status();

        return new PageRedirect($location, null, $pageStatus === 201 || ($pageStatus >= 300 && $pageStatus <= 399) ? $pageStatus : $status);
    }
}
