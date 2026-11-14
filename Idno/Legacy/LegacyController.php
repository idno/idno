<?php

declare(strict_types=1);

namespace Idno\Legacy;

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
    public function __construct(private readonly LegacyDispatcher $dispatcher)
    {
    }

    // utf8: false, unlike every other route (config/packages/framework.php): a path that is
    // not valid UTF-8 must still reach the legacy router, as it did before.
    #[Route('/{path}', name: 'idno_legacy', requirements: ['path' => '.*'], priority: -1000, utf8: false)]
    public function __invoke(): LegacyResponse
    {
        return $this->response();
    }

    /**
     * The response that runs the legacy router.
     */
    public function response(): LegacyResponse
    {
        $dispatcher = $this->dispatcher;

        return new LegacyResponse(static fn () => $dispatcher->dispatch());
    }
}
