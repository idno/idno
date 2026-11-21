<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * The scheme (http or https) of the URLs a queued delivery carries, by how the site is told it is
 * https. The site's public URL is built with `Config::getDisplayURL()`, which gives https when the
 * CURRENT request is SSL (`$_SERVER['HTTPS']` on, port 443, or `X-Forwarded-Proto: https`) and http
 * otherwise, whatever the configured `url` says; some URLs (an entity's `uuid`, so the object's `id`)
 * are built from the configured `url` as it is. The activity is built in the publishing request;
 * the signature's keyId is built where the event is dispatched (a web request today, to the site's
 * own plain http address, made by the worker). These cases pin what is, including what looks wrong:
 * a proxy that marks public traffic `X-Forwarded-Proto: https` gives https URLs in the activity and an
 * http keyId, because the worker's dispatch call does not pass through the proxy.
 *
 * Here the configured `url` is http. See QueuedDeliveryConfiguredHttpsTest and
 * QueuedDeliveryBehindTlsTest for the other ways of telling the site.
 */
final class QueuedDeliverySchemeTest extends AsyncSiteTestCase
{
    public function testAPlainHttpSiteBuildsHttpUrlsEverywhere(): void
    {
        $http = $this->baseUrl();
        $post = $http . date('Y') . '/hello-queue';
        $this->assertSame([
            'permalink' => $post,
            'actor' => $http . 'actor/alice',
            'objectId' => $http . 'view/<uuid>',
            'objectUrl' => $post,
            'keyId' => $http . 'actor/alice#main-key',
        ], $this->deliveredUrls());
    }

    /**
     * Only the publish request is marked https (as a proxy does for public traffic); the worker
     * reaches the origin plain. The object's `id` (an entity uuid, from the configured http url)
     * and the keyId stay http.
     */
    public function testAProxyMarkingThePublishRequestHttpsGivesHttpsUrlsButAnHttpKeyId(): void
    {
        $http = $this->baseUrl();
        $https = 'https://' . substr($http, strlen('http://'));
        $post = $https . date('Y') . '/hello-queue';
        $this->assertSame([
            'permalink' => $post,
            'actor' => $https . 'actor/alice',
            'objectId' => $http . 'view/<uuid>',
            'objectUrl' => $post,
            'keyId' => $http . 'actor/alice#main-key',
        ], $this->deliveredUrls(['X-Forwarded-Proto' => 'https']));
    }
}
