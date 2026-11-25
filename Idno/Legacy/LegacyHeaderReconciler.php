<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Http\Page\PageContext;
use Idno\Kernel;
use Idno\Security\DeniedResponder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the headers of a site page that a controller serves the ones a legacy page sends.
 *
 * A legacy page's caching headers come from PHP: its session cache limiter queues `Expires`, `Cache-Control` and `Pragma` at
 * `session_start()` (a visitor's request too), and the page adds `Last-Modified` with `header()`. A Symfony `Response` always has a
 * `Cache-Control` of its own (computed, sorted, with `private` added) and a `Date`, and `Response::sendHeaders()` sends every header
 * but `Content-Type` beside what PHP holds, so without this rule a page would send two `Cache-Control` lines. The rule, for each
 * response to a site page (a main request, the legacy application booted, a page context, and not a response the legacy code writes
 * itself):
 *
 * - `Cache-Control`, `Pragma`, `Expires`: never the response's (they are taken off it). PHP's lines are sent as PHP queued them,
 *   except for a name the page asked for with `PageContext::setCaching()`: PHP's lines of that name are replaced by exactly the
 *   lines the page asked for, in order (`setNoCache()` sent two `Cache-Control` lines, and left the session's `Expires`). Where PHP
 *   keeps no list (the command line) the response carries what the page asked for.
 * - `Last-Modified`: the response's, when it has one (PHP's queued line is removed); else PHP's.
 * - `Content-Type`: the response's; a response with none and a type that declares none (a third party's) takes PHP's queued line,
 *   because a response with none gets `text/html` when it is prepared and sends it replacing PHP's.
 * - caching set any other way (a header of the page's context, a `Cache-Control`, `Pragma` or `Expires` a controller set on its response, a
 *   `#[Cache]` attribute) is honoured as the page asking for it, name by name, unless the page asked for that name itself: its value is
 *   sent as given, in place of PHP's lines of that name. A `Cache-Control` equal to the one a response computes by itself
 *   (`no-cache, private`, or `must-revalidate, private` once it has `Last-Modified`) is no ask. The rule runs a second time after
 *   Symfony's cache attribute listener (priority -10), which adds its header after the first.
 * - an empty answer (a 204, as OPTIONS is) is given the `Content-Type` PHP would have sent it: a response that is prepared drops its own
 *   and tells PHP to send none, where a legacy page's answer had PHP's default.
 * - everything else (`Link` from `page/head`, `Vary`, `X-*`), `Date` and `Set-Cookie`: untouched, the response's lines beside PHP's.
 *
 * The listener is registered after the page's own headers are on the response (`PageRequestListener::onResponse()`) and before
 * Symfony's `ResponseListener`, which prepares it. `X-Robots-Tag: noindex`, which Symfony adds to every response in debug mode (and the
 * legacy router never sent), is taken off a site page's response only when it was not there before Symfony's listener ran: a page that
 * says `noindex` itself keeps it, in every environment.
 */
final class LegacyHeaderReconciler
{
    private const CACHING = ['Cache-Control', 'Pragma', 'Expires'];

    /** What a response computes for its own `Cache-Control` when nothing set one: not a page's ask */
    private const COMPUTED_CACHE_CONTROL = ['no-cache, private', 'must-revalidate, private', 'private, must-revalidate'];

    private const ROBOTS_BEFORE = '_idno_robots_tag_before';

