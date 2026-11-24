<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Security\SessionUser;
use Idno\Security\StatelessAuthentication;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Test-environment only (config/routes/test/fixtures.php): who Symfony Security says is making the request, and a
 * route only an administrator reaches, for Tests\Kernel\ControllerAuthorizationTest and the stateless authentication
 * tests. Answers are never cached.
 */
#[AsController]
final class WhoAmIController
{
    public function __construct(private readonly Security $security)
    {
    }

    #[Route('/_test/whoami', name: 'idno_test_whoami')]
    public function whoAmI(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        return $this->json([
            'uuid' => $user?->getUserIdentifier(),
            'roles' => $user?->getRoles() ?? [],
            'handle' => $user instanceof SessionUser ? $user->getHandle() : null,
            'stateless' => StatelessAuthentication::isStateless($request),
        ]);
    }

    #[Route('/_test/admin-only', name: 'idno_test_admin_only')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminOnly(): JsonResponse
    {
        return $this->json(['admin' => true]);
    }

    #[Route('/_test/member-only', name: 'idno_test_member_only')]
    #[IsGranted('ROLE_USER')]
    public function memberOnly(): JsonResponse
    {
        return $this->json(['member' => true]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
