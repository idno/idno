<?php

namespace Tests\Data {

    /**
     * Test that the MySQL back-end stores 4-byte UTF-8 characters, such as emoji.
     *
     * A record is stored three times: as a JSON document, as search text in
     * <collection>_search, and as one row per field in <collection>_metadata. The JSON
     * document is ASCII, but the other two hold the text itself, so their columns and
     * the connection have to be utf8mb4. MySQL's "utf8" is utf8mb3, which has no room
     * for a 4-byte character: in strict SQL mode the save is refused, and otherwise the
     * search text and the indexed values are cut at the first such character.
     *
     * The second half tests the schema upgrade that converts an existing database.
     */
    class MySQLUtf8mb4Test extends \Tests\IdnoTestCase
    {

        const TITLE = 'Launch day 🚀 retrospective';
        const BODY = 'We shipped it 🎉 and nobody quokkaspotted the difference';
        const TAGS = '#party🎉';

        /// The schema version that came before the upgrade to utf8mb4
        const PREVIOUS_SCHEMA_VERSION = '2020120301';

        /// The columns that hold text, with the length of the index prefix on them (if any)
        const TEXT_COLUMNS = [
            'site_search.search' => null,
            'config_search.search' => null,
            'entities_search.search' => null,
            'reader_search.search' => null,
            'site_metadata.value' => 191,
            'config_metadata.value' => 191,
            'entities_metadata.value' => 191,
            'reader_metadata.value' => 191,
        ];

        /// IDs of records to remove after each test
        private $toDelete = [];

        /// The SQL mode of the connection before the test changed it
        private $sqlMode;

        /// Names of the databases created by a test, to drop after it
        private $scratchDatabases = [];

        public function setUp(): void
        {
            if (!(\Idno\Core\Idno::site()->db() instanceof \Idno\Data\MySQL)) {
                $this->markTestSkipped('These tests are specific to the MySQL back-end.');
            }

            $this->sqlMode = $this->client()->query('select @@session.sql_mode')->fetchColumn();
        }

        public function tearDown(): void
        {
            if (!(\Idno\Core\Idno::site()->db() instanceof \Idno\Data\MySQL)) {
                return;
            }

            foreach ($this->toDelete as $id) {
                \Idno\Core\Idno::site()->db()->deleteRecord($id);
            }
            $this->toDelete = [];

            foreach ($this->scratchDatabases as $name) {
                $this->client()->exec("drop database if exists `{$name}`");
            }
            $this->scratchDatabases = [];

            $this->client()->exec('set session sql_mode = ' . $this->client()->quote($this->sqlMode));
        }

        /**
         * The SQL modes to test in. Strict mode is the default since MySQL 5.7 and
         * MariaDB 10.2.4: the server refuses a value it can't store. Without it, the
         * server stores what it can and raises a warning.
         */
        public function sqlModeProvider()
        {
            return [
                'strict mode' => ['STRICT_TRANS_TABLES'],
                'non-strict mode' => [''],
            ];
        }

        /**
         * A post with an emoji in its title, body and tags is saved.
         *
         * @dataProvider sqlModeProvider
         */
        public function testPostWithEmojiIsSaved($sqlMode)
        {
            $entry = $this->savePost($sqlMode);

            $this->assertNotEmpty($entry->getID(), 'save() should have stored the post and given it an ID.');
            $this->assertIsArray(\Idno\Core\Idno::site()->db()->getRecord($entry->getID()), 'The post should be in the database.');
        }

        /**
         * The post is read back exactly as it was written.
         *
         * @dataProvider sqlModeProvider
         */
        public function testPostWithEmojiReadsBackUnchanged($sqlMode)
        {
            $entry = $this->savePost($sqlMode);
            $this->assertNotEmpty($entry->getID(), 'save() should have stored the post and given it an ID.');

            $loaded = \IdnoPlugins\Text\Entry::getByID($entry->getID());

            $this->assertInstanceOf(\IdnoPlugins\Text\Entry::class, $loaded);
            $this->assertSame(self::TITLE, $loaded->title);
            $this->assertSame(self::BODY, $loaded->body);
            $this->assertSame(self::TAGS, $loaded->tags);
        }

