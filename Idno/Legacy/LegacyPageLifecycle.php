<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Core\Logging;
use Idno\Core\TokenProvider;
use Idno\Core\Version;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageLifecycle;
use Idno\Templating\TypeChain;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The page lifecycle of a page a controller serves, over the legacy application: what `Page::init()`, the legacy router's
 * refusal of an API request on an account page and `Page::get()` did, in that order, for a request the router never saw.
 * Built when a site page's request needs it (it needs `Idno::site()`).
 */
final class LegacyPageLifecycle implements PageLifecycle
{
    public function __construct(private readonly HeaderQueue $php = new PhpHeaders())
    {
    }

    public function beginStatelessRequest(PageContext $context): void
    {
        Idno::site()->session()->beginStatelessRequest();
    }

    public function begin(PageContext $context): void
    {
        $site = Idno::site();
        $page = new ControllerPage($context, false);

        // PHP's own X-Powered-By (expose_php) is replaced by the page's, as header() replaced it: the response adds the page's
        // value beside, never over, a header PHP already holds.
        $this->php->remove('X-Powered-By');

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
        // The request for a permalink's Markdown form (the `.md` the router took off its address): what a permalink page's `selectMarkdownIfWanted()` did first
        if ($context->markdownSuffixRequested()) {
            $page->selectMarkdownIfWanted();
        }

        // Templates read the referrer from here, and it is never empty.
        $_SERVER['HTTP_REFERER'] = $_SERVER['HTTP_REFERER'] ?? '';

        $site->embedded();

        $site->events()->triggerEvent('page/ready');

        $this->sendRouterXhrHeaders($context);
    }

    /**
     * What the legacy router sent for an XHR request to a page that has an `<method>_xhr` handler, after the page was built and before anything
     * else: the JSON content type (a default: the page's shell replaces it), the 1997 `Expires`, `Last-Modified` now, a no-store
     * `Cache-Control` of two lines, and `Pragma`. The caching lines replace the session's (`LegacyHeaderReconciler` sends them), as `header()`
     * replaced them. A GET (and the HEAD that answers like it) of a page a controller serves has none of them: it gets the headers the
     * same request that is not XHR gets. OPTIONS never had them.
     */
    private function sendRouterXhrHeaders(PageContext $context): void
    {
        if (!$context->isXhr() || in_array($context->request()->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $context->addDefaultHeader('Content-Type', 'application/json');
        $context->addHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
        // the lines the router sent, one for each `header()` call (`Toro::serve()`), in place of the session's
        $context->setCaching([
            'Expires' => 'Mon, 26 Jul 1997 05:00:00 GMT',
            'Cache-Control' => ['no-store, no-cache, must-revalidate', 'post-check=0, pre-check=0'],
            'Pragma' => 'no-cache',
        ]);
    }

    public function refusesApiRequest(PageContext $context): bool
    {
        $site = Idno::site();
        if (!$site->session()->isAPIRequest() || !($context->page()->accountPage || $site->routes()->isAccountPage($site->currentPage()))) {
            return false;
        }
        $site->logging()->warning('API request to an account page refused: ' . ltrim($context->page()->name, '\\'));
        $site->template()->autodetectTemplateType();
        // the message the legacy router's refusal showed with its 403 page
        $site->session()->addErrorMessage($site->language()->_('Account pages are not available to API requests.'));

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
        // A page that takes state-changing requests without the form token is entered only by a request made stateless first (the switch makes the
        // request nobody's): the token is skipped because the request is an API request (requireFormToken()), never because of what the attribute says.
        if ($context->page()->changesStateWithoutFormToken($context->request()->getMethod()) && !$site->session()->isAPIRequest()) {
            throw new \LogicException('A page with no form token was entered by a request that beginStatelessRequest() had not made nobody\'s.');
        }
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
            // before the page hears of it. A request that is an API request needs none: a signed one, and one made stateless for a page that authenticates its requests itself (`formTokenReplacedBy`).
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
            // A refusal, drawn as the site's 403 page and never reported as a failure (Page::put() threw, which mailed the
            // administrator for every stranger's request).
            throw new AccessDeniedHttpException($site->language()->_('The page you were on timed out.'));
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
