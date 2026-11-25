<?php

namespace Tests\API {

    use Idno\Core\Idno;

    /**
     * A ban ends everything a member can act with at once: the browser session, the API key
     * and IndieAuth access tokens. Lifting the ban makes them work again.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class BannedCredentialsTest extends \Tests\BrowserTestCase
    {

        private static $admin;

        public function setUp(): void
        {
            if (!static::$admin) {
                static::$admin = static::member('credadmin', true);
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$admin = null;
        }

        public function testBrowserSessionEndsAtOnce()
        {
            $member = static::member('credmember');
            $this->assertEquals(200, $this->get('/account/settings/', $member)['status'], 'Control: the member is logged in.');

            $this->ban($member);
            $this->assertNotEquals(200, $this->get('/account/settings/', $member)['status'], 'The session of a banned member must end on the next request.');
            $this->logIn($member);
            $this->assertNotEquals(200, $this->get('/account/settings/', $member)['status'], 'A banned member must not be able to sign in.');

            $this->unban($member);
            $this->logIn($member);
            $this->assertEquals(200, $this->get('/account/settings/', $member)['status'], 'Once the ban is lifted the member can sign in again.');
        }

        public function testApiKeyStopsWorking()
        {
            $member = static::member('credmember');

            $this->request('POST', '/status/edit', ['body' => 'Signed ' . static::marker()], null, $this->signature($member, '/status/edit'));
            $this->assertEquals(1, $this->countPosts($member), 'Control: a signed request posts.');

            $this->ban($member);
            $this->request('POST', '/status/edit', ['body' => 'Signed ' . static::marker()], null, $this->signature($member, '/status/edit'));
            $this->assertEquals(1, $this->countPosts($member), 'A signed request from a banned member must not post.');

            $this->unban($member);
            $this->request('POST', '/status/edit', ['body' => 'Signed ' . static::marker()], null, $this->signature($member, '/status/edit'));
            $this->assertEquals(2, $this->countPosts($member));
        }

        public function testAccessTokenStopsWorking()
        {
            if (!Idno::site()->plugins()->get('IndiePub')) {
                $this->markTestSkipped('The IndiePub plugin is not enabled.');
            }
            $member = static::member('credmember');
            $token = \IdnoPlugins\IndiePub\Pages\IndieAuth\Token::issueToken(static::fresh($member), ['me' => $member->getURL(), 'client_id' => 'https://client.example/', 'scope' => 'create']);
            $bearer = ['Authorization: Bearer ' . $token];

            $this->request('POST', '/micropub/endpoint', ['h' => 'entry', 'content' => 'Token ' . static::marker()], null, $bearer);
            $this->assertEquals(1, $this->countPosts($member), 'Control: a request with an access token posts.');

            $this->ban($member);
            $result = $this->request('POST', '/micropub/endpoint', ['h' => 'entry', 'content' => 'Token ' . static::marker()], null, $bearer);
            $this->assertEquals(1, $this->countPosts($member), 'A request with the access token of a banned member must not post.');
            $this->assertContains($result['status'], [401, 403]);

            $this->unban($member);
            $this->request('POST', '/micropub/endpoint', ['h' => 'entry', 'content' => 'Token ' . static::marker()], null, $bearer);
            $this->assertEquals(2, $this->countPosts($member));
        }

        private function ban($member)
        {
            $stored = static::fresh($member);
            $this->assertTrue($stored->ban('test', static::$admin->getUUID()));
            $stored->save();
        }

        private function unban($member)
        {
            $stored = static::fresh($member);
            $stored->unban();
            $stored->save();
        }

        private function countPosts($member)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $count = 0;
            foreach (\Idno\Common\Entity::getFromAll(['owner' => $member->getUUID()], [], 100) ?: [] as $entity) {
                if ($entity instanceof \IdnoPlugins\Status\Status) {
                    $count++;
                }
            }
            Idno::site()->db()->setIgnoreAccess(false);

            return $count;
        }
    }

}
