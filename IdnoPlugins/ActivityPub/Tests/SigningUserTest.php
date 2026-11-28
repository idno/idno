<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\ActivityPub\RemoteActor;

    /**
     * Whose key signs the site's own requests for another server's actor, when nobody is
     * signed in (a delivery to an inbox, the queue). Servers that check the signer (Mastodon's
     * secure mode, GoToSocial) fetch the signer's actor, so it must be an actor this site
     * still serves: one whose address answers 410 gets every such request refused, and then
     * follows and replies are dropped for every member of the site.
     */
    class SigningUserTest extends \Tests\BrowserTestCase
    {

        private static $server;
        private $proxy;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$server = \Tests\LocalHttpServer::start();
        }

        public static function tearDownAfterClass(): void
        {
            static::$server->stop();
            parent::tearDownAfterClass();
        }

        public function setUp(): void
        {
            if (!Idno::site()->plugins()->get('ActivityPub')) {
                $this->markTestSkipped('The ActivityPub plugin is not enabled.');
            }
            static::$server->reset();
            $this->proxy = Idno::site()->config()->proxy_string ?? null;
            Idno::site()->config()->proxy_string = static::$server->proxy();
        }

        public function tearDown(): void
        {
            Idno::site()->config()->proxy_string = $this->proxy;
        }

        /**
         * A member who joined after everybody else, which is who was picked to sign
         */
        private static function newest($prefix, $days = 1)
        {
            $member = static::member($prefix);
            $member->created = time() + $days * 86400;
            $member->save(true);

            return $member;
        }

        /**
         * The account whose key signed the one request the local server got
         */
        private function signer()
        {
            $signers = static::$server->signers();
            $this->assertCount(1, $signers);
            $this->assertNotSame('', $signers[0], 'The request is signed.');
            $prefix = Idno::site()->config()->getDisplayURL() . 'actor/';
            $this->assertStringStartsWith($prefix, $signers[0]);

            return User::getByHandle(substr(strstr($signers[0], '#', true), strlen($prefix)));
        }

        /**
         * While the newest account was being deleted, its key signed the site's requests, and
         * servers that fetch the signer were answered 410.
         */
        public function testAnAccountThatIsBeingDeletedDoesNotSignTheSitesRequests()
        {
            static::member('signopen');
            $closed = static::newest('signclosed');
            $this->swapUser(null);
            $this->assertTrue(static::fresh($closed)->close());

            $this->assertIsArray(RemoteActor::fetch('http://203.0.113.10/actor'));

            $signer = $this->signer();
            $this->assertInstanceOf(User::class, $signer);
            $this->assertNotSame($closed->getUUID(), $signer->getUUID());
            $this->assertFalse($signer->isClosed(), 'The signer is an account whose actor is still served.');
        }

        /**
         * A banned member's actor answers 410 as well, so their key must not sign either.
         */
        public function testABannedMemberDoesNotSignTheSitesRequests()
        {
            static::member('signkept');
            $banned = static::newest('signbanned', 2);
            $banned->banned = time();
            $banned->save(true);
            $this->swapUser(null);

            $this->assertIsArray(RemoteActor::fetch('http://203.0.113.10/actor'));

            $signer = $this->signer();
            $this->assertInstanceOf(User::class, $signer);
            $this->assertNotSame($banned->getUUID(), $signer->getUUID());
            $this->assertFalse($signer->isBanned(), 'The signer is an account whose actor is still served.');
        }

    }

}