        /**
         * The search text and the indexed values are not cut at the emoji.
         *
         * @dataProvider sqlModeProvider
         */
        public function testSearchTextAndIndexedValuesKeepEmoji($sqlMode)
        {
            $entry = $this->savePost($sqlMode);
            $this->assertNotEmpty($entry->getID(), 'save() should have stored the post and given it an ID.');

            $statement = $this->client()->prepare('select `search` from entities_search where `_id` = :id');
            $statement->execute([':id' => $entry->getID()]);
            $search = $statement->fetchColumn();

            $this->assertStringContainsString(strtolower(self::TITLE), $search, 'The search text should hold the whole title.');
            $this->assertStringContainsString(strtolower(self::TAGS), $search, 'The search text should hold the whole tag.');
            $this->assertStringContainsString(strtolower(self::BODY), $search, 'The search text should hold the whole body.');

            $this->assertSame([self::TITLE], $this->getIndexedValues($entry->getID(), 'title'));
            $this->assertSame([self::BODY], $this->getIndexedValues($entry->getID(), 'body'));
            $this->assertSame([self::TAGS], $this->getIndexedValues($entry->getID(), 'tags'));
        }

        /**
         * A query for a short value that contains an emoji finds the post.
         *
         * @dataProvider sqlModeProvider
         */
        public function testMetadataQueryFindsValueContainingEmoji($sqlMode)
        {
            $entry = $this->savePost($sqlMode);
            $this->assertNotEmpty($entry->getID(), 'save() should have stored the post and given it an ID.');

            $records = \Idno\Core\Idno::site()->db()->getRecords([], ['tags' => self::TAGS], 10, 0);
            $this->assertIsArray($records, 'A query for the tag should have found the post.');
            $this->assertContains($entry->getID(), array_column($records, '_id'), 'A query for the tag should have found the post.');

            $found = \IdnoPlugins\Text\Entry::getOne(['tags' => self::TAGS]);
            $this->assertInstanceOf(\IdnoPlugins\Text\Entry::class, $found);
            $this->assertSame($entry->getID(), $found->getID());
        }

        /**
         * Two values that differ only in an emoji are different values: a query for one
         * does not return the other. (The general_ci and unicode_ci collations give every
         * 4-byte character the same weight, so they would be equal.)
         */
        public function testMetadataQueryTellsEmojiApart()
        {
            $party = $this->savePost('STRICT_TRANS_TABLES', ['tags' => '#party🎉']);
            $balloon = $this->savePost('STRICT_TRANS_TABLES', ['tags' => '#party🎈']);
            $this->assertNotEmpty($party->getID(), 'save() should have stored the first post and given it an ID.');
            $this->assertNotEmpty($balloon->getID(), 'save() should have stored the second post and given it an ID.');

            $records = \Idno\Core\Idno::site()->db()->getRecords([], ['tags' => '#party🎈'], 10, 0);
            $this->assertIsArray($records, 'A query for the second tag should have found the second post.');
            $ids = array_column($records, '_id');

            $this->assertContains($balloon->getID(), $ids, 'A query for the second tag should have found the second post.');
            $this->assertNotContains($party->getID(), $ids, 'A query for the second tag should not have found the first post.');
        }

        /**
         * Full-text search finds the post by a word that comes after an emoji.
         *
         * @dataProvider sqlModeProvider
         */
        public function testFullTextSearchFindsWordAfterEmoji($sqlMode)
        {
            $entry = $this->savePost($sqlMode);
            $this->assertNotEmpty($entry->getID(), 'save() should have stored the post and given it an ID.');

            // Longer than five bytes, so this is a full-text query, not a LIKE
            $records = \Idno\Core\Idno::site()->db()->getRecords([], ['$search' => ['quokkaspotted']], 10, 0);

            $this->assertIsArray($records, 'A search for a word after the emoji should have found the post.');
            $this->assertContains($entry->getID(), array_column($records, '_id'), 'A search for a word after the emoji should have found the post.');
        }

        /**
         * A search for an emoji finds the posts that contain that emoji, and no others.
         * (An emoji is not a word to the full-text index, but a search this short is a
         * LIKE on the search text.)
         */
        public function testSearchForEmojiFindsOnlyThatEmoji()
        {
            $party = $this->savePost('STRICT_TRANS_TABLES', ['title' => 'One', 'body' => 'A party 🎉', 'tags' => '']);
            $balloon = $this->savePost('STRICT_TRANS_TABLES', ['title' => 'Two', 'body' => 'A balloon 🎈', 'tags' => '']);
            $this->assertNotEmpty($party->getID(), 'save() should have stored the first post and given it an ID.');
            $this->assertNotEmpty($balloon->getID(), 'save() should have stored the second post and given it an ID.');

            $records = \Idno\Core\Idno::site()->db()->getRecords([], ['$search' => ['🎈']], 10, 0);
            $this->assertIsArray($records, 'A search for the second emoji should have found the second post.');
            $ids = array_column($records, '_id');

            $this->assertContains($balloon->getID(), $ids, 'A search for the second emoji should have found the second post.');
            $this->assertNotContains($party->getID(), $ids, 'A search for the second emoji should not have found the first post.');
        }

