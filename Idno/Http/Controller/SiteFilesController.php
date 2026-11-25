<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Discovery\SiteFiles;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `robots.txt`, the web app manifest and the service worker script: three pages that were
 * legacy classes (each `#[SitePage]` names the class whose page it is). The manifest and the service worker are public on a private site (a browser fetches them
 * without the visitor's cookie); `robots.txt` is not, as it was.
 */
#[AsController]
final class SiteFilesController
{
    public function __construct(private readonly SiteFiles $files)
    {
    }

    #[Route('/{lead}robots.txt{slash}', name: 'idno_robots', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Txt\Robots', legacyPatterns: ['/robots\.txt'])]
    public function robots(PageContext $page): Response
    {
        return $this->files->robots($page);
    }

    #[Route('/{lead}chrome/manifest.json{slash}', name: 'idno_manifest', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Chrome\Manifest', publicOnPrivateSite: true, legacyPatterns: ['/chrome/manifest\.json'])]
    public function manifest(PageContext $page): Response
    {
        return $this->files->manifest($page);
    }

    /**
     * The script that ships (`js/service-worker.min.js`) at both of its old addresses.
     */
    #[Route('/{lead}service-worker.js{slash}', name: 'idno_service_worker', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[Route('/{lead}service-worker.min.js{slash}', name: 'idno_service_worker_min', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: 'Idno\Pages\Chrome\ServiceWorker', publicOnPrivateSite: true, legacyPatterns: ['/service-worker(\.min)?\.js'])]
    public function serviceWorker(PageContext $page): Response
    {
        return $this->files->serviceWorker($page);
    }
}
