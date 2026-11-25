<?php

declare(strict_types=1);

namespace Idno\Install;

use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * On a site that has no configuration (the kernel did not boot the legacy application and did not
 * ask the schema gate), answers every request before routing:
 *
 *  - `/` (GET, HEAD, POST): the installer (WebInstaller). It has no address of its own, so it works
 *    on a server whose URL rewriting does not, and that failure can be shown;
 *  - a GET or HEAD for a page anywhere else: a redirect to `/`;
 *  - anything else (feeds, webmentions, form posts): 503, `text/plain`.
 *
 * A site whose configuration file exists but cannot be read (or is empty) is NOT offered the
 * installer: every request gets a 503 that says so.
 *
 * Once the site has a configuration this listener does nothing, for any address or method: the
 * installer no longer exists, and the requests it answered are the site's own.
 */
final class InstallGateListener
{
    /** What the entry points that cannot serve the installer (Idno/start.php, the console) say. */
    public const NOT_INSTALLED = 'Idno is not installed yet: load the site in a browser, or run php bin/console idno:install';

    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly KernelInterface $kernel,
        private readonly InstallPaths $paths,
        private readonly WebInstaller $installer,
    )
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 310)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel instanceof Kernel || !$this->kernel->bootsLegacy() || $this->kernel->installed()) {
            return;
        }
        $request = $event->getRequest();
        $method = $request->getRealMethod();
        $wantsPage = ($method === 'GET' || $method === 'HEAD') && (str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html') || !$request->headers->has('Accept'));

        if ($this->paths->existingConfigFiles() !== []) {
            $nonce = base64_encode(random_bytes(18));
            $response = $wantsPage
                ? new Response($method === 'HEAD' ? '' : InstallPage::unreadableConfiguration($nonce), Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => InstallPage::contentSecurityPolicy($nonce)])
                : new Response("Idno cannot read its configuration file.\n", Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } elseif (in_array($request->getPathInfo(), ['/', '/index.php'], true) && in_array($method, ['GET', 'HEAD', 'POST'], true) && $method === $request->getMethod()) {
            $event->setResponse($this->installer->handle($request));

            return;
        } elseif ($wantsPage) {
            $response = new RedirectResponse($request->getBasePath() . '/', Response::HTTP_FOUND);
        } else {
            $response = new Response("Idno is not set up yet.\n", Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '300']);
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $event->setResponse($response);
    }
}