        /**
         * The database the tests run on can store emoji in every text column.
         */
        public function testTextColumnsOfTheCurrentDatabaseAreUtf8mb4()
        {
            $this->assertTextColumnsAreUtf8mb4($this->client(), \Idno\Core\Idno::site()->db()->getDatabase());
        }

        /**
         * A database at the previous schema version is converted when the back-end
         * connects to it, and keeps its rows.
         */
        public function testUpgradeConvertsExistingDatabaseAndKeepsItsRows()
        {
            $name = $this->createScratchDatabase(__DIR__ . '/fixtures/mysql-schema-' . self::PREVIOUS_SCHEMA_VERSION . '.sql');
            $scratch = $this->connectTo($name);
            $rows = $this->insertRowsWrittenBeforeTheUpgrade($scratch);

            $this->assertSame(self::PREVIOUS_SCHEMA_VERSION, $this->getSchemaVersion($scratch), 'The fixture should be at the previous schema version.');
            $this->assertNotSame('utf8mb4', $this->getTextColumns($scratch, $name)['entities_metadata.value']['charset'], 'The fixture should not be in utf8mb4 yet.');

            // Connecting runs checkAndUpgradeSchema()
            $db = $this->backEndFor($name);

            $this->assertGreaterThan((int)self::PREVIOUS_SCHEMA_VERSION, (int)$this->getSchemaVersion($scratch), 'The schema version should have moved on.');
            $this->assertTextColumnsAreUtf8mb4($scratch, $name);
            $this->assertSame($rows, $this->getRows($scratch), 'The rows written before the upgrade should be unchanged.');

            // The upgraded database stores a record with an emoji, and finds it again
            $scratch->exec("set session sql_mode = 'STRICT_TRANS_TABLES'");
            $db->getClient()->exec("set session sql_mode = 'STRICT_TRANS_TABLES'");
            $id = $db->saveRecord('entities', [
                'entity_subtype' => 'Idno\Entities\GenericDataItem',
                'siteid' => 'site-1',
                'title' => self::TITLE,
                'tags' => self::TAGS,
            ]);
            $this->assertNotEmpty($id, 'A record with an emoji should be saved to the upgraded database.');
            $records = $db->getRecords([], ['tags' => self::TAGS], 10, 0);
            $this->assertIsArray($records, 'A query for the tag should have found the record.');
            $this->assertSame([$id], array_column($records, '_id'));

            // A record written before the upgrade is still found by its indexed value and by full-text search
            $records = $db->getRecords([], ['title' => 'Crème brûlée 日本語'], 10, 0);
            $this->assertIsArray($records, 'A query for the old title should have found the old record.');
            $this->assertSame(['old-1'], array_column($records, '_id'));
            $records = $db->getRecords([], ['$search' => ['brûlée']], 10, 0);
            $this->assertIsArray($records, 'A search for a word of the old title should have found the old record.');
            $this->assertSame(['old-1'], array_column($records, '_id'));
        }

        /**
         * The upgrade can be applied again to a database it has already converted: if it
         * is interrupted, it starts from the top on the next request.
         */
        public function testUpgradeCanBeAppliedAgain()
        {
            $name = $this->createScratchDatabase(__DIR__ . '/fixtures/mysql-schema-' . self::PREVIOUS_SCHEMA_VERSION . '.sql');
            $scratch = $this->connectTo($name);
            $rows = $this->insertRowsWrittenBeforeTheUpgrade($scratch);

            $db = $this->backEndFor($name);
            $upgraded = $this->getSchemaVersion($scratch);
            $this->assertGreaterThan((int)self::PREVIOUS_SCHEMA_VERSION, (int)$upgraded, 'The schema version should have moved on.');

            // As if the upgrade had stopped before it recorded the new version
            $scratch->exec("update `versions` set `value` = '" . self::PREVIOUS_SCHEMA_VERSION . "' where `label` = 'schema'");
            $db->checkAndUpgradeSchema();

            $this->assertSame($upgraded, $this->getSchemaVersion($scratch), 'The second run should have recorded the new schema version.');
            $this->assertTextColumnsAreUtf8mb4($scratch, $name);
            $this->assertSame($rows, $this->getRows($scratch), 'The rows written before the upgrade should be unchanged.');
        }

