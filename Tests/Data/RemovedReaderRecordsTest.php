<?php

namespace Tests\Data {

    use Idno\Common\Entity;
    use Idno\Core\Idno;

    /**
     * The feed reader's classes were removed in November 2026, and nothing it stored was: the
     * `reader` collection and its tables stay, and a record of one of the removed classes
     * (which the reader in fact saved to `entities`) stays where it is. Such a record is not
     * a post: lookups do not return it, the pages that list posts are drawn without it, and
     * its own address answers as the address of a post that does not exist.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class RemovedReaderRecordsTest extends \Tests\BrowserTestCase
    {

        const CLASSES = [
            'Idno\Entities\Reader\Feed',
            'Idno\Entities\Reader\FeedItem',
            'Idno\Entities\Reader\Subscription',
        ];

        private static $member;

        /// The member's own post, which every listing must still show
        private static $post;

        private static $postMarker;

        /// The stored records: 'collection', 'id', 'uuid', 'marker', 'stored', by "<class> in <collection>"
        private static $records = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$member = static::member('leftover');
        }

        public static function tearDownAfterClass(): void
        {
            foreach (static::$records as $record) {
                Idno::site()->db()->deleteRecord($record['id'], $record['collection']);
            }
            static::$records = [];
            static::$post = null;
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            parent::setUp();
            if (static::$post) {
                return;
            }
            static::$postMarker = static::marker();
            static::$post = $this->post(static::$member, 'A real post ' . static::$postMarker);

            $db = Idno::site()->db();
            foreach (['entities', 'reader'] as $collection) {
                foreach (self::CLASSES as $class) {
                    $marker = static::marker();
                    $record = [
                        'entity_subtype' => $class,
                        'owner'          => static::$member->getUUID(),
                        'title'          => 'Leftover ' . $marker,
                        'body'           => 'Leftover body ' . $marker,
                        'url'            => 'https://example.com/' . $marker,
                        'feed_url'       => 'https://example.com/feed/' . $marker,
                        'slug'           => 'leftover-' . $marker,
                        'access'         => 'PUBLIC',
                        'publish_status' => 'published',
                        'created'        => time(),
                        'updated'        => time(),
                    ];
                    $id = (string) $db->saveRecord($collection, $record);
                    $this->assertNotEmpty($id, "A record should be stored in $collection.");
                    $record['_id'] = $db->processID($id);
                    $record['uuid'] = Idno::site()->config()->getURL() . 'view/' . $id;
                    $this->assertNotEmpty($db->saveRecord($collection, $record));

                    static::$records["$class in $collection"] = [
                        'collection' => $collection,
                        'id'         => $id,
                        'uuid'       => $record['uuid'],
                        'slug'       => $record['slug'],
                        'marker'     => $marker,
                        'stored'     => $this->stored($collection, $id),
                    ];
                }
            }
        }

        public static function records()
        {
            $cases = [];
            foreach (['entities', 'reader'] as $collection) {
                foreach (self::CLASSES as $class) {
                    $cases["$class in $collection"] = ["$class in $collection"];
                }
            }

            return $cases;
        }

        public static function viewers()
        {
            return ['a visitor' => [false], 'the member who owns the records' => [true]];
        }

        public function testReaderCollectionStillTakesAndGivesRecords()
        {
            $record = static::$records[self::CLASSES[0] . ' in reader'];

            $this->assertIsArray($record['stored'], 'The reader collection should still hold what is saved to it.');
            $this->assertSame(self::CLASSES[0], $record['stored']['entity_subtype']);
            $this->assertNotEmpty(Idno::site()->db()->getRecordByUUID($record['uuid'], 'reader'));
        }

        public function testInstallationSchemaStillHasTheReaderTables()
        {
            $schema = file_get_contents(dirname(__DIR__, 2) . '/warmup/schemas/mysql/mysql.sql');

            foreach (['reader', 'reader_search', 'reader_metadata'] as $table) {
                $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $schema, "A new installation should still get the $table table.");
            }
        }

        public function testNoUpgradeDropsTheReaderTables()
        {
            foreach (glob(dirname(__DIR__, 2) . '/warmup/schemas/mysql/*.sql') as $file) {
                $this->assertDoesNotMatchRegularExpression('/\b(drop|truncate|rename)\s+table\b[^;]*\breader/i', file_get_contents($file), basename($file) . ' should leave the reader tables alone.');
                $this->assertDoesNotMatchRegularExpression('/\bdelete\s+from\s+`?reader/i', file_get_contents($file), basename($file) . ' should delete nothing from the reader tables.');
            }
        }

        /**
         * @dataProvider records
         */
        public function testLookupsDoNotReturnTheRecord($which)
        {
            $record = static::$records[$which];
            $db = Idno::site()->db();

            foreach ([false, true] as $ignoreAccess) {
                $db->setIgnoreAccess($ignoreAccess);
                try {
                    $this->assertEmpty(Entity::getByUUID($record['uuid'], false), 'Not found by its UUID.');
                    $this->assertEmpty(Entity::getByID($record['id']), 'Not found by its id.');
                    $this->assertEmpty(Entity::getBySlug($record['slug']), 'Not found by its slug.');
                    $this->assertEmpty(Entity::getByURL($record['uuid'], false), 'Not found by its address.');
                } finally {
                    $db->setIgnoreAccess(false);
                }
            }
        }

