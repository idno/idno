<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * The direct-TLS variant: the web server presents EVERY request as TLS (`HTTPS=on`), including the
 * dispatch call the worker makes, while the configured url is http. This is an artificial mix (a real
 * proxy marks public traffic only; see QueuedDeliverySchemeTest for that). The permalink, the actor
 * and the object's `url`, built in the publishing request, are https; the keyId, built in the worker,
 * follows the configured http url. The object's `id` (an entity uuid) is built from the configured http url as it is.
 */
final class QueuedDeliveryBehindTlsTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_SERVER_HTTPS' => '1'];
    }

    public function testWhenEveryRequestIsPresentedAsTlsTheWorkerBuiltKeyIdStillFollowsTheConfiguredHttpUrl(): void
    {
        $http = $this->baseUrl();
        $https = 'https://' . substr($http, strlen('http://'));
        $post = $https . date('Y') . '/hello-queue';
        $this->assertSame([
            'permalink' => $post,
            'actor' => $https . 'actor/alice',
            'objectId' => $http . 'view/<uuid>',
            'objectUrl' => $post,
            // CHANGED deliberately (was `$https`). The keyId is built where the event runs. It used to run
            // in a web request the worker made, which this server presented as TLS; now it runs in the
            // worker, which has no request, so the site is https exactly when its configured url is.
            // A site served over TLS must have an https `url` configured, or what its worker signs
            // and sends names http.
            'keyId' => $http . 'actor/alice#main-key',
        ], $this->deliveredUrls());
    }
}
