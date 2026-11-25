<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Discovery;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * The small files a site serves from its root: `robots.txt` (a template, so a plugin may extend it), the web app manifest and the service
 * worker script. Each is an answer in the site's own terms (the walled garden and the page events are the lifecycle's), not a page in the shell.
 */
final class SiteFiles
{
    /** The service worker script that ships with a release, relative to the project */
    public const SERVICE_WORKER = 'js/service-worker.min.js';

    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly SiteIcons $icons,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
    }

    /**
     * `robots.txt`: the `txt/robots` template, as plain text.
     */
    public function robots(PageContext $page): Response
    {
        return new Response($this->renderer->template('txt/robots', []), 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * The web app manifest: the site's name, its icons and where the app starts.
     */
    public function manifest(PageContext $page): Response
    {
        return new Response((string) json_encode($this->manifestData(), JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * The service worker script (at `service-worker.js` and at `service-worker.min.js`): the one that ships in `js/`. A release without it is a
     * 404, never a server error.
     */
    public function serviceWorker(PageContext $page): Response
    {
        $script = @file_get_contents($this->projectDir . '/' . self::SERVICE_WORKER);
        if ($script === false) {
            return $this->renderer->notFound($page);
        }

        return new Response($script, 200, ['Content-Type' => 'application/javascript']);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestData(): array
    {
        $icons = $this->icons->icons();

        // The application's scope matches the service worker's
        $scope = '/';
        $url = parse_url($this->icons->displayUrl());
        if (!empty($url['path'])) {
            $scope = $url['path'];
        }

        $manifest = [
            'name' => $this->icons->title(),
            'short_name' => $this->icons->title(),
            'icons' => [],
            'start_url' => $this->icons->displayUrl() . 'session/login',
            'display' => 'standalone',
            'scope' => $scope,
        ];

        foreach (['36', '48', '72', '96', '144', '192'] as $size) {
            $source = (string) $icons['defaults']['default_' . $size];
            $parts = explode('.', $source);
            $manifest['icons'][] = [
                'src' => $source,
                'sizes' => $size . 'x' . $size,
                'type' => 'image/' . end($parts),
            ];
        }

        return $manifest;
    }
}
