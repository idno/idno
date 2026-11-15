<?php

namespace Tests\Data {

    /**
     * Test what the MySQL back-end's saveRecord() stores and what it reports.
     *
     * Every field of a record is copied to the <collection>_metadata table so it can be
     * queried, cut to 255 bytes. Two rules are tested here:
     *
     * - the cut lands on a character boundary, so the indexed copy is always valid UTF-8
     *   (the full value in the stored document is never cut);
     * - saveRecord() only returns an ID for a record it has stored: if the transaction
     *   is rolled back, it returns false.
     */
    class MySQLSaveRecordTest extends \Tests\IdnoTestCase
    {

        /// IDs of records to remove after each test
        private $toDelete = [];

        /// The SQL mode of the connection before the test changed it
        private $sqlMode;

        public function setUp(): void
        {
            if (!(\Idno\Core\Idno::site()->db() instanceof \Idno\Data\MySQL)) {
                $this->markTestSkipped('These tests are specific to the MySQL back-end.');
            }

            // In strict mode (the default since MySQL 5.7 and MariaDB 10.2.4) the server
            // rejects a value it would otherwise cut or mangle with a warning. Make sure
            // these tests run in strict mode whatever the server default is.
            $client = \Idno\Core\Idno::site()->db()->getClient();
            $this->sqlMode = $client->query('select @@session.sql_mode')->fetchColumn();
            $client->exec("set session sql_mode = 'STRICT_TRANS_TABLES'");
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

            $client = \Idno\Core\Idno::site()->db()->getClient();
            $client->exec('set session sql_mode = ' . $client->quote($this->sqlMode));
        }

        /**
         * Values where the 255th byte falls inside a multi-byte character, with the
         * indexed copy we expect: everything up to the last whole character that fits.
         */
        public function straddlingValueProvider()
        {
            return [
                'two-byte characters' => [str_repeat('é', 200), str_repeat('é', 127)],
                'three-byte characters' => ['a' . str_repeat('日', 100), 'a' . str_repeat('日', 84)],
                'four-byte character (emoji)' => [str_repeat('a', 253) . '😀 and the rest', str_repeat('a', 253)],
            ];
        }

        /**
         * A record with a multi-byte character across the 255 byte limit is saved, and
         * the stored document holds the full, unchanged value.
         *
         * @dataProvider straddlingValueProvider
         */
        public function testMultiByteValueAcrossIndexLimitIsSaved($value, $expectedIndexed)
        {
            $id = $this->saveRecord(['note' => $value]);

            $this->assertNotEmpty($id, 'saveRecord() should have returned the ID of the saved record.');

            $record = \Idno\Core\Idno::site()->db()->getRecord($id);
            $this->assertIsArray($record, 'The record should have been stored.');
            $this->assertSame($value, $record['note'], 'The stored value should be byte-identical to the saved one.');
        }

        /**
         * The indexed copy of such a value is cut on a character boundary: it is valid
         * UTF-8, no longer than 255 bytes, and a query for the cut value finds the record.
         *
         * @dataProvider straddlingValueProvider
         */
        public function testIndexedCopyIsCutOnCharacterBoundary($value, $expectedIndexed)
        {
            $id = $this->saveRecord(['note' => $value]);
            $this->assertNotEmpty($id, 'saveRecord() should have returned the ID of the saved record.');

            $indexed = $this->getIndexedValues($id, 'note');
            $this->assertCount(1, $indexed, 'The value should have been indexed once.');
            $this->assertTrue(mb_check_encoding($indexed[0], 'UTF-8'), 'The indexed copy should be valid UTF-8.');
            $this->assertLessThanOrEqual(255, strlen($indexed[0]), 'The indexed copy should be at most 255 bytes.');
            $this->assertSame($expectedIndexed, $indexed[0], 'The indexed copy should end with the last whole character that fits in 255 bytes.');

            $records = \Idno\Core\Idno::site()->db()->getRecords([], ['note' => $expectedIndexed], 10, 0);
            $this->assertIsArray($records, 'A query for the indexed copy should have found the record.');
            $this->assertContains($id, array_column($records, '_id'), 'A query for the indexed copy should have found the record.');
        }

