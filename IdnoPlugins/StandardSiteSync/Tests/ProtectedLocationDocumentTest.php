<?php

declare(strict_types=1);

namespace IdnoPlugins\StandardSiteSync\Tests;

use IdnoPlugins\Checkin\Checkin;
use IdnoPlugins\StandardSiteSync\ATProtoClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The document written to the author's repository for a check-in whose location is protected is put together while the author is signed in, so its title
 * is the one a visitor reads: the place is for whoever is signed in, never for another server.
 */
final class ProtectedLocationDocumentTest extends \Tests\IdnoTestCase
{
    /** @var list<string> the bodies of the requests the mock client was sent */
    private array $bodies = [];

    protected function tearDown(): void
    {
        ATProtoClient::useClient(null);
        parent::tearDown();
    }

    public function testTheDocumentOfAProtectedCheckinIsTitledWithoutThePlaceWhileTheAuthorIsSignedIn(): void
    {
        ATProtoClient::useClient(new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->bodies[] = is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse(json_encode(['uri' => 'at://x']), ['http_code' => 200]);
        }));
        $checkin = new class extends Checkin {
            public function getID()
            {
                return 'abc123';
            }

            public function getURL()
            {
                return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'view/abc123';
            }
        };
        $checkin->anonymity = 'Yes';
        $checkin->placename = 'Quagga Hollow';
        $checkin->lat = '51.752345';
        $checkin->long = '-1.257712';
        $checkin->created = time();
        $session = [
            'did' => 'did:plc:alice', 'pds' => 'https://pds.example', 'access_token' => 'ACCESS-TOKEN', 'refresh_token' => 'REFRESH-TOKEN', 'token_type' => 'DPoP',
            'expires_at' => time() + 3600, 'dpop_key' => ATProtoClient::generateDPoPKeyPair(), 'dpop_nonce_auth' => null, 'dpop_nonce_pds' => null,
            'auth_server' => ['authorization_endpoint' => 'https://auth.example/authorize', 'token_endpoint' => 'https://auth.example/token'],
        ];

        $previous = $this->swapUser($this->user());
        try {
            $this->assertStringContainsString('Quagga', $checkin->getTitle(), 'the author is shown the place');
            (new ATProtoClient($session))->putDocument($checkin);
        } finally {
            $this->swapUser($previous ?: null);
        }

        $title = json_decode($this->bodies[0], true)['record']['title'];
        $this->assertStringNotContainsString('Quagga', $title);
        $this->assertStringContainsString('somewhere near', $title);
    }
}
