<?php

namespace IdnoPlugins\Checkin\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Checkin\Checkin;

    /**
     * A member can protect the location of a check-in for 24 hours: until then, visitors who
     * are not signed in are told "somewhere nearby" and shown an inexact position. The place
     * must then not reach them by any other way either: not in the post's address, not in any
     * form of the page (HTML, RSS, JSON Feed, JSON, KML, ActivityPub), not in search, and not
     * in what is sent to other servers. Members who are signed in, and the owner, see the place.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class ProtectedLocationTest extends \Tests\BrowserTestCase
    {

        const LATITUDE = '51.752345';
        const LONGITUDE = '-1.257712';

        /// Where the check-ins are that anybody may be told about
        const OPEN_LATITUDE = '48.858371';
        const OPEN_LONGITUDE = '2.294482';

        protected static $owner;
        protected static $other;

        /// @var Checkin The check-in whose location is protected, made over HTTP by its owner
        protected static $checkin;
        protected static $place;
        protected static $street;

        /// The name of the places of the check-ins that anybody may be told about
        protected static $openPlace;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$owner = static::member('placeown');
            static::$other = static::member('placeother');
            static::$checkin = null;
            static::$place = 'Quagga' . substr(md5(rand() . microtime(true)), 0, 10);
            static::$street = 'Okapirow' . substr(md5(rand() . microtime(true)), 0, 10);
            static::$openPlace = 'Zebra' . substr(md5(rand() . microtime(true)), 0, 10);
        }

        // -----------------------------------------------------------------
        // The address
        // -----------------------------------------------------------------

        public function testTheAddressOfAProtectedCheckinDoesNotNameThePlace()
        {
            $checkin = $this->protectedCheckin();

            $this->assertStringNotContainsStringIgnoringCase(static::$place, $checkin->getURL());
            $this->assertStringNotContainsStringIgnoringCase(static::$place, (string) $checkin->getSlug());
            $this->assertStringNotContainsStringIgnoringCase(static::$place, $checkin->getUUID());
            $this->assertNotEmpty($checkin->getSlug());
        }

        public function testTheAddressOfAnOpenCheckinStillNamesThePlace()
        {
            $checkin = $this->checkIn(['placename' => static::$openPlace, 'anonymity' => ''] + static::openPlace());

            $this->assertStringContainsStringIgnoringCase(static::$openPlace, $checkin->getURL());
        }

        public function testASecondProtectedCheckinGetsAnAddressOfItsOwn()
        {
            $first = $this->protectedCheckin();
            $second = $this->checkIn(['placename' => 'Another ' . static::$place]);

            $this->assertNotSame($first->getURL(), $second->getURL());
            $this->assertStringNotContainsStringIgnoringCase(static::$place, $second->getURL());
        }

        // -----------------------------------------------------------------
        // Every form of the page
        // -----------------------------------------------------------------

        /**
         * The forms a check-in is given out in, as functions of the check-in that return the
         * path to ask for and the headers to ask with
         */
        public static function forms(): array
        {
            $permalink = function ($type) {
                return function (Checkin $checkin) use ($type) {
                    return [parse_url($checkin->getURL(), PHP_URL_PATH) . ($type ? '?_t=' . $type : ''), []];
                };
            };
            $profile = function ($type) {
                return function (Checkin $checkin) use ($type) {
                    return ['/profile/' . $checkin->getOwner()->getHandle() . ($type ? '?_t=' . $type : ''), []];
                };
            };

            return [
                'the page'                     => [$permalink('')],
                'the page at /view/'           => [function (Checkin $checkin) {
                    return ['/view/' . $checkin->getID(), []];
                }],
                'the page as RSS'              => [$permalink('rss')],
                'the page as JSON Feed'        => [$permalink('jsonfeed')],
                'the page as JSON'             => [$permalink('json')],
                'the page as JSONP'            => [$permalink('jsonp')],
                'the page as KML'              => [$permalink('kml')],
                'the page as ActivityPub'      => [function (Checkin $checkin) {
                    return [parse_url($checkin->getURL(), PHP_URL_PATH), ['Accept: application/activity+json']];
                }],
                'the profile'                  => [$profile('')],
                'the profile as RSS'           => [$profile('rss')],
                'the profile as JSON Feed'     => [$profile('jsonfeed')],
                'the profile as JSON'          => [$profile('json')],
                'the profile as KML'           => [$profile('kml')],
                'the list of check-ins'        => [function (Checkin $checkin) {
                    return ['/profile/' . $checkin->getOwner()->getHandle() . '/locations', []];
                }],
            ];
        }

        /**
         * @dataProvider forms
         */
        public function testAVisitorIsNotToldThePlaceInAnyForm($form)
        {
            $checkin = $this->protectedCheckin();
            [$path, $headers] = $form($checkin);
            $answer = $this->get($path, null, $headers);

            $this->assertLessThan(500, $answer['status'], $path);
            $this->assertHidesThePlace($answer['headers'] . $answer['body'], $path . ' as a visitor');
        }

        /**
         * Search looks in the stored title of a post, which named the place.
         */
        public function testAVisitorDoesNotFindTheCheckinByItsPlace()
        {
            $checkin = $this->protectedCheckin();
            $text = trim(strip_tags((string) $checkin->body));

            // The search does find it by what its author wrote about it
            $this->assertStringContainsString($text, $this->get('/?q=' . urlencode($text))['body']);

            // By the place alone: a full-text search for "Checked into <place>" finds every
            // check-in by the words they all have
            foreach ([static::$place, static::$street] as $query) {
                foreach (['', '_t=json&', '_t=rss&'] as $type) {
                    $answer = $this->get('/?' . $type . 'q=' . urlencode($query));
                    $this->assertLessThan(500, $answer['status']);
                    $this->assertStringNotContainsString($text, $answer['body'], 'A search for "' . $query . '" should not find the check-in');
                    $this->assertStringNotContainsString($checkin->getID(), $answer['body']);
                }
            }
        }

        /**
         * So that the test above is not passing on empty pages
         */
        public function testAVisitorIsStillShownTheCheckin()
        {
            $checkin = $this->protectedCheckin();
            $path = parse_url($checkin->getURL(), PHP_URL_PATH);

            $page = $this->get($path);
            $this->assertEquals(200, $page['status']);
            $this->assertStringContainsString('somewhere nearby', $page['body']);
            $this->assertStringContainsString('What happened there', $page['body']);

            foreach (['rss', 'jsonfeed', 'json', 'kml'] as $type) {
                $answer = $this->get($path . '?_t=' . $type);
                $this->assertEquals(200, $answer['status'], $type);
                $this->assertStringContainsString('somewhere nearby', $answer['body'], $type);
            }

            $json = $this->jsonOf($path);
            $this->assertIsArray($json);
            $this->assertNotEmpty($json['latitude']);
            $this->assertEqualsWithDelta((float) self::LATITUDE, (float) $json['latitude'], 0.011, 'A visitor is shown a position nearby');
            $this->assertEqualsWithDelta((float) self::LONGITUDE, (float) $json['longitude'], 0.011);
        }

        /**
         * The inexact position was a random one for every request: the average of enough
         * requests was the exact one.
         */
        public function testTheInexactPositionIsTheSameEveryTime()
        {
            $path = parse_url($this->protectedCheckin()->getURL(), PHP_URL_PATH);

            $positions = [];
            for ($i = 0; $i < 4; $i++) {
                $json = $this->jsonOf($path);
                $positions[$json['latitude'] . ',' . $json['longitude']] = true;
            }

            $this->assertCount(1, $positions, 'A visitor who asks again must get the same position: ' . implode(' ', array_keys($positions)));
        }

        public static function membersWhoSeeThePlace(): array
        {
            return ['another member' => ['other'], 'the owner' => ['owner']];
        }

        /**
         * @dataProvider membersWhoSeeThePlace
         */
        public function testAMemberWhoIsSignedInIsShownThePlace($who)
        {
            $member = $who === 'owner' ? static::$owner : static::$other;
            $checkin = $this->protectedCheckin();
            $path = parse_url($checkin->getURL(), PHP_URL_PATH);

            $page = $this->get($path, $member);
            $this->assertEquals(200, $page['status']);
            $this->assertStringContainsString('Checked into ' . static::$place, $page['body']);
            $this->assertStringContainsString(self::LATITUDE, $page['body']);
            $this->assertStringContainsString(self::LONGITUDE, $page['body']);

            $json = $this->jsonOf($path, $member);
            $this->assertSame(static::$place, $json['placeName']);
            $this->assertSame('17 ' . static::$street, $json['address']);
            $this->assertSame(self::LATITUDE, $json['latitude']);
            $this->assertSame(self::LONGITUDE, $json['longitude']);

            $kml = $this->get($path . '?_t=kml', $member)['body'];
            $this->assertStringContainsString(self::LONGITUDE . ',' . self::LATITUDE, $kml);
            $this->assertStringContainsString(static::$place, $kml);

            $feed = json_decode($this->get($path . '?_t=jsonfeed', $member)['body'], true);
            $this->assertSame(static::$place, $feed['items'][0]['_meta']['placename']);
        }

        /**
         * The protection is for 24 hours.
         */
        public function testAfterADayAVisitorIsShownThePlace()
        {
            $checkin = $this->checkIn(['placename' => 'Older ' . static::$openPlace] + static::openPlace());
            $checkin->created = time() - 86400 - 60;
            $this->assertNotEmpty($this->saveAs(static::$owner, $checkin));
            $path = parse_url($checkin->getURL(), PHP_URL_PATH);

            $page = $this->get($path);
            $this->assertStringContainsString('Checked into Older ' . static::$openPlace, $page['body']);
            $this->assertStringContainsString(self::OPEN_LATITUDE, $page['body']);

            $json = $this->jsonOf($path);
            $this->assertSame('Older ' . static::$openPlace, $json['placeName']);
            $this->assertSame('9 Open Street', $json['address']);
            $this->assertSame(self::OPEN_LATITUDE, $json['latitude']);
            $this->assertStringContainsString(self::OPEN_LONGITUDE . ',' . self::OPEN_LATITUDE, $this->get($path . '?_t=kml')['body']);
        }

        public function testACheckinThatIsNotProtectedIsShownToAVisitor()
        {
            $checkin = $this->checkIn(['placename' => 'Public ' . static::$openPlace, 'anonymity' => ''] + static::openPlace());
            $path = parse_url($checkin->getURL(), PHP_URL_PATH);

            $this->assertStringContainsString('Checked into Public ' . static::$openPlace, $this->get($path)['body']);
            $json = $this->jsonOf($path);
            $this->assertSame('Public ' . static::$openPlace, $json['placeName']);
            $this->assertSame('9 Open Street', $json['address']);
            $this->assertSame(self::OPEN_LATITUDE, $json['latitude']);
        }

        // -----------------------------------------------------------------
        // The rule, and what is sent to other servers while the owner is signed in
        // -----------------------------------------------------------------

        public function testTheLocationAViewerMaySee()
        {
            $checkin = Checkin::getByUUID($this->protectedCheckin()->getUUID());

            $this->assertTrue($checkin->isLocationProtected());

            $public = $checkin->locationFor(null);
            $this->assertFalse($public['precise']);
            $this->assertSame('', $public['placename']);
            $this->assertSame('', $public['address']);
            $this->assertNotEquals((float) self::LATITUDE, $public['latitude']);
            $this->assertNotEquals((float) self::LONGITUDE, $public['longitude']);
            $this->assertEqualsWithDelta((float) self::LATITUDE, $public['latitude'], 0.011);
            $this->assertEqualsWithDelta((float) self::LONGITUDE, $public['longitude'], 0.011);
            $this->assertSame($public, $checkin->publicLocation());

            foreach ([static::$other, static::$owner] as $member) {
                $seen = $checkin->locationFor($member);
                $this->assertTrue($seen['precise']);
                $this->assertSame(static::$place, $seen['placename']);
                $this->assertSame('17 ' . static::$street, $seen['address']);
                $this->assertSame((float) self::LATITUDE, $seen['latitude']);
                $this->assertSame((float) self::LONGITUDE, $seen['longitude']);
            }
        }

        /**
         * Whoever is signed in, what goes to other servers is what a visitor sees: a post is
         * delivered while its owner is signed in.
         */
        public function testWhatIsSentElsewhereDoesNotNameThePlaceWhileTheOwnerIsSignedIn()
        {
            $old = $this->swapUser(static::$owner);
            try {
                $checkin = Checkin::getByUUID($this->protectedCheckin()->getUUID());

                $this->assertStringContainsString(static::$place, $checkin->getTitle(), 'The owner reads the place');
                $this->assertHidesThePlace($checkin->getPublicTitle(), 'The title for other servers');
                $this->assertStringContainsString('somewhere nearby', $checkin->getPublicTitle());

                if (class_exists('\IdnoPlugins\ActivityPub\ActivityBuilder')) {
                    $object = \IdnoPlugins\ActivityPub\ActivityBuilder::buildObject($checkin);
                    $this->assertHidesThePlace(json_encode($object), 'The ActivityPub object');
                    $this->assertArrayNotHasKey('location', $object);
                }
            } finally {
                $this->swapUser($old);
            }
        }

        public function testEveryEntityHasAPublicTitle()
        {
            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'A status update';

            $this->assertSame($status->getTitle(), $status->getPublicTitle());
        }

        // -----------------------------------------------------------------

        /**
         * No form of the check-in's place: its name, its address, its exact position
         */
        protected function assertHidesThePlace($text, $what)
        {
            $this->assertStringNotContainsStringIgnoringCase(static::$place, $text, $what . ' names the place');
            $this->assertStringNotContainsStringIgnoringCase(static::$street, $text, $what . ' gives the address');
            $this->assertStringNotContainsString(self::LATITUDE, $text, $what . ' gives the exact latitude');
            $this->assertStringNotContainsString(ltrim(self::LONGITUDE, '-'), $text, $what . ' gives the exact longitude');
        }

        /**
         * The form fields of a place that is not the protected one
         */
        protected static function openPlace(): array
        {
            return ['user_address' => '9 Open Street', 'lat' => self::OPEN_LATITUDE, 'long' => self::OPEN_LONGITUDE];
        }

        /**
         * The check-in with a protected location that most tests read
         */
        protected function protectedCheckin(): Checkin
        {
            if (!static::$checkin) {
                static::$checkin = $this->checkIn([]);
            }

            return static::$checkin;
        }

        /**
         * Check in as the owner, with the form, over HTTP.
         */
        protected function checkIn(array $fields): Checkin
        {
            $marker = static::marker();
            $fields += [
                'body'         => 'What happened there ' . $marker,
                'placename'    => static::$place,
                'user_address' => '17 ' . static::$street,
                'lat'          => self::LATITUDE,
                'long'         => self::LONGITUDE,
                'anonymity'    => 'Yes',
                'access'       => 'PUBLIC',
            ];
            $tokens = $this->tokenFields($this->jar(static::$owner), '/checkin/edit');
            $answer = $this->request('POST', '/checkin/edit', $tokens + $fields, static::$owner);
            $this->assertContains($answer['status'], [301, 302, 303], 'The check-in should be saved: ' . substr(strip_tags($answer['body']), 0, 300));

            Idno::site()->db()->setIgnoreAccess(true);
            $found = Checkin::get(['owner' => static::$owner->getUUID()], [], 20);
            Idno::site()->db()->setIgnoreAccess(false);
            foreach ($found ?: [] as $checkin) {
                if (strpos((string) $checkin->body, $marker) !== false) {
                    static::$cleanup[] = $checkin;

                    return $checkin;
                }
            }
            $this->fail('The check-in was not stored.');
        }

        /**
         * The check-in in the JSON form of its page
         */
        protected function jsonOf($path, $as = null)
        {
            $answer = $this->get($path . '?_t=json', $as);
            $json = json_decode($answer['body'], true);
            $this->assertIsArray($json['object'] ?? null, 'The JSON form of ' . $path . ' should hold the check-in');

            return $json['object'] + ['latitude' => null, 'longitude' => null, 'placeName' => null, 'address' => null];
        }

        protected function saveAs($member, Checkin $checkin)
        {
            $old = $this->swapUser($member);
            $result = $checkin->save();
            $this->swapUser($old);

            return $result;
        }
    }

}