        /**
         * A new installation, created from mysql.sql, has the same text columns and the
         * same schema version as an upgraded one.
         */
        public function testNewInstallationMatchesUpgradedDatabase()
        {
            $schemas = dirname(__DIR__, 2) . '/warmup/schemas/mysql/';

            $new = $this->createScratchDatabase($schemas . 'mysql.sql', 'new');
            $newClient = $this->connectTo($new);

            $old = $this->createScratchDatabase(__DIR__ . '/fixtures/mysql-schema-' . self::PREVIOUS_SCHEMA_VERSION . '.sql', 'old');
            $oldClient = $this->connectTo($old);
            $this->backEndFor($old);

            $this->assertTextColumnsAreUtf8mb4($newClient, $new);
            $this->assertSame($this->getTextColumns($oldClient, $old), $this->getTextColumns($newClient, $new));
            $this->assertSame($this->getSchemaVersion($oldClient), $this->getSchemaVersion($newClient), 'mysql.sql should record the version of the latest upgrade.');
        }

        /**
         * The PDO connection of the back-end under test.
         *
         * @return \PDO
         */
        private function client()
        {
            return \Idno\Core\Idno::site()->db()->getClient();
        }

        /**
         * Save a post with an emoji in its title, body and tags, in the given SQL mode,
         * and remember to remove it.
         *
         * @param  string $sqlMode
         * @param  array  $fields  Values to use instead of the default title, body and tags
         * @return \IdnoPlugins\Text\Entry
         */
        private function savePost($sqlMode, array $fields = [])
        {
            $this->client()->exec('set session sql_mode = ' . $this->client()->quote($sqlMode));

            $fields = array_merge(['title' => self::TITLE, 'body' => self::BODY, 'tags' => self::TAGS], $fields);

            $entry = new \IdnoPlugins\Text\Entry();
            $entry->setOwner($this->user());
            $entry->setTitle($fields['title']);
            $entry->body = $fields['body'];
            $entry->tags = $fields['tags'];
            $entry->save(true);

            if ($entry->getID()) {
                $this->toDelete[] = $entry->getID();
            }

            return $entry;
        }

        /**
         * Retrieve the indexed copies of a field of a record, straight from the
         * metadata table.
         *
         * @param  string $id
         * @param  string $name
         * @return array
         */
        private function getIndexedValues($id, $name)
        {
            $statement = $this->client()->prepare('select `value` from entities_metadata where `_id` = :id and `name` = :name');
            $statement->execute([':id' => $id, ':name' => $name]);

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        }

        /**
         * Create an empty database next to the one the tests run on, load a schema file
         * into it, and remember to drop it. Skips the test if the database user is not
         * allowed to create databases.
         *
         * @param  string $schemaFile
         * @param  string $suffix
         * @return string The name of the new database
         */
        private function createScratchDatabase($schemaFile, $suffix = 'upgrade')
        {
            $name = \Idno\Core\Idno::site()->db()->getDatabase() . '_utf8mb4test_' . $suffix;

            try {
                $this->client()->exec("drop database if exists `{$name}`");
                $this->client()->exec("create database `{$name}`");
            } catch (\PDOException $e) {
                $this->markTestSkipped('The database user can not create a scratch database: ' . $e->getMessage());
            }
            $this->scratchDatabases[] = $name;

            $client = $this->connectTo($name);
            foreach (explode(";\n", file_get_contents($schemaFile)) as $statement) {
                if (trim($statement) !== '') {
                    $client->exec($statement);
                }
            }

            return $name;
        }