        /**
         * Values in a list are each indexed, and each cut on a character boundary.
         */
        public function testMultiByteValuesInAListAreSaved()
        {
            $values = [str_repeat('é', 200), 'short', 'a' . str_repeat('日', 100)];
            $id = $this->saveRecord(['notes' => $values]);

            $this->assertNotEmpty($id, 'saveRecord() should have returned the ID of the saved record.');

            $record = \Idno\Core\Idno::site()->db()->getRecord($id);
            $this->assertIsArray($record, 'The record should have been stored.');
            $this->assertSame($values, $record['notes'], 'The stored values should be byte-identical to the saved ones.');

            $indexed = $this->getIndexedValues($id, 'notes');
            sort($indexed);
            $expected = [str_repeat('é', 127), 'short', 'a' . str_repeat('日', 84)];
            sort($expected);
            $this->assertSame($expected, $indexed);
        }

        /**
         * Unchanged behaviour: a long value of single-byte characters is indexed as its
         * first 255 bytes, and a value that fits is indexed whole.
         */
        public function testSingleByteValueIsIndexedByItsFirst255Bytes()
        {
            $long = str_repeat('abcdefghij', 30);
            $exact = str_repeat('é', 127) . 'a'; // 255 bytes: nothing to cut
            $id = $this->saveRecord(['note' => $long, 'exact' => $exact, 'short' => 'café']);

            $this->assertNotEmpty($id, 'saveRecord() should have returned the ID of the saved record.');
            $this->assertSame($long, \Idno\Core\Idno::site()->db()->getRecord($id)['note']);

            $this->assertSame([substr($long, 0, 255)], $this->getIndexedValues($id, 'note'));
            $this->assertSame([$exact], $this->getIndexedValues($id, 'exact'));
            $this->assertSame(['café'], $this->getIndexedValues($id, 'short'));

            $records = \Idno\Core\Idno::site()->db()->getRecords([], ['note' => substr($long, 0, 255)], 10, 0);
            $this->assertIsArray($records, 'A query for the first 255 bytes should have found the record.');
            $this->assertContains($id, array_column($records, '_id'), 'A query for the first 255 bytes should have found the record.');
        }

        /**
         * A value that is not valid UTF-8 can't be stored as a JSON document: the save
         * is refused before anything is written, and saveRecord() says so.
         */
        public function testInvalidUtf8ValueIsRefused()
        {
            $id = \Idno\Core\Idno::site()->db()->generateID();

            $result = \Idno\Core\Idno::site()->db()->saveRecord('entities', [
                '_id' => $id,
                'entity_subtype' => 'Idno\Entities\GenericDataItem',
                'note' => str_repeat('a', 300) . "\xC3",
            ]);
            if ($result) {
                $this->toDelete[] = $id;
            }

            $this->assertFalse($result, 'saveRecord() should have returned false.');
            $this->assertFalse(\Idno\Core\Idno::site()->db()->getRecord($id), 'Nothing should have been stored.');
        }

        /**
         * When writing the indexed copies fails, the whole save is rolled back:
         * saveRecord() must return false, not the ID of a record that doesn't exist.
         *
         * The failure is provoked with a field name that is too long for the metadata
         * table, which the server rejects in strict mode. It stands in for any error
         * after the main row has been written.
         */
        public function testRolledBackInsertReturnsFalse()
        {
            $id = \Idno\Core\Idno::site()->db()->generateID();

            $result = \Idno\Core\Idno::site()->db()->saveRecord('entities', [
                '_id' => $id,
                'entity_subtype' => 'Idno\Entities\GenericDataItem',
                'note' => 'This record can not be saved',
                str_repeat('n', 65) => 'The field name is too long',
            ]);
            if ($result) {
                $this->toDelete[] = $id;
            }

            $this->assertFalse($result, 'saveRecord() should have returned false.');
            $this->assertFalse(\Idno\Core\Idno::site()->db()->getRecord($id), 'Nothing should have been stored.');
            $this->assertSame([], $this->getIndexedValues($id, 'note'), 'Nothing should have been indexed.');
        }

