<?php

namespace Tests\Pages\Account {

    use Idno\Core\Idno;
    use Idno\Core\WebSub;
    use Idno\Entities\RemoteUser;
    use Tests\LocalHttpServer;

    /**
     * Following a person stayed when the feed reader was removed: the settings page with its
     * bookmarklet, the page the bookmarklet opens, the follow itself, and the WebSub
     * subscription to the followed person's hub with the callback that confirms it.
     *
     * The person followed is a page of Tests\LocalHttpServer, and so are their feed and their
     * hub: nothing leaves the machine. The requests to the site are made over HTTP, so these
     * tests need the site to be running (see IDNO_DOMAIN / IDNO_PORT), on the machine the
     * tests run on.
     */
    class FollowingPersonTest extends \Tests\BrowserTestCase
    {

        const SETTINGS = '/account/settings/following/';
        const BOOKMARKLET = '/account/settings/following/bookmarklet';

        /// @var LocalHttpServer
        private static $server;

        private static $member;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$server = LocalHttpServer::start();
            static::$member = static::member('follower');
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach (RemoteUser::get(['url' => static::$server->url('/person')]) ?: [] as $person) {
                if ($person instanceof RemoteUser) {
                    $person->delete();
                }
            }
            Idno::site()->db()->setIgnoreAccess(false);
            static::$server->stop();
            parent::tearDownAfterClass();
        }

        public function testSettingsPageOffersTheBookmarklet()
        {
            $page = $this->get(self::SETTINGS, static::$member);

            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('account/settings/following/bookmarklet?u=', $page['body'], 'The settings page should hold the bookmarklet.');
            $this->assertStringNotContainsString('following/confirm', $page['body']);
        }

        public function testSettingsPageIsForMembers()
        {
            $page = $this->get(self::SETTINGS);

            $this->assertNotSame(200, $page['status'], 'A visitor has no following settings.');
            $this->assertStringNotContainsString('bookmarklet?u=', $page['body']);
        }

        public function testBookmarkletPageShowsThePersonFound()
        {
            $page = $this->get(self::BOOKMARKLET . '?u=' . rawurlencode($this->person()), static::$member);

            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('Fixture Person', $page['body']);
            $this->assertStringContainsString('name="uuid"', $page['body'], 'The page should hold the form that follows the person.');
            $this->assertStringContainsString(htmlspecialchars($this->person()), $page['body']);
        }

        public function testFollowingAPersonThroughTheBookmarklet()
        {
            static::$server->reset();

            $result = $this->request(
                'POST',
                self::BOOKMARKLET,
                $this->tokenFields($this->jar(static::$member), self::BOOKMARKLET) + ['uuid' => $this->person(), 'name' => 'Fixture Person', 'nickname' => 'fixtureperson' . substr(static::marker(), -8), 'email' => ''],
                static::$member
            );

            // It used to end here with the error page: the follow was saved, and then the feed
            // reader was asked for the person's feed
            $this->assertLessThan(400, $result['status'], 'Following a person should not end with an error page.');
            $this->assertStringNotContainsString('following/confirm', $result['location'], 'The reader\'s confirmation page is gone; nothing should send the member there.');

            $person = $this->followed();
            $this->assertInstanceOf(RemoteUser::class, $person, 'The person followed should have been stored.');
            $member = static::fresh(static::$member);
            $this->assertTrue($member->isFollowing($person), 'The member should now follow the person.');
            $this->assertArrayHasKey($person->getUUID(), $member->getFollowingUUIDs() ? $member->following : []);

            $next = $this->get(self::SETTINGS, static::$member);
            $this->assertStringContainsString('You are now following Fixture Person', $next['body']);
            $this->assertStringNotContainsString('subscribe to their feed', $next['body'], 'The member is no longer offered a feed subscription.');

            return $person->getUUID();
        }

