<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * The direct-TLS variant: the web server presents EVERY request as TLS (`HTTPS=on`), including the
 * dispatch call the worker makes, while the configured url is http. This is an artificial mix (a real
 * proxy marks public traffic only; see QueuedDeliverySchemeTest for that), and pins the all-https
 * outcome: the permalink, the actor, the object's `url` and the keyId are https. The worker is a
 * command-line process and is never SSL, yet its delivery arrives, because the service token ignores
 * the scheme. The object's `id` (an entity uuid) is built from the configured http url as it is.
 */
final class QueuedDeliveryBehindTlsTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_SERVER_HTTPS' => '1'];
    }

    public function testWhenEveryRequestIsPresentedAsTlsEveryUrlButTheObjectIdIsHttps(): void
    {
        $http = $this->baseUrl();
        $https = 'https://' . substr($http, strlen('http://'));
        $post = $https . date('Y') . '/hello-queue';
        $this->assertSame([
            'permalink' => $post,
            'actor' => $https . 'actor/alice',
            'objectId' => $http . 'view/<uuid>',
            'objectUrl' => $post,
            'keyId' => $https . 'actor/alice#main-key',
        ], $this->deliveredUrls());
    }
}
