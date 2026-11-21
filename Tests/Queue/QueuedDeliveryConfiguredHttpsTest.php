<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * A site whose configured `url` is https (in its boot configuration) while every request it
 * receives, the worker's included, is plain http (as when a proxy terminates TLS and talks http to
 * the origin without marking the request). See QueuedDeliverySchemeTest for what decides the scheme.
 * Pinned as it is today, odd parts included: the object's `id` (an entity uuid) is built from the
 * configured url as it is, so is https; the permalink, the actor, the object's `url` and the keyId
 * are built from the display url, which follows the request, so are http when it is plain.
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
            'keyId' => $http . 'actor/alice#main-key',
        ], $this->deliveredUrls());
    }

    /**
     * The publish request marked https as well: every URL built then is https but the keyId, built
     * where the plain worker's call is dispatched.
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
            'keyId' => $http . 'actor/alice#main-key',
        ], $this->deliveredUrls(['X-Forwarded-Proto' => 'https']));
    }
}
