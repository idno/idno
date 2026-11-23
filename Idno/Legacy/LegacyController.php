<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The lowest-priority route: every request that no other route matches, for any path and
 * any HTTP method, is served by the legacy router (Idno\Common\Page classes).
 *
 * The legacy code runs while the response is sent, after the kernel has returned it, and
 * reads the request from the superglobals as it always has.
 */
#[AsController]
final class LegacyController
{
    public function __construct(private readonly LegacyDispatcher $dispatcher, private readonly RequestStack $requests)
    {
    }

    // utf8: false, unlike every other route (config/packages/framework.php): a path that is
    // not valid UTF-8 must still reach the legacy router, as it did before.
    #[Route('/{path}', name: 'idno_legacy', requirements: ['path' => '.*'], priority: -1000, utf8: false)]
    public function __invoke(Request $request): LegacyResponse
    {
        return $this->response($request);
    }

    /**
     * The response that runs the legacy router, with the request on Symfony's request stack while it runs.
     */
    public function response(?Request $request = null): LegacyResponse
    {
        $dispatcher = $this->dispatcher;

        return new LegacyResponse(static fn () => $dispatcher->dispatch(), $this->requests, $request);
    }
}
