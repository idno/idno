<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): /_test/mail/log returns the mail the site
 * has sent so far, recorded by RecordingMailTransport, as a JSON list (oldest first) and empties the
 * record, as /_test/sink/{name}/log does for requests.
 */
final class MailLogController
{
    #[Route('/_test/mail/log', name: 'idno_test_mail_log')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(RecordingMailTransport::drain(), 200, ['Cache-Control' => 'no-store']);
    }
}
