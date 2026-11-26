<?php

namespace IdnoPlugins\Bridgy\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Bridgy\Main;

    /**
     * Bridgy sends the member's browser back to the site with what it decided in the address.
     * Those pages are plain GETs, so they act only on an address that carries the state the
     * site gave this member when they set out, and keep only values of the kind Bridgy sends.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT), with the Bridgy plugin on.
     */
    class CallbackStateTest extends \Tests\BrowserTestCase
    {

        private static $member;
        private static $other;
        private static $server;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$member = static::member('bridgyone');
            static::$other = static::member('bridgytwo');
            static::$server = \Tests\LocalHttpServer::start();
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$server) {
                static::$server->stop();
                static::$server = null;
            }
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            if (!Idno::site()->plugins()->get('Bridgy')) {
                $this->markTestSkipped('The Bridgy plugin is not enabled.');
            }
            $member = static::fresh(static::$member);
            $member->bridgy = [];
            $member->save(true);
        }

        private function settings()
        {
            return (array) (static::fresh(static::$member)->bridgy ?? []);
        }

        private function comeBack($page, array $query, ?User $as = null)
        {
            return $this->get("/account/bridgy/$page/?" . http_build_query($query), $as ?: static::$member);
        }

        /// What Bridgy adds to the address when it sends the browser back
        private function fromBridgy(array $more = [])
        {
            return $more + ['result' => 'success', 'user' => 'https://brid.gy/twitter/someone', 'key' => 'aKey123'];
        }

        public function testAnAddressWithoutTheStateChangesNothing()
        {
            foreach (['enabled', 'disabled'] as $page) {
                $answer = $this->comeBack($page, $this->fromBridgy(['service' => 'twitter']));

                $this->assertLessThan(500, $answer['status']);
                $this->assertSame([], $this->settings(), "A request to the $page page that does not carry the member's state must change nothing.");
            }
        }

        public function testAStateOfAnotherMemberOrAnotherPageChangesNothing()
        {
            $states = [
                'of another member' => Main::callbackState(static::fresh(static::$other), 'enabled', 'twitter'),
                'for the other page' => Main::callbackState(static::fresh(static::$member), 'disabled', 'twitter'),
                'for another service' => Main::callbackState(static::fresh(static::$member), 'enabled', 'facebook'),
                'that is too old' => Main::callbackState(static::fresh(static::$member), 'enabled', 'twitter', time() - Main::STATE_LIFETIME - 60),
                'that is made up' => time() . '.' . str_repeat('0', 64),
            ];
            foreach ($states as $what => $state) {
                $this->comeBack('enabled', $this->fromBridgy(['service' => 'twitter', 'state' => $state]));

                $this->assertSame([], $this->settings(), "A state $what must not be accepted.");
            }
        }

        public function testTheAddressTheAccountPageGivesBridgyIsAccepted()
        {
            $page = $this->get('/account/bridgy/', static::$member);
            $this->assertSame(200, $page['status']);
            $this->assertSame(1, preg_match('#name="callback" value="([^"]*account/bridgy/enabled/[^"]*)"#', $page['body'], $found), 'The account page should give Bridgy an address to come back to.');
            $callback = html_entity_decode($found[1], ENT_QUOTES);
            parse_str((string) parse_url($callback, PHP_URL_QUERY), $query);
            $this->assertNotEmpty($query['state'] ?? null, 'That address should carry a state.');

            $this->comeBack('enabled', $this->fromBridgy($query));

            $this->assertSame(['status' => 'enabled', 'user' => 'https://brid.gy/twitter/someone', 'key' => 'aKey123'], $this->settings()['twitter'] ?? null);

            // ... and the same for turning it off again
            $state = Main::callbackState(static::fresh(static::$member), 'disabled', 'twitter');
            $this->comeBack('disabled', $this->fromBridgy(['service' => 'twitter', 'state' => $state]));
            $this->assertSame('disabled', $this->settings()['twitter']['status'] ?? null);
        }

        public function testOnlyAServiceThePluginKnowsIsKept()
        {
            $state = Main::callbackState(static::fresh(static::$member), 'enabled', 'somethingelse');
            $this->comeBack('enabled', $this->fromBridgy(['service' => 'somethingelse', 'state' => $state]));

            $this->assertSame([], $this->settings());
        }

        public function testWhatIsKeptIsAWebAddressAndAKeyAndIsPrintedAsText()
        {
            $state = Main::callbackState(static::fresh(static::$member), 'enabled', 'twitter');
            $this->comeBack('enabled', ['service' => 'twitter', 'state' => $state, 'result' => 'success', 'user' => 'javascript:alert(1)', 'key' => '"><script>alert(1)</script>']);

            $kept = $this->settings()['twitter'] ?? [];
            $this->assertSame('', $kept['user'] ?? '', 'An address that is not a web address is not kept.');

            foreach (['default', 'modern'] as $look) {
                $page = $this->get('/account/bridgy/?_t=' . $look, static::$member);
                $this->assertStringNotContainsString('<script>alert(1)</script>', $page['body'], "The key is printed as text ($look look).");
            }
        }

        /**
         * The status check asks the address Bridgy gave for the member. It is fetched as an
         * address from another party is: never one on the server's own network.
         */
        public function testTheStatusCheckDoesNotFetchAnAddressOnTheServersOwnNetwork()
        {
            $member = static::fresh(static::$member);
            $member->bridgy = ['twitter' => ['status' => 'enabled', 'user' => static::$server->url('/bridgy-user'), 'key' => 'k']];
            $member->save(true);
            static::$server->reset();

            $answer = $this->get('/account/bridgy/check/', static::$member);

            $this->assertLessThan(500, $answer['status']);
            $this->assertSame([], static::$server->requests(), 'The site must not request an address on its own network because a member\'s Bridgy settings name it.');
        }
    }

}
