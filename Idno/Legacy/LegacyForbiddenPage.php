<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Kernel;
use Idno\Security\ForbiddenPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The legacy 403 page (templates `pages/403` in the page shell, as Page::deniedContent() draws it), as a Response
 * instead of output and an exit. It needs a current page for the shell: a page that does nothing (the home page, as
 * PageHandler uses to refuse an API request) is made for that. Null when this kernel did not boot the legacy
 * application.
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
        $page = new \Idno\Pages\Homepage();
        $page->setResponse(403);
        $site->template()->autodetectTemplateType();
        $template = $site->template();
        $html = $template->__(['body' => $template->draw('pages/403'), 'title' => ''])->drawPage(false);

        return new Response((string) $html, 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
