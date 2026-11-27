<?php

namespace Tests\Core {

    /**
     * Test gatekeepers
     */
    class GatekeeperTest extends \Tests\IdnoTestCase
    {

        // A page for members answers 403 to a visitor and 200 to a request signed with a member's API key.
        public function testGatekeeper()
        {
            $result = \Idno\Core\Webservice::get(\Idno\Core\Idno::site()->config()->url . 'begin/profile/', [], []);

            $response = $result['response'];
            $this->assertEmpty($result['error'], 'The result\'s error property should be empty.');
            $this->assertEquals($response, 403, 'The response should have returned a 403 HTTP response.');

            $user = \Tests\IdnoTestCase::user();
            $this->assertIsObject(\Idno\Core\Idno::site()->session()->logUserOn($user));

            // Not an account page: those are closed to API requests, see Tests\API\AccountPagesTest
            $result = \Idno\Core\Webservice::get(
                \Idno\Core\Idno::site()->config()->url . 'begin/profile/', [], [
                'X-IDNO-USERNAME: ' . $user->handle,
                'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', '/begin/profile/', $user->getAPIkey(), true)),

                ]
            );

            $response = $result['response'];
            $this->assertEmpty($result['error'], 'The result\'s error property should be empty.');
            $this->assertEquals($response, 200, 'The response should have returned a 200 HTTP response.');

            \Idno\Core\Idno::site()->session()->logUserOff();
        }

        // An admin page answers 403 to a visitor, to a signed member, and to a signed administrator: an API request is never an administrator.
        public function testAdminGatekeeper()
        {
            $result = \Idno\Core\Webservice::get(\Idno\Core\Idno::site()->config()->url . 'admin/', [], []);

            $response = $result['response'];
            $this->assertEmpty($result['error'], 'The result\'s error property should be empty.');
            $this->assertEquals($response, 403, 'The response should have returned a 403 HTTP response.');

            $user = \Tests\IdnoTestCase::user();
            $this->assertIsObject(\Idno\Core\Idno::site()->session()->logUserOn($user));

            // Try normal user
            \Idno\Core\Idno::site()->session()->logUserOff();
            $result = \Idno\Core\Webservice::get(
                \Idno\Core\Idno::site()->config()->url . 'admin/', [], [
                'X-IDNO-USERNAME: ' . $user->handle,
                'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', '/admin/', $user->getAPIkey(), true)),

                ]
            );

            $response = $result['response'];
            $this->assertEmpty($result['error'], 'The result\'s error property should be empty.');
            $this->assertEquals($response, 403, 'The response should have returned a 403 HTTP response.');

            // Try admin
            $user = \Tests\IdnoTestCase::admin();
            $this->assertIsObject(\Idno\Core\Idno::site()->session()->logUserOn($user));

            $result = \Idno\Core\Webservice::get(
                \Idno\Core\Idno::site()->config()->url . 'admin/', [], [
                'X-IDNO-USERNAME: ' . $user->handle,
                'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', '/admin/', $user->getAPIkey(), true)),

                ]
            );

            $response = $result['response'];
            $this->assertEmpty($result['error'], 'The result\'s error property should be empty.');
            $this->assertEquals($response, 403, 'The response should have returned a 403 HTTP response.');

            \Idno\Core\Idno::site()->session()->logUserOff();
        }


    }

}
