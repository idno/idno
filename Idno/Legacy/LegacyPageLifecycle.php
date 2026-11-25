<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Exceptions\SecurityException;
use Idno\Core\Logging;
use Idno\Core\TokenProvider;
use Idno\Core\Version;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageLifecycle;
use Idno\Templating\TypeChain;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The page lifecycle of a page a controller serves, over the legacy application: what `Page::init()`, the legacy router's
 * refusal of an API request on an account page and `Page::get()` did, in that order, for a request the router never saw.
 * Built when a site page's request needs it (it needs `Idno::site()`).
 */
final class LegacyPageLifecycle implements PageLifecycle
{
    public function begin(PageContext $context): void
    {
        $site = Idno::site();
        $page = new ControllerPage($context, false);

        // PHP's own X-Powered-By (expose_php) is replaced by the page's, as header() replaced it: the response adds the page's
        // value beside, never over, a header PHP already holds.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        $defaultType = $context->page()->defaultType;
        if ($defaultType !== null && empty($context->input('_t'))) {
            $context->setInput('_t', $defaultType);
        }
        if ($template = $page->getInput('_t')) {
            if (is_string($template)) {
                $template = TypeChain::requested($template);
            }
            if (is_string($template) && TypeChain::requestable($template) && $site->template()->templateTypeExists($template)) {
                $site->template()->setTemplateType($template);
            }
        }
        $site->setCurrentPage($page);

        // Templates read the referrer from here, and it is never empty.
        $_SERVER['HTTP_REFERER'] = $_SERVER['HTTP_REFERER'] ?? '';

        $site->embedded();

        $site->events()->triggerEvent('page/ready');

        $this->sendRouterXhrHeaders($context);
    }

    /**
     * What the legacy router sent for an XHR request to a page that has an `<method>_xhr` handler (every method but OPTIONS), after
     * the page was built and before anything else: the JSON content type (a default: the page's shell replaces it), the 1997
     * `Expires`, `Last-Modified` now, a no-store `Cache-Control` with the two legacy directives, and `Pragma`. They replace the
     * headers PHP's session already holds, as `header()` replaced them.
     */
    private function sendRouterXhrHeaders(PageContext $context): void
    {
        if (!$context->isXhr() || $context->request()->getMethod() === 'OPTIONS') {
            return;
        }
        if (!headers_sent()) {
            foreach (['Expires', 'Cache-Control', 'Pragma'] as $name) {
                header_remove($name);
            }
        }
        $context->addDefaultHeader('Content-Type', 'application/json');
        $context->addHeader('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
        $context->addHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
        $context->setCaching(['Cache-Control' => 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0', 'Pragma' => 'no-cache']);
    }

    public function refusesApiRequest(PageContext $context): bool
    {
        $site = Idno::site();
        if (!$site->session()->isAPIRequest() || !($context->page()->accountPage || $site->routes()->isAccountPage($site->currentPage()))) {
            return false;
        }
        $site->logging()->warning('API request to an account page refused: ' . ltrim($context->page()->name, '\\'));
        $site->template()->autodetectTemplateType();

        return true;
    }

    public function mayView(PageContext $context): bool
    {
        $site = Idno::site();
        if ($site->config()->isPublicSite() || $site->session()->isLoggedOn()) {
            return true;
        }

        return $site->routes()->isRoutePublic($site->currentPage());
    }

    public function enter(PageContext $context): void
    {
        $site = Idno::site();
        $page = $site->currentPage();
        if ($page instanceof ControllerPage) {
            // What `<method>_xhr()` did once the visitor was let in: the page is an XHR page from here on.
            $page->startXhr();
        }
        $site->template()->autodetectTemplateType();

        $method = $context->request()->getMethod();
        $data = ['page_class' => $context->page()->name, 'arguments' => $context->arguments()];
        if ($method === 'HEAD') {
            // A legacy HEAD said `page/head` with the class and the arguments, and nothing after it.
            $site->events()->triggerEvent('page/head', $data);

            return;
        }
        $site->events()->triggerEvent('page/head', ['page' => $page]);
        if ($method !== 'GET') {
            // A method that changes state is answered only with the session's form token, as Page::post() and put() asked for it:
            // before the page hears of it. A signed API request needs none.
            $this->requireFormToken($method, $page);
        }
        $site->events()->triggerEvent('page/' . strtolower($method), $data);
    }

    private function requireFormToken(string $method, mixed $page): void
    {
        $site = Idno::site();
        if ($site->session()->isAPIRequest() || $site->actions()->validateToken((string) $page->currentUrl(), false) || $site->actions()->validateToken('', false)) {
            return;
        }
        if ($method !== 'POST') {
            throw new SecurityException($site->language()->_('The page you were on timed out.'));
        }
        $site->logging()->error($site->language()->_('Invalid token.'));
        $site->session()->addErrorMessage($site->language()->_('Invalid token.'));
        $page->forward();
        // an XHR page does not forward: it is refused
        throw new AccessDeniedException();
    }

    public function standardHeaders(): array
    {
        if (defined('IDNO_UNIT_TEST')) {
            return [];
        }

        return [
            'X-Powered-By' => 'https://idno.co',
            'X-Clacks-Overhead' => 'GNU Terry Pratchett',
            'X-Idno-Build-Fingerprint' => TokenProvider::truncateToken(Version::fingerprint()),
        ];
    }

    public function reportException(\Throwable $e): void
    {
        $message = $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']';
        try {
            $site = Idno::site();
            $site->logging()->critical($message);
            $statistics = $site->statistics();
            if (!empty($statistics)) {
                $statistics->increment('error.exception');
            }
            Logging::oopsAlert($message, get_class($e));
        } catch (\Throwable) {
            // Reporting an exception never raises another.
        }
    }
}
