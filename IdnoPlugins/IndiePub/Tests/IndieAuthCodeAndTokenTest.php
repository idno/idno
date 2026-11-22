<?php

namespace IdnoPlugins\IndiePub\Tests {

    use IdnoPlugins\IndiePub\Pages\IndieAuth\Auth;
    use IdnoPlugins\IndiePub\Pages\IndieAuth\Token;

    /**
     * IndieAuth authorization codes and access tokens are bearer secrets: whoever holds one
     * can act as the user. They must be unguessable, a code must work once and only for ten
     * minutes, and tokens issued before these rules existed must go on working.
     */
    class IndieAuthCodeAndTokenTest extends \Tests\IdnoTestCase
    {

        const CLIENT = 'https://client.example/';
        const REDIRECT = 'https://client.example/callback';

        protected function setUp(): void
        {
            parent::setUp();
            $user = $this->user();
            $user->indieauth_codes = [];
            $user->indieauth_tokens = [];
            $user->save(true);
        }

        private function issueCode(array $changes = [])
        {
            return Auth::issueCode($this->user(), array_merge([
                'me'           => 'https://user.example/',
                'redirect_uri' => self::REDIRECT,
                'scope'        => 'create',
                'state'        => 'abc',
                'client_id'    => self::CLIENT,
            ], $changes));
        }

        /**
         * Store a code as if it had been issued some seconds ago.
         */
        private function storeCode($code, $age)
        {
            $user = $this->user();
            $codes = (array) $user->indieauth_codes;
            $codes[$code] = [
                'me'           => 'https://user.example/',
                'redirect_uri' => self::REDIRECT,
                'scope'        => 'create',
                'state'        => 'abc',
                'client_id'    => self::CLIENT,
                'issued_at'    => time() - $age,
            ];
            $user->indieauth_codes = $codes;
            $user->save(true);
        }

        private function storedCodes()
        {
            $user = \Idno\Entities\User::getByUUID($this->user()->getUUID());

            return (array) $user->indieauth_codes;
        }

        public function testSecretsAreLongRandomHex()
        {
            $first = Auth::generateSecret();
            $second = Auth::generateSecret();

            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
            $this->assertNotEquals($first, $second);
        }

        public function testIssuedCodeIsRandomAndCanBeRedeemed()
        {
            $code = $this->issueCode();

            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $code);

            $verified = Auth::verifyCode($code, self::CLIENT, self::REDIRECT);

            $this->assertTrue($verified['valid']);
            $this->assertEquals($this->user()->getUUID(), $verified['user']->getUUID());
            $this->assertEquals('https://user.example/', $verified['me']);
            $this->assertEquals('create', $verified['scope']);
        }

        public function testCodeCanBeRedeemedOnlyOnce()
        {
            $code = $this->issueCode();

            $this->assertTrue(Auth::verifyCode($code, self::CLIENT, self::REDIRECT)['valid']);

            $again = Auth::verifyCode($code, self::CLIENT, self::REDIRECT);

            $this->assertFalse($again['valid']);
            $this->assertArrayNotHasKey($code, $this->storedCodes());
        }

        public function testCodeOlderThanTenMinutesIsRefused()
        {
            $this->storeCode('oldcode', 10 * 60 + 5);

            $verified = Auth::verifyCode('oldcode', self::CLIENT, self::REDIRECT);

            $this->assertFalse($verified['valid']);
            $this->assertArrayNotHasKey('oldcode', $this->storedCodes());
        }

        public function testCodeJustUnderTenMinutesOldIsAccepted()
        {
            $this->storeCode('recentcode', 10 * 60 - 30);

            $this->assertTrue(Auth::verifyCode('recentcode', self::CLIENT, self::REDIRECT)['valid']);
        }

        public function testCodeWithoutAnIssueTimeIsRefused()
        {
            $user = $this->user();
            $user->indieauth_codes = ['timeless' => ['redirect_uri' => self::REDIRECT, 'client_id' => self::CLIENT, 'me' => '', 'scope' => '']];
            $user->save(true);

            $this->assertFalse(Auth::verifyCode('timeless', self::CLIENT, self::REDIRECT)['valid']);
        }

        public function testCodeIsRefusedForAnotherClientAndCannotBeTriedAgain()
        {
            $code = $this->issueCode();

            $this->assertFalse(Auth::verifyCode($code, 'https://other.example/', self::REDIRECT)['valid']);
            $this->assertFalse(Auth::verifyCode($code, self::CLIENT, self::REDIRECT)['valid']);
        }

