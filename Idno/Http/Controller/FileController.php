<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\File\FilePages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A stored file, at `/file/<id>` and `/file/<id>/<name>` (the name is what a download is called): served on a private site too, as the legacy page was
 * registered, and streamed (see `FilePages`). Core still registers the legacy pattern `/file/(:id)(/.*)?`, held by `Idno\Legacy\ControllerRoute`, so that the
 * legacy router's rules decide what a plugin's registration does to it. The addresses that look like a file's (`/file/upload`, `/file/picker`, `/file/mint`)
 * are the legacy router's own pages, registered before it, and a request for one of them is handed back to it.
 */
#[AsController]
final class FileController
{
    public function __construct(private readonly FilePages $pages)
    {
    }

    #[Route('/{lead}file/{id}{rest}', name: 'idno_file', requirements: ['lead' => '/?', 'id' => '[A-Za-z0-9\-]+', 'rest' => '(?:/.*)?'], defaults: ['lead' => '', 'rest' => ''], methods: ['GET', 'HEAD', 'OPTIONS'], utf8: false)]
    #[SitePage(name: 'Idno\Pages\File\View', publicOnPrivateSite: true)]
    public function serve(PageContext $page, string $id, string $rest = ''): Response
    {
        return $this->pages->serve($page, $id, $rest);
    }
}