    private const DEFAULT_MIMETYPE = '_idno_default_mimetype';

    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        private readonly HeaderQueue $php,
    )
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 8)]
    public function onResponse(ResponseEvent $event): void
    {
        $context = $this->contextOf($event);
        if ($context === null) {
            return;
        }
        // The listener cannot tell what type the page was drawn in (that is the template object's): a response that has no
        // Content-Type is filled from PHP's queue, which holds one only when a shell sent its own.
        $this->reconcile($event->getResponse(), $context, false);
    }

    /**
     * After Symfony's cache attribute listener (-10): what it, or anything before, set on the response is honoured as the page's ask.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
    public function onLateResponse(ResponseEvent $event): void
    {
        $context = $this->contextOf($event);
        if ($context === null) {
            return;
        }
        $this->reconcile($event->getResponse(), $context, true);
        // an empty answer was prepared with PHP's default type switched off for the process: back as it was
        $default = $event->getRequest()->attributes->get(self::DEFAULT_MIMETYPE);
        if (is_string($default)) {
            ini_set('default_mimetype', $default);
            $event->getRequest()->attributes->remove(self::DEFAULT_MIMETYPE);
        }
    }

    /**
     * Just before Symfony's `DisallowRobotsIndexingListener` (-255): whether the response already says anything of indexing.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -250)]
    public function noteRobotsTag(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::ROBOTS_BEFORE, $event->getResponse()->headers->has('X-Robots-Tag'));
        }
    }

    /**
     * Symfony's `DisallowRobotsIndexingListener` (priority -255) adds `X-Robots-Tag: noindex` to a response that has none, in debug mode.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -300)]
    public function removeRobotsTag(ResponseEvent $event): void
    {
        $context = $this->contextOf($event);
        if ($context === null || $event->getRequest()->attributes->get(self::ROBOTS_BEFORE) !== false) {
            return;
        }
        $response = $event->getResponse();
        if ($response->headers->get('X-Robots-Tag') === 'noindex') {
            $response->headers->remove('X-Robots-Tag');
        }
    }

    /**
     * @param bool $knownType the page was drawn in a template type that declares its headers (`ShellHeaders`), so the response has its
     *                        `Content-Type` and PHP's queue is not asked for one
     */
    public function reconcile(Response $response, PageContext $context, bool $knownType): void
    {
        $queue = $this->php->listed();
        $queued = $queue ? $this->php->queued() : [];
        if ($context->request()->attributes->get(DeniedResponder::ATTRIBUTE) !== true) {
            // (a refusal's own `no-store` is the responder's and not the page's: a site page's 403 is sent with the session's lines, as a legacy page's was)
            $this->honour($response, $context);
        }
        $asked = $context->cachingLines();

        // Never the response's own: it computes a Cache-Control (and adds `private`), and Symfony sends it beside PHP's
        foreach (self::CACHING as $name) {
            $response->headers->remove($name);
        }
        foreach ($asked as $name => $lines) {
            if (!$queue) {
                // nothing to send into: the response carries them (one line a name; Symfony makes its own of Cache-Control)
                $response->headers->set($name, $lines);
                continue;
            }
            $this->php->remove($name);
            foreach (array_values($lines) as $position => $line) {
                $this->php->send($name . ': ' . $line, $position === 0);
            }
        }

        if ($queue && $response->headers->has('Last-Modified') && self::holds($queued, 'Last-Modified')) {
            $this->php->remove('Last-Modified');
        }
        if ($queue && !$knownType && !$response->headers->has('Content-Type')) {
            $line = self::first($queued, 'Content-Type');
            if ($line !== null) {
                $response->headers->set('Content-Type', $line);
                $this->php->remove('Content-Type');
            } elseif ($response->isEmpty() && ($default = $this->php->defaultContentType()) !== null) {
                // Preparing an empty response drops its type and switches PHP's default off, for the process: so the default is sent
                // by name here, and the setting is put back after the response is prepared (onLateResponse())
                $this->php->send('Content-Type: ' . $default, true);
                $context->request()->attributes->set(self::DEFAULT_MIMETYPE, (string) ini_get('default_mimetype'));
            }
        }
    }

    /**
     * Caching set on the response (or as a header of the context) is the page asking for it, for each name the page did not ask for with
     * `setCaching()`: the value is the page's, sent as given. A `Cache-Control` the response computed by itself is no ask.
     */
    private function honour(Response $response, PageContext $context): void
    {
        $asked = $context->caching();
        foreach (self::CACHING as $name) {
            if (!$response->headers->has($name) || array_key_exists($name, $asked)) {
                continue;
            }
            $lines = array_values($response->headers->all($name));
            if ($name === 'Cache-Control' && count($lines) === 1 && in_array($lines[0], self::COMPUTED_CACHE_CONTROL, true)) {
                continue;
            }
            $context->setCaching([$name => $lines]);
        }
    }

    private function contextOf(ResponseEvent $event): ?PageContext
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted() || !PageContext::has($request) || $event->getResponse() instanceof LegacyResponse) {
            return null;
        }

        return PageContext::of($request);
    }

    /**
     * @param list<string> $lines
     */
    private static function holds(array $lines, string $name): bool
    {
        return self::first($lines, $name) !== null;
    }

    /**
     * The value of the first line of a name.
     *
     * @param list<string> $lines
     */
    private static function first(array $lines, string $name): ?string
    {
        foreach ($lines as $line) {
            $position = strpos($line, ':');
            if ($position !== false && strcasecmp(trim(substr($line, 0, $position)), $name) === 0) {
                return trim(substr($line, $position + 1));
            }
        }

        return null;
    }
}
