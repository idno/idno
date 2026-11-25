<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Kernel;
use Idno\Security\ForbiddenPage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The legacy 403 page (templates `pages/403` in the page shell, as Page::deniedContent() draws it), as a Response
 * instead of output and an exit. It needs a current page for the shell: a page that does nothing (the home page, as
 * PageHandler uses to refuse an API request) is made for that, unless the request is a page a controller serves, whose own
 * current page is used. A request answered in JSON is refused with `{"error":"forbidden"}`. Null when this kernel did not boot the legacy
 * application, and for any other template type but the web page's (the caller answers in plain text). Drawing it
 * uses up no message of the session and sends no header.
 */
final class LegacyForbiddenPage implements ForbiddenPage
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function render(Request $request): ?Response
    {
        if (!$this->kernel->legacyBooted() || !Idno::site() instanceof Idno) {
            return null;
        }
        $site = Idno::site();
        // The page of a controller that is being refused is the current page, as Page::deniedContent() drew it in the page it
        // refused; any other request has none, and a page that does nothing stands in.
        $page = $site->currentPage() instanceof ControllerPage ? $site->currentPage() : new BarePage();
        $page->setResponse(403);
        $site->template()->autodetectTemplateType();
        $template = $site->template();
        if (strtolower((string) $template->getTemplateType()) === 'json') {
            // A request that is answered in JSON (a signed API request, whatever it asked for) is refused in JSON.
            return new JsonResponse(['error' => 'forbidden'], 403);
        }
        if (strtolower((string) $template->getTemplateType()) !== 'default') {
            // A feed, KML or any other type has no 403 page of its own to draw here: the responder answers it plainly.
            return null;
        }
        // Drawn without the side effects of drawing a page for the visitor: the messages waiting in the session stay
        // there, and no header (the cross-origin one) is sent from here.
        $html = $template->__(['body' => $template->draw('pages/403'), 'title' => ''])->drawPageWithoutSideEffects(false);

        return new Response((string) $html, 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
