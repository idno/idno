<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * While the schema gate is closed (the legacy application did not boot), answers every request
 * except the upgrade step (a POST to `/_upgrade/step`, UpgradeController) before routing, so that
 * nothing reaches the legacy router:
 *
 *  - a GET or HEAD whose Accept header includes `text/html`: the upgrade page (UpgradePage), status
 *    503, with a Content-Security-Policy that allows only the page's own inline script and style (a
 *    nonce per response); a HEAD gets the headers only;
 *  - anything else (feeds, ActivityPub, webmentions, form posts): status 503, `text/plain`.
 *
 * Both carry `Retry-After: 30` and `Cache-Control: no-store`. The step URL is derived from the
 * request's base path (the site may live in a subdirectory), never from stored configuration, which
 * is not loaded.
 *
 * It acts only when the gate has been asked (Idno\Kernel::boot() asks it in a kernel that boots
 * the legacy application): in a kernel built with bootLegacy: false it does nothing and connects to
 * nothing. It is constructed for the first request event, so it takes nothing that needs the legacy
 * application (no logger): the gate, and the copier as a closure fetched only to show a 1.x copy's
 * progress. Its priority puts it before Symfony's own request listeners (the router at 32, the
 * request validator at 256).
 */
final class UpgradeGateListener
{
    public const RETRY_AFTER = '30';

    /**
     * @param \Closure(): LegacyDataCopier $copier
     */
    public function __construct(
        private readonly SchemaGate $gate,
        #[AutowireServiceClosure(LegacyDataCopier::class)]
        private readonly \Closure $copier,
    )
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 300)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->gate->asked() || !$this->gate->closed()) {
            return;
        }
        $request = $event->getRequest();
        if (UpgradePage::isStepRequest($request)) {
            return;
        }
        $event->setResponse($this->response($request));
    }

    private function response(Request $request): Response
    {
        $status = $this->gate->status();
        $method = $request->getRealMethod();
        $wantsPage = ($method === 'GET' || $method === 'HEAD') && str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html');
        if (!$wantsPage) {
            $response = new Response(UpgradePage::text($status, $this->gate->refusal()), Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } else {
            $nonce = base64_encode(random_bytes(18));
            $progress = null;
            if ($status === SchemaStatus::Legacy) {
                try {
                    $progress = ($this->copier)()->progress();
                } catch (\Throwable) {
                    // The page shows no counts: the script's first step brings them.
                }
            }
            $response = new Response(
                $method === 'HEAD' ? '' : UpgradePage::html($status, $progress, UpgradePage::basePath($request), $nonce, $this->gate->refusal()),
                Response::HTTP_SERVICE_UNAVAILABLE,
                [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Content-Security-Policy' => UpgradePage::contentSecurityPolicy($nonce),
                    'X-Content-Type-Options' => 'nosniff',
                    'Referrer-Policy' => 'no-referrer',
                ],
            );
        }
        $response->headers->set('Retry-After', self::RETRY_AFTER);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