        public function testListingOfTheOwnersThingsHoldsEntitiesOnly()
        {
            $db = Idno::site()->db();
            $db->setIgnoreAccess(true);
            try {
                $found = Entity::getFromAll(['owner' => static::$member->getUUID()], [], 100);
            } finally {
                $db->setIgnoreAccess(false);
            }

            $this->assertNotEmpty($found);
            $uuids = [];
            foreach ($found as $entity) {
                $this->assertInstanceOf(Entity::class, $entity, 'A listing should hold entities only.');
                $uuids[] = $entity->getUUID();
            }
            $this->assertContains(static::$post->getUUID(), $uuids, 'The member\'s post should be listed.');
            foreach (static::$records as $record) {
                $this->assertNotContains($record['uuid'], $uuids);
            }
        }

        public static function listings()
        {
            $cases = [];
            foreach (['a visitor' => false, 'the owner' => true] as $name => $member) {
                foreach (['/', '/?_t=rss', '/?_t=json', '/content/all/', '/content/all/?_t=rss', '/?q={post}', '/?q={leftover}', '/profile/{handle}', '/profile/{handle}?_t=rss', '/profile/{handle}?_t=json', '/profile/{handle}/all'] as $path) {
                    $cases["$path for $name"] = [$path, $member];
                }
            }

            return $cases;
        }

        /**
         * @dataProvider listings
         */
        public function testListingIsDrawnWithoutTheRecords($path, $asMember)
        {
            $searchesForLeftover = (strpos($path, '{leftover}') !== false);
            $path = strtr($path, [
                '{handle}'   => static::$member->getHandle(),
                '{post}'     => static::$postMarker,
                '{leftover}' => static::$records[self::CLASSES[1] . ' in entities']['marker'],
            ]);

            $result = $this->get($path, $asMember ? static::$member : null);

            $this->assertSame(200, $result['status'], "$path should be served.");
            if (!$searchesForLeftover) {
                $this->assertStringContainsString(static::$postMarker, $result['body'], "$path should list the member's post.");
            }
            foreach (static::$records as $which => $record) {
                $this->assertStringNotContainsString('Leftover ' . $record['marker'], $result['body'], "$path should not show the record of $which.");
                $this->assertStringNotContainsString('Leftover body ' . $record['marker'], $result['body']);
            }
        }

        /**
         * @dataProvider records
         */
        public function testOwnAddressAnswersAsAPostThatDoesNotExist($which)
        {
            $record = static::$records[$which];

            foreach ([null, static::$member] as $as) {
                foreach (['', '?_t=json', '?_t=rss'] as $query) {
                    $missing = $this->get('/view/' . $this->unusedId() . $query, $as);
                    $this->assertContains($missing['status'], [404, 410]);

                    $result = $this->get('/view/' . $record['id'] . $query, $as);

                    $this->assertSame($missing['status'], $result['status'], "/view/<id>$query of the record of $which should answer as a post that does not exist does.");
                    $this->assertStringNotContainsString($record['marker'], $result['body']);
                }
            }
        }

        /**
         * @dataProvider records
         */
        public function testOwnAddressTakesNoChangeOrDeletion($which)
        {
            $record = static::$records[$which];
            $path = '/view/' . $record['id'];

            foreach (['POST', 'DELETE'] as $method) {
                $fields = $this->tokenFields($this->jar(static::$member), $path) + ['body' => 'changed'];
                // PHP reads form fields from the body of a POST only
                $result = ($method === 'POST')
                    ? $this->request($method, $path, $fields, static::$member)
                    : $this->request($method, $path . '?' . http_build_query($fields), null, static::$member);

                $this->assertLessThan(500, $result['status'], "$method $path should not end with an error page.");
                $this->assertNotSame(200, $result['status']);
            }

            $this->assertSame($record['stored'], $this->stored($record['collection'], $record['id']), 'The record should be as it was stored.');
        }

        /**
         * Runs last (PHPUnit runs the tests in the order they are written): after every page
         * above has been asked for, each record is as it was stored.
         *
         * @dataProvider records
         */
        public function testRecordIsUntouched($which)
        {
            $record = static::$records[$which];

            $this->assertNotEmpty($record['stored']);
            $this->assertSame($record['stored'], $this->stored($record['collection'], $record['id']), "The record of $which should be as it was stored.");
        }

        /**
         * A record as the database holds it, as plain values
         */
        private function stored($collection, $id)
        {
            $record = Idno::site()->db()->getRecord($id, $collection);

            return $record ? json_decode(json_encode($record), true) : $record;
        }

        /**
         * An id of the form the database gives, which nothing has
         */
        private function unusedId()
        {
            return (string) Idno::site()->db()->processID(bin2hex(random_bytes(12)));
        }
    }

}