        public function testCodeIsRefusedForAnotherRedirectUri()
        {
            $code = $this->issueCode();

            $this->assertFalse(Auth::verifyCode($code, self::CLIENT, 'https://client.example/other')['valid']);
        }

        public function testUnknownEmptyAndMalformedCodesAreRefused()
        {
            $this->issueCode();

            $this->assertFalse(Auth::verifyCode('nosuchcode', self::CLIENT, self::REDIRECT)['valid']);
            $this->assertFalse(Auth::verifyCode('', self::CLIENT, self::REDIRECT)['valid']);
            $this->assertFalse(Auth::verifyCode(null, self::CLIENT, self::REDIRECT)['valid']);
            $this->assertFalse(Auth::verifyCode(['x'], self::CLIENT, self::REDIRECT)['valid']);
        }

        public function testIssuingACodeDropsExpiredOnes()
        {
            $this->storeCode('stale', 11 * 60);
            $this->storeCode('fresh', 60);

            $code = $this->issueCode();
            $stored = $this->storedCodes();

            $this->assertArrayNotHasKey('stale', $stored);
            $this->assertArrayHasKey('fresh', $stored);
            $this->assertArrayHasKey($code, $stored);
        }

        public function testIssuedTokenIsRandomAndIdentifiesItsUser()
        {
            $token = Token::issueToken($this->user(), [
                'me'           => 'https://user.example/',
                'redirect_uri' => self::REDIRECT,
                'scope'        => 'create',
                'client_id'    => self::CLIENT,
            ]);

            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

            $found = Token::findUserForToken($token);

            $this->assertEquals($this->user()->getUUID(), $found['user']->getUUID());
            $this->assertEquals('create', $found['data']['scope']);
            $this->assertEquals(self::CLIENT, $found['data']['client_id']);
            $this->assertEqualsWithDelta(time(), $found['data']['issued_at'], 5);
        }

        /**
         * Tokens issued by earlier versions are 32 hex characters, and micropub clients still hold them.
         */
        public function testTokenIssuedByAnEarlierVersionStillWorks()
        {
            $legacy = md5('a token issued in 2019');
            $user = $this->user();
            $user->indieauth_tokens = [$legacy => [
                'me'           => 'https://user.example/',
                'redirect_uri' => self::REDIRECT,
                'scope'        => 'post',
                'client_id'    => self::CLIENT,
                'issued_at'    => 1560000000,
                'nonce'        => 1234567,
            ]];
            $user->save(true);

            $found = Token::findUserForToken($legacy);

            $this->assertEquals($user->getUUID(), $found['user']->getUUID());
            $this->assertEquals('post', $found['data']['scope']);
        }

        public function testUnknownEmptyAndMalformedTokensFindNobody()
        {
            $token = Token::issueToken($this->user(), ['me' => '', 'redirect_uri' => '', 'scope' => 'create', 'client_id' => self::CLIENT]);

            $this->assertEmpty(Token::findUserForToken('nosuchtoken'));
            $this->assertEmpty(Token::findUserForToken(substr($token, 0, 32)));
            $this->assertEmpty(Token::findUserForToken(''));
            $this->assertEmpty(Token::findUserForToken(null));
            $this->assertEmpty(Token::findUserForToken([$token]));
        }

        /**
         * The whole exchange over HTTP: a code buys one token, and the token is accepted afterwards.
         */
        public function testTokenEndpointExchangesACodeOnce()
        {
            $code = $this->issueCode();
            $endpoint = \Idno\Core\Idno::site()->config()->url . 'indieauth/token';
            $params = ['code' => $code, 'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'state' => 'abc'];

            $first = \Idno\Core\Webservice::post($endpoint, $params);
            $body = json_decode($first['content'], true);

            $this->assertEquals(200, $first['response']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['access_token']);
            $this->assertEquals('create', $body['scope']);

            $second = \Idno\Core\Webservice::post($endpoint, $params);

            $this->assertEquals(400, $second['response']);

            $check = \Idno\Core\Webservice::get($endpoint, [], ['Authorization: Bearer ' . $body['access_token']]);

            $this->assertEquals(200, $check['response']);
            $this->assertStringContainsString('scope=create', $check['content']);

            $wrong = \Idno\Core\Webservice::get($endpoint, [], ['Authorization: Bearer ' . str_repeat('0', 64)]);

            $this->assertEquals(404, $wrong['response']);
        }
    }
}
