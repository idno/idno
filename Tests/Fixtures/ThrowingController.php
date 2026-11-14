<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): a new-style controller that
 * throws, so tests can check what happens to an exception Symfony handles.
 */
final class ThrowingController
{
    public const MESSAGE = 'thrown by the test-only controller';

    #[Route('/_test/throw', name: 'idno_test_throw')]
    public function __invoke(): Response
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
