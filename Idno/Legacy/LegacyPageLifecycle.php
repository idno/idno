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
        $page = new ControllerPage($context);

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
    }

    public function refusesApiRequest(PageContext $context): bool
    {
        $site = Idno::site();
        if (!$site->session()->isAPIRequest() || !$site->routes()->isAccountPage($site->currentPage())) {
            return false;
        }
        $site->logging()->warning('API request to an account page refused: ' . ltrim($context->page()->name, '\\'));
        $site->template()->autodetectTemplateType();
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
        $site->template()->autodetectTemplateType();

        $site->events()->triggerEvent('page/head', ['page' => $site->currentPage()]);
        $site->events()->triggerEvent('page/get', ['page_class' => $context->page()->name, 'arguments' => $context->arguments()]);
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
