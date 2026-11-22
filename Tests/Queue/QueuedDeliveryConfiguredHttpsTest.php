<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * A site whose configured `url` is https (in its boot configuration) while every request it
 * receives, the worker's included, is plain http (as when a proxy terminates TLS and talks http to
 * the origin without marking the request). See QueuedDeliverySchemeTest for what decides the scheme.
 * Pinned as it is, odd parts included: the object's `id` (an entity uuid) is built from the
 * configured url as it is, so is https; the permalink, the actor and the object's `url` are built in
 * the publishing request from the display url, which follows that request, so are http when it is plain.
 * The keyId is built in the worker, which has no request: the site is https there exactly when its
 * configured url is (it used to follow the plain-http request the worker made to the site).
 */
final class QueuedDeliveryConfiguredHttpsTest extends AsyncSiteTestCase
{
    protected function configuredScheme(): string
    {
        return 'https';
    }

    public function testAnHttpsConfiguredUrlWithPlainRequests(): void
    {
        $http = $this->baseUrl();
        $https = 'https://' . substr($http, strlen('http://'));
        $post = $http . date('Y') . '/hello-queue';
        $this->assertSame([
            'permalink' => $post,
            'actor' => $http . 'actor/alice',
            'objectId' => $https . 'view/<uuid>',
            'objectUrl' => $post,
            // CHANGED deliberately (was `$http`): the keyId is built where the event runs. That used to be
            // a plain-http web request made by the worker; it is now the worker, which has no request to
            // say how it was made, so the site is https exactly when its configured url is, and it is.
            'keyId' => $https . 'actor/alice#main-key',
        ], $this->deliveredUrls());
    }

    /**
     * The publish request marked https as well: every URL built is https (the keyId too, since the
     * worker went on as the configured https url says).
     */
    public function testAnHttpsConfiguredUrlWithAProxyHeaderOnThePublishRequest(): void
    {
        $http = $this->baseUrl();
        $https = 'https://' . substr($http, strlen('http://'));
        $post = $https . date('Y') . '/hello-queue';
        $this->assertSame([
            'permalink' => $post,
            'actor' => $https . 'actor/alice',
            'objectId' => $https . 'view/<uuid>',
            'objectUrl' => $post,
            // CHANGED deliberately (was `$http`), for the reason given in the test above: the worker has no
            // request, so the configured https url decides.
            'keyId' => $https . 'actor/alice#main-key',
        ], $this->deliveredUrls(['X-Forwarded-Proto' => 'https']));
    }
}