        /**
         * @depends testFollowingAPersonThroughTheBookmarklet
         */
        public function testFollowingSubscribesAtThePersonsHub($uuid)
        {
            $requests = static::$server->requests();
            $this->assertContains('GET /person/feed', $requests, 'The site should have read the feed the person\'s page names.');
            $this->assertContains('POST /hub', $requests, 'The site should have asked the person\'s hub for a subscription.');

            $person = RemoteUser::getByUUID($uuid, false);
            $this->assertSame(static::$server->url('/hub'), $person->pubsub_hub);
            $this->assertSame(static::$server->url('/person/feed'), $person->pubsub_self);

            $pending = unserialize(static::fresh(static::$member)->pubsub_pending);
            $this->assertContains($uuid, $pending->subscribe, 'The subscription should be waiting for the hub to confirm it.');

            return $uuid;
        }

        /**
         * @depends testFollowingSubscribesAtThePersonsHub
         */
        public function testHubConfirmsTheSubscriptionAtTheCallback($uuid)
        {
            $person = RemoteUser::getByUUID($uuid, false);
            $callback = WebSub::callbackUrl(static::fresh(static::$member), $person);
            $this->assertStringContainsString(WebSub::CALLBACK_PATH, $callback);

            $path = (string) parse_url($callback, PHP_URL_PATH);
            $result = $this->get($path . '?' . http_build_query(['hub.mode' => 'subscribe', 'hub.topic' => static::$server->url('/person/feed'), 'hub.challenge' => 'challenge-' . static::marker()]));

            $this->assertSame(200, $result['status']);
            $this->assertStringStartsWith('challenge-marker', $result['body'], 'The callback should repeat the hub\'s challenge.');

            $pending = unserialize(static::fresh(static::$member)->pubsub_pending);
            $this->assertNotContains($uuid, (array) ($pending->subscribe ?? []), 'A confirmed subscription is no longer pending.');
        }

        /**
         * @depends testFollowingAPersonThroughTheBookmarklet
         */
        public function testFollowingTheSamePersonAgainChangesNothing($uuid)
        {
            $result = $this->request(
                'POST',
                self::BOOKMARKLET,
                $this->tokenFields($this->jar(static::$member), self::BOOKMARKLET) + ['uuid' => $this->person(), 'name' => 'Fixture Person', 'nickname' => 'fixtureperson', 'email' => ''],
                static::$member
            );

            $this->assertLessThan(400, $result['status']);
            $this->assertCount(1, RemoteUser::get(['url' => $this->person()]), 'The person should be stored once.');
            $this->assertTrue(static::fresh(static::$member)->isFollowing(RemoteUser::getByUUID($uuid, false)));
        }

        public function testFollowAndUnfollowEventsAreTriggered()
        {
            $follower = static::member('eventfollower');
            $followed = static::member('eventfollowed');
            $heard = [];
            $listener = function (\Idno\Core\Event $event) use (&$heard, $followed) {
                $data = $event->data();
                if ($data['following']->getUUID() === $followed->getUUID()) {
                    $heard[] = $data['user']->getUUID();
                    // Nothing more: the site's own listener would fetch the profile. The
                    // listener stays for the rest of the process and hears only this member
                    $event->stopPropagation();
                }
            };
            Idno::site()->events()->addListener('follow', $listener, 100);
            Idno::site()->events()->addListener('unfollow', $listener, 100);

            $this->assertTrue($follower->addFollowing($followed));
            $this->assertTrue($follower->isFollowing($followed));
            $this->assertFalse($follower->addFollowing($followed), 'A member follows a person once.');
            $this->assertTrue($follower->removeFollowing($followed));
            $this->assertFalse($follower->isFollowing($followed));

            $this->assertSame([$follower->getUUID(), $follower->getUUID()], $heard, 'Following and unfollowing should each trigger their event once.');
        }

        private function person()
        {
            return static::$server->url('/person');
        }

        /**
         * The stored record of the fixture's person, if there is one
         */
        private function followed()
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $found = RemoteUser::get(['url' => $this->person()]) ?: [];
            Idno::site()->db()->setIgnoreAccess(false);

            return $found ? $found[0] : null;
        }
    }

}
