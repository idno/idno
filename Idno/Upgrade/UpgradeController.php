<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /_upgrade/step`: advances the upgrade by one bounded step (UpgradeRunner::step(), with a
 * deadline five seconds from now) and reports where it stands, for the upgrade page's script.
 *
 * It changes state without authentication or CSRF protection, the one exception to the rule for
 * new-style controllers: while the schema gate is closed nobody can log in (the legacy application
 * does not boot), and anyone who loads the site must be able to finish its upgrade. It is safe by
 * construction:
 *
 *  - it takes no input that selects what it does: only the presentation flag `?html=1` is read
 *    (the body, the other parameters and the headers are ignored);
 *  - with the gate open it does nothing but report `current` (no migration, no index, no copy, no
 *    lock; the runner is not even built);
 *  - every call does bounded work (the deadline), and concurrent calls are serialised by the
 *    UpgradeLock: the one that does not get it reports `working` and does nothing;
 *  - every response carries `Cache-Control: no-store`; with `?html=1` (the page's form, without
 *    JavaScript) it is a 303 to the site's own root under the request's base path, never to a URL
 *    from the request (no Referer, no parameter): no open redirect;
 *  - nothing from the database reaches the response except as JSON-encoded text (the HTML page
 *    escapes it): the status, the copy's phase and per-collection counts, and the copier's stored
 *    error; never a document or a rejected row.
 *
 * The JSON: `{"status": "current"|"working"|"too_old"|"unsupported"|"error", "message": ?string,
 * "phase": ?string, "collections": {<name>: {"source", "copied", "rejected", "done"}}, "error":
 * ?string}`, HTTP 500 for `error` (a failure the page shows and stops at), 200 otherwise. `message`
 * and `error` are PublicMessage texts only: a failure's private detail (an exception or driver
 * message, a record's uuid) is never in the response; it is logged on the `upgrade` channel and
 * shown by `idno:upgrade`. An exception the runner did not handle is such an error too.
 *
 * While the database is unreachable (the gate is open, but Idno\Kernel does not boot the legacy
 * application for a step) it answers 503 with status `working` and PublicMessage::unreachable(),
 * `Retry-After: 30`: the page keeps retrying with its back-off. It never reports `current` then.
 *
 * The step's time budget is UpgradeRunner::STEP_SECONDS; the test environment's container may set
 * a shorter one from IDNO_TEST_UPGRADE_STEP_SECONDS (config/services_test.php), which no request
 * can reach.
 */
#[AsController]
final class UpgradeController
{
    /**
     * @param \Closure(): UpgradeRunner $runner
     */
    public function __construct(
        private readonly SchemaGate $gate,
        #[AutowireServiceClosure(UpgradeRunner::class)]
        private readonly \Closure $runner,
        private readonly LoggerInterface $logger,
        private readonly float $stepSeconds = UpgradeRunner::STEP_SECONDS,
    )
    {
    }

    #[Route(UpgradePage::STEP_PATH, name: 'idno_upgrade_step', methods: ['POST'])]
    public function step(Request $request): Response
    {
        $unreachable = $this->gate->status() === SchemaStatus::Unreachable;
        if ($unreachable) {
            $result = ['status' => 'working', 'progress' => null, 'message' => PublicMessage::unreachable()->text];
        } elseif (!$this->gate->closed()) {
            $result = ['status' => 'current', 'progress' => null, 'message' => null];
        } else {
            try {
                $result = ($this->runner)()->step(microtime(true) + $this->stepSeconds);
            } catch (\Throwable $e) {
                $this->logger->error('The upgrade step failed: ' . LegacyDataCopier::clean($e::class . ': ' . $e->getMessage()));
                $result = ['status' => 'error', 'progress' => null, 'message' => PublicMessage::stopped()->text];
            }
        }

        if ($request->query->get('html') === '1') {
            $response = new RedirectResponse(UpgradePage::basePath($request) . '/', Response::HTTP_SEE_OTHER);
        } else {
            $progress = $result['progress'] instanceof UpgradeProgress ? $result['progress'] : null;
            $response = new JsonResponse(
                [
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'phase' => $progress?->phase,
                    'collections' => (object) ($progress?->collections ?? []),
                    'error' => $progress?->error,
                ],
                match (true) {
                    $unreachable => Response::HTTP_SERVICE_UNAVAILABLE,
                    $result['status'] === 'error' => Response::HTTP_INTERNAL_SERVER_ERROR,
                    default => Response::HTTP_OK,
                },
            );
            $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES);
        }
        if ($unreachable) {
            $response->headers->set('Retry-After', UpgradeGateListener::RETRY_AFTER);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