        /**
         * Open a connection of our own to a scratch database.
         *
         * @param  string $name
         * @return \PDO
         */
        private function connectTo($name)
        {
            $config = \Idno\Core\Idno::site()->config();
            $dsn = 'mysql:host=' . $config->dbhost . ';dbname=' . $name . ';charset=utf8mb4';
            if (!empty($config->dbport)) {
                $dsn .= ';port=' . $config->dbport;
            }

            return new \PDO($dsn, $config->dbuser, $config->dbpass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }

        /**
         * Connect the MySQL back-end to a scratch database, which upgrades its schema.
         *
         * @param  string $name
         * @return \Idno\Data\MySQL
         */
        private function backEndFor($name)
        {
            $config = \Idno\Core\Idno::site()->config();

            return new \Idno\Data\MySQL($config->dbuser, $config->dbpass, $name, $config->dbhost, $config->dbport);
        }

        /**
         * Write a record to a database at the previous schema version, the way the
         * back-end did: a document, its search text and its indexed values. The text has
         * accented and CJK characters, which utf8mb3 could always store.
         *
         * @param  \PDO $client
         * @return array The rows, as getRows() returns them
         */
        private function insertRowsWrittenBeforeTheUpgrade(\PDO $client)
        {
            $title = 'Crème brûlée 日本語';
            $contents = json_encode(['_id' => 'old-1', 'uuid' => 'http://example.com/view/old-1', 'title' => $title, 'entity_subtype' => 'Idno\Entities\GenericDataItem']);

            $client->prepare("insert into `entities` (`uuid`, `_id`, `siteid`, `owner`, `entity_subtype`, `contents`) values ('http://example.com/view/old-1', 'old-1', 'site-1', '', 'Idno\\\\Entities\\\\GenericDataItem', :contents)")->execute([':contents' => $contents]);
            $client->prepare("insert into `entities_search` (`_id`, `search`) values ('old-1', :search)")->execute([':search' => 'crème brûlée 日本語 ']);
            $insert = $client->prepare("insert into `entities_metadata` (`_id`, `name`, `value`) values ('old-1', :name, :value)");
            $insert->execute([':name' => 'title', ':value' => $title]);
            $insert->execute([':name' => 'long', ':value' => str_repeat('é', 127) . 'a']); // 255 bytes: the most the back-end indexes

            return $this->getRows($client);
        }

        /**
         * Everything in the entities tables of a database.
         *
         * @param  \PDO $client
         * @return array
         */
        private function getRows(\PDO $client)
        {
            return [
                'entities' => $client->query('select `uuid`, `_id`, `siteid`, `owner`, `entity_subtype`, `contents`, `publish_status` from `entities` order by `_id`')->fetchAll(\PDO::FETCH_ASSOC),
                'entities_search' => $client->query('select * from `entities_search` order by `_id`')->fetchAll(\PDO::FETCH_ASSOC),
                'entities_metadata' => $client->query('select * from `entities_metadata` order by `_id`, `name`')->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }

        /**
         * The schema version recorded in a database.
         *
         * @param  \PDO $client
         * @return string
         */
        private function getSchemaVersion(\PDO $client)
        {
            return $client->query("select `value` from `versions` where `label` = 'schema'")->fetchColumn();
        }

        /**
         * The character set, collation and index prefix length of the text columns of a
         * database.
         *
         * @param  \PDO   $client
         * @param  string $database
         * @return array
         */
        private function getTextColumns(\PDO $client, $database)
        {
            $columns = [];
            foreach (array_keys(self::TEXT_COLUMNS) as $column) {
                list($table, $name) = explode('.', $column);

                $statement = $client->prepare('select character_set_name, collation_name from information_schema.columns where table_schema = :db and table_name = :table and column_name = :name');
                $statement->execute([':db' => $database, ':table' => $table, ':name' => $name]);
                $row = $statement->fetch(\PDO::FETCH_NUM);

                $statement = $client->prepare("select sub_part from information_schema.statistics where table_schema = :db and table_name = :table and column_name = :name and index_type = 'BTREE'");
                $statement->execute([':db' => $database, ':table' => $table, ':name' => $name]);
                $prefix = $statement->fetchColumn();

                $columns[$column] = ['charset' => $row[0], 'collation' => $row[1], 'index prefix' => $prefix === false ? null : (int)$prefix];
            }

            return $columns;
        }

        /**
         * Assert that every text column of a database is utf8mb4, with a collation that
         * tells 4-byte characters apart, and that the index on the indexed values is
         * short enough for every InnoDB row format (191 characters is 764 bytes; the
         * limit of the older row formats is 767).
         *
         * @param \PDO   $client
         * @param string $database
         */
        private function assertTextColumnsAreUtf8mb4(\PDO $client, $database)
        {
            $expected = [];
            foreach (self::TEXT_COLUMNS as $column => $prefix) {
                $expected[$column] = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_520_ci', 'index prefix' => $prefix];
            }

            $this->assertSame($expected, $this->getTextColumns($client, $database));
        }
    }
}