        /**
         * When an update is rolled back, saveRecord() returns false and the record that
         * was there before is intact, with its indexed copies.
         */
        public function testRolledBackUpdateReturnsFalseAndKeepsOriginal()
        {
            $id = $this->saveRecord(['note' => 'The original note']);
            $this->assertNotEmpty($id);
            $original = \Idno\Core\Idno::site()->db()->getRecord($id);

            $result = \Idno\Core\Idno::site()->db()->saveRecord('entities', array_merge($original, [
                'note' => 'The replacement note',
                str_repeat('n', 65) => 'The field name is too long',
            ]));

            $this->assertFalse($result, 'saveRecord() should have returned false.');
            $this->assertSame($original, \Idno\Core\Idno::site()->db()->getRecord($id), 'The original record should be intact.');
            $this->assertSame(['The original note'], $this->getIndexedValues($id, 'note'), 'The original indexed copy should be intact.');
        }

        /**
         * A second record that reuses the UUID of an existing one, with a different ID,
         * can't be stored: saveRecord() returns false and the existing record is intact.
         */
        public function testRecordReusingAnExistingUuidReturnsFalseAndKeepsOriginal()
        {
            $id = $this->saveRecord(['note' => 'The original note']);
            $this->assertNotEmpty($id);
            $original = \Idno\Core\Idno::site()->db()->getRecord($id);

            $otherId = \Idno\Core\Idno::site()->db()->generateID();
            $result = \Idno\Core\Idno::site()->db()->saveRecord('entities', [
                '_id' => $otherId,
                'uuid' => $original['uuid'],
                'entity_subtype' => 'Idno\Entities\GenericDataItem',
                'note' => 'The impostor note',
            ]);
            if ($result) {
                $this->toDelete[] = $otherId;
            }

            $this->assertFalse($result, 'saveRecord() should have returned false.');
            $this->assertFalse(\Idno\Core\Idno::site()->db()->getRecord($otherId), 'The second record should not have been stored.');
            $this->assertSame($original, \Idno\Core\Idno::site()->db()->getRecord($id), 'The original record should be intact.');
            $this->assertSame(['The original note'], $this->getIndexedValues($id, 'note'), 'The original indexed copy should be intact.');
        }

        /**
         * A save that fails in the database is a failed save for the entity too:
         * save() returns false, the entity gets no ID, and "saved" is not triggered.
         */
        public function testEntitySaveReportsARolledBackSave()
        {
            $entity = new \Idno\Entities\GenericDataItem();
            $entity->setDatatype('UnitTestObject');
            $entity->setTitle('This entity can not be saved');
            $entity->{str_repeat('n', 65)} = 'The field name is too long';

            $saved = 0;
            \Idno\Core\Idno::site()->events()->addListener('saved', function (\Idno\Core\Event $event) use ($entity, &$saved) {
                if ($event->data()['object'] === $entity) {
                    $saved++;
                }
            });

            $result = $entity->save(true);
            if ($entity->getID()) {
                $this->toDelete[] = $entity->getID();
            }

            $this->assertFalse($result, 'save() should have returned false.');
            $this->assertEmpty($entity->getID(), 'The entity should not have been given an ID.');
            $this->assertSame(0, $saved, 'The "saved" event should not have been triggered.');
            $this->assertEmpty(\Idno\Entities\GenericDataItem::get(['title' => 'This entity can not be saved']), 'Nothing should have been stored.');
        }

        /**
         * Save a record to the entities collection, and remember to remove it.
         *
         * @param  array $fields
         * @return string|false The result of saveRecord()
         */
        private function saveRecord(array $fields)
        {
            $id = \Idno\Core\Idno::site()->db()->generateID();
            $this->toDelete[] = $id;

            return \Idno\Core\Idno::site()->db()->saveRecord('entities', array_merge([
                '_id' => $id,
                'entity_subtype' => 'Idno\Entities\GenericDataItem',
            ], $fields));
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
            $statement = \Idno\Core\Idno::site()->db()->getClient()->prepare('select `value` from entities_metadata where `_id` = :id and `name` = :name');
            $statement->execute([':id' => $id, ':name' => $name]);

            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        }
    }
}
