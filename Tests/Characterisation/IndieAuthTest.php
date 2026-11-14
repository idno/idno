<?php

namespace Tests\Characterisation {

    /**
     * The IndieAuth endpoints as an IndieAuth client sees them.
     */
    class IndieAuthTest extends CharacterisationTestCase
    {

        public function testEndpointsAreAdvertised(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('GET', '');

            $this->assertStringContainsString('<link rel="authorization_endpoint" href="' . $base . 'indieauth/auth">', $r->body);
            $this->assertStringContainsString('<link rel="token_endpoint" href="' . $base . 'indieauth/token">', $r->body);

            $link = (string)$r->header('Link');
            $this->assertStringContainsString('<' . $base . 'indieauth/auth>; rel="authorization_endpoint"', $link);
            $this->assertStringContainsString('<' . $base . 'indieauth/token>; rel="token_endpoint"', $link);
        }

        public function testAuthWithoutSessionRedirectsToLogin(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request(
                'GET',
                'indieauth/auth?me=' . $base . '&client_id=https://client.invalid/&redirect_uri=https://client.invalid/cb&state=x&response_type=code'
            );

            $this->assertSame(302, $r->status);
            $this->assertStringStartsWith($base . 'session/login/?fwd=', $r->header('Location'));
        }

        public function testTokenWithInvalidCodeIs400UnrecognizedCode(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('POST', 'indieauth/token', [], [
                'code'         => 'nope',
                'me'           => $base,
                'redirect_uri' => 'https://client.invalid/cb',
                'client_id'    => 'https://client.invalid/',
            ]);

            $this->assertSame(400, $r->status);
            $this->assertSame('unrecognized code.', $r->body);
            // Nothing was issued: the failure is not a token response.
            $this->assertStringNotContainsString('access_token', $r->body);
        }

        public function testTokenVerificationReturnsFormEncodedTokenData(): void
        {
            $base  = $this->site()->baseUrl();
            $token = $this->issueMicropubToken($this->user(), 'create update');
            $r     = $this->site()->request('GET', 'indieauth/token', ['Authorization' => 'Bearer ' . $token]);

            $this->assertSame(200, $r->status);
            parse_str($r->body, $data);
            $this->assertSame($base, $data['me']);
            $this->assertSame('create update', $data['scope']);
            $this->assertSame('https://client.invalid/', $data['client_id']);
        }

        public function testTokenVerificationWithUnknownTokenIs404ClientMismatch(): void
        {
            $r = $this->site()->request('GET', 'indieauth/token', ['Authorization' => 'Bearer nope']);

            $this->assertSame(404, $r->status);
            $this->assertSame('Client mismatch.', $r->body);
        }

        public function testTokenVerificationWithoutTokenIs404ClientMismatch(): void
        {
            $r = $this->site()->request('GET', 'indieauth/token');

            $this->assertSame(404, $r->status);
            $this->assertSame('Client mismatch.', $r->body);
        }
    }

}
