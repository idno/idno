<?php

declare(strict_types=1);

namespace Tests\Storage\Contract;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Attribute\IndexedField;
use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Criterion;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\GreaterThan;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\LessThan;
use Idno\Storage\Criterion\NotEquals;
use Idno\Storage\Criterion\NotIn;
use Idno\Storage\EntityClassFinder;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\IndexedFields;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\RecordIndex;
use Idno\Storage\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Storage\Fixtures\IndexedFields\AccessRecord;
use Tests\Storage\Fixtures\IndexedFields\ActorRecord;
use Tests\Storage\Fixtures\IndexedFields\ActorRecordWithoutIndex;
use Tests\Storage\Fixtures\IndexedFields\ChildRecord;
use Tests\Storage\Fixtures\IndexedFields\ConflictingRecord;
use Tests\Storage\Fixtures\IndexedFields\DeclaringParent;
use Tests\Storage\Fixtures\IndexedFields\FieldRecord;
use Tests\Storage\Fixtures\IndexedFields\MissingCollectionRecord;
use Tests\Storage\Fixtures\IndexedFields\UniqueRecord;
use Tests\Storage\Fixtures\IndexedFieldsInvalid\InvalidNameRecord;
use Tests\Storage\StatementRecorder;

/**
 * Declared indexed fields (IndexedFields, and JsonRecordStore's queries on them), against one
 * database engine. Each test has a new private database with the schema migrations run, with the
 * store, the catalog and IndexedFields on one connection that records its statements (for EXPLAIN).
 * Dependency-free, like RecordStoreContract: a binding supplies the database.
 */
abstract class IndexedFieldsContract extends TestCase
{
    protected Connection $connection;

    protected StatementRecorder $recorder;

    protected Dialect $dialect;

    protected SchemaCatalog $catalog;

    protected IndexedFields $indexes;

    protected JsonRecordStore $store;

    /**
     * A new private database with the schema migrations run, on a connection with the recorder
     * among its middlewares.
     */
    abstract protected function newConnection(StatementRecorder $recorder): Connection;

    /**
     * Removes the database newConnection() made.
     */
    abstract protected function dropConnection(Connection $connection): void;

    abstract protected function newDialect(Connection $connection): Dialect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new StatementRecorder();
        $this->connection = $this->newConnection($this->recorder);
        $this->dialect = $this->newDialect($this->connection);
        $this->catalog = new SchemaCatalog($this->connection);
        $this->indexes = new IndexedFields($this->connection, $this->dialect, $this->catalog);
        $this->store = new JsonRecordStore(
            $this->connection,
            $this->dialect,
            new FixedRecordDefaults('http://indexed.test/'),
            new IdentityTypeMap(),
            $this->catalog,
            static fn (string $text): string => $text,
            false,
            false,
        );
    }

    protected function tearDown(): void
    {
        $this->dropConnection($this->connection);
        parent::tearDown();
    }

    /**
     * The fixture classes' directory.
     */
    protected static function fixtures(): string
    {
        return dirname(__DIR__) . '/Fixtures/IndexedFields';
    }

    /**
     * Saves records (each given an _id, a uuid and a distinct created) into `entities`.
     *
     * @param array<string, array<string, mixed>> $records by id
     */
    protected function saveAll(array $records, string $collection = 'entities'): void
    {
        $created = 1_700_000_000;
        foreach ($records as $id => $fields) {
            $record = ['_id' => (string) $id, 'uuid' => 'http://indexed.test/view/' . $id, 'created' => $created++] + $fields;
            self::assertSame((string) $id, $this->store->save($collection, $record), 'saving ' . $id);
        }
    }

    /**
     * @param array<string, mixed>|Criterion $query a legacy search array, or one criterion
     */
    protected static function criteria(array|Criterion $query): Criteria
    {
        return is_array($query) ? Criteria::fromLegacyArray($query) : new Criteria(new AllOf([$query]));
    }

    /**
     * @param array<string, mixed>|Criterion $query a legacy search array, or one criterion
     *
     * @return list<string> the ids found, in the store's order
     */
    protected function ids(array|Criterion $query, string $collection = 'entities'): array
    {
        return array_column($this->store->find($collection, self::criteria($query), 100, 0), '_id');
    }

    public function testReconcileCreatesTheIndexAndIsIdempotent(): void
    {
        self::assertSame(['access'], $this->catalog->indexedFields('entities'), 'the schema\'s own');

        self::assertSame(['entities.actor_id'], $this->indexes->reconcile(ActorRecord::class));
        self::assertSame(['access', 'actor_id'], $this->catalog->indexedFields('entities'));
        self::assertSame([], $this->indexes->reconcile(ActorRecord::class));
        self::assertSame([], $this->indexes->conflicts());
        self::assertSame(['access', 'actor_id'], $this->catalog->indexedFields('entities'));
        self::assertSame(['unique' => false, 'length' => $this->expectedLength(IndexedField::MAX_LENGTH)], $this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'actor_id'));
    }

    public function testAQueryGivesTheSameResultBeforeAndAfterTheIndexExists(): void
    {
        $this->saveAll([
            'value' => ['f' => 'value'],
            'upper' => ['f' => 'VALUE'],
            'missing' => ['g' => 'value'],
            'empty' => ['f' => ''],
            'true' => ['f' => true],
            'false' => ['f' => false],
            'null' => ['f' => null],
            'zero' => ['f' => 0],
            'zero_text' => ['f' => '0'],
            'int' => ['f' => 42],
            'float' => ['f' => 1.5],
            'small_float' => ['f' => 0.1 + 0.2],
            'other' => ['f' => 'zeta'],
            'unicode' => ['f' => "Été \u{1F600}"],
            'underscore' => ['f' => 'a_b'],
            // Where the engines' unquoting differs (MariaDB JSON_VALUE(), MySQL JSON_UNQUOTE(),
            // SQLite json_extract()): escapes, \u-escaped and four-byte characters, long strings,
            // and strings that read like the other JSON types.
            'quote' => ['f' => "say \"hi\" \\ back\nline"],
            'accent' => ['f' => 'café'],
            'emoji' => ['f' => "\u{1F600}"],
            'long600' => ['f' => str_repeat('L', 600) . 'end'],
            'long300' => ['f' => str_repeat('M', 300)],
            'null_text' => ['f' => 'null'],
            'true_text' => ['f' => 'true'],
            'one_text' => ['f' => '1'],
            'one' => ['f' => 1],
        ]);
        $exact = [
            'equals quote' => [new Equals('f', "say \"hi\" \\ back\nline"), ['quote']],
            'equals accent' => [new Equals('f', 'café'), ['accent']],
            'equals emoji' => [new Equals('f', "\u{1F600}"), ['emoji']],
            'equals unicode' => [new Equals('f', "Été \u{1F600}"), ['unicode']],
            'equals long600' => [new Equals('f', str_repeat('L', 600) . 'end'), ['long600']],
            'equals long600 prefix' => [new Equals('f', str_repeat('L', 600)), []],
            'equals long300' => [new Equals('f', str_repeat('M', 300)), ['long300']],
            'equals null text' => [new Equals('f', 'null'), ['null_text']],
            'equals true text' => [new Equals('f', 'true'), ['true_text']],
            'equals 1 text' => [new Equals('f', '1'), ['one', 'one_text', 'true']],
            'equals 1' => [new Equals('f', 1), ['one', 'one_text', 'true']],
            'equals true' => [new Equals('f', true), ['one', 'one_text', 'true']],
            'equals 0' => [new Equals('f', '0'), ['zero_text', 'zero', 'null', 'false', 'empty']],
            'equals null' => [new Equals('f', null), []],
        ];
        $queries = array_map(static fn (array $pair): Criterion => $pair[0], $exact) + [
            'equals' => new Equals('f', 'value'),
            'equals false' => new Equals('f', false),
            'equals int' => new Equals('f', 42),
            'equals int text' => new Equals('f', '42'),
            'equals float' => new Equals('f', 1.5),
            'equals small float' => new Equals('f', 0.1 + 0.2),
            'in' => new In('f', ['value', 'zeta', '1']),
            'in with null' => new In('f', ['zeta', null]),
            'in nothing' => new In('f', []),
            'not equals' => new NotEquals('f', 'value'),
            'not equals null' => new NotEquals('f', null),
            'not in' => new NotIn('f', ['value', '0']),
            'not in with null' => new NotIn('f', ['value', null]),
            'not in nothing' => new NotIn('f', []),
            'less than' => new LessThan('f', 'm'),
            'less than underscore' => new LessThan('f', 'aZ'),
            'greater than' => new GreaterThan('f', 'm'),
            'greater than number' => new GreaterThan('f', '2'),
            'any of' => new AnyOf([new Equals('f', 'value'), new Equals('f', 42)]),
            'all of' => new AllOf([new NotEquals('f', 'value'), new LessThan('f', 'b')]),
        ];
        $before = [];
        foreach ($queries as $name => $query) {
            $before[$name] = [$this->ids($query), $this->store->count('entities', self::criteria($query))];
        }

        self::assertSame(['entities.f'], $this->indexes->reconcile(FieldRecord::class));

        foreach ($queries as $name => $query) {
            self::assertSame($before[$name], [$this->ids($query), $this->store->count('entities', self::criteria($query))], $name);
        }
        foreach ($exact as $name => [, $ids]) {
            self::assertSame($ids, $before[$name][0], $name . ' (before and after the index)');
        }
        self::assertStringContainsString($this->dialect->indexedFieldValue('t', 'f'), $this->recorder->last('/^select count/')['sql'], 'the query goes through the index');
        // A few results, so that the comparison is not between two empty sets.
        self::assertSame(['upper', 'value'], $before['equals'][0]);
        self::assertSame(['int'], $before['equals int text'][0]);
        self::assertSame(23, $before['not in nothing'][1]);
    }

    /**
     * `access` is indexed by the schema itself, in every collection (no class declares it, and
     * reconciling creates nothing for it): a query on it matches exactly what a query on a declared
     * field holding the same values matches, for every operator.
     */
    public function testTheSchemasAccessIndexMatchesAsADeclaredFieldDoes(): void
    {
        foreach (['entities', 'reader', 'site'] as $collection) {
            self::assertContains('access', $this->catalog->indexedFields($collection), $collection);
        }
        self::assertSame(['entities.f'], $this->indexes->reconcile(FieldRecord::class));

        $values = [
            'value' => 'value',
            'upper' => 'VALUE',
            'public' => 'PUBLIC',
            'empty' => '',
            'true' => true,
            'false' => false,
            'null' => null,
            'zero' => 0,
            'zero_text' => '0',
            'int' => 42,
            'float' => 1.5,
            'small_float' => 0.1 + 0.2,
            'other' => 'zeta',
            'unicode' => "Été \u{1F600}",
            'underscore' => 'a_b',
            'quote' => "say \"hi\" \\ back\nline",
            'emoji' => "\u{1F600}",
            'null_text' => 'null',
            'true_text' => 'true',
            'one' => 1,
            'longest' => str_repeat('x', 254) . 'y',
            'list' => ['PUBLIC', 'b'],
            'object' => ['k' => 'PUBLIC'],
        ];
        $records = ['missing' => ['g' => 'value']];
        foreach ($values as $id => $value) {
            $records[$id] = ['access' => $value, 'f' => $value];
        }
        $this->saveAll($records);

        $queries = static fn (string $field): array => [
            'equals' => new Equals($field, 'value'),
            'equals public' => new Equals($field, 'public'),
            'equals longest' => new Equals($field, str_repeat('x', 254) . 'y'),
            'equals a prefix of longest' => new Equals($field, str_repeat('x', 254)),
            'equals emoji' => new Equals($field, "\u{1F600}"),
            'equals quote' => new Equals($field, "say \"hi\" \\ back\nline"),
            'equals 1' => new Equals($field, 1),
            'equals true' => new Equals($field, true),
            'equals false' => new Equals($field, false),
            'equals 0' => new Equals($field, '0'),
            'equals null' => new Equals($field, null),
            'equals float' => new Equals($field, 1.5),
            'equals small float' => new Equals($field, 0.1 + 0.2),
            'in' => new In($field, ['PUBLIC', 'zeta', '1']),
            'in with null' => new In($field, ['zeta', null]),
            'in nothing' => new In($field, []),
            'not equals' => new NotEquals($field, 'PUBLIC'),
            'not in' => new NotIn($field, ['value', '0']),
            'not in nothing' => new NotIn($field, []),
            'less than' => new LessThan($field, 'm'),
            'less than underscore' => new LessThan($field, 'aZ'),
            'greater than' => new GreaterThan($field, 'm'),
            'any of' => new AnyOf([new Equals($field, 'value'), new Equals($field, 42)]),
            'all of' => new AllOf([new NotEquals($field, 'value'), new LessThan($field, 'b')]),
        ];
        $declared = $queries('f');
        foreach ($queries('access') as $name => $query) {
            self::assertSame(
                [$this->ids($declared[$name]), $this->store->count('entities', self::criteria($declared[$name]))],
                [$this->ids($query), $this->store->count('entities', self::criteria($query))],
                $name,
            );
        }
        self::assertStringContainsString($this->dialect->indexedFieldValue('t', 'access'), $this->recorder->last('/^select count/')['sql'], 'the query goes through the index');
        // A few results, so that the comparison is not between two empty sets.
        self::assertSame(['public'], $this->ids(new Equals('access', 'public')));
        self::assertSame(['longest'], $this->ids(new Equals('access', str_repeat('X', 254) . 'Y')));
        self::assertSame([], $this->ids(new Equals('access', str_repeat('x', 254))));
        self::assertSame(['one', 'true'], $this->ids(new Equals('access', 1)));
        self::assertSame(21, $this->store->count('entities', self::criteria(new NotIn('access', []))), 'every scalar value, no list, object or missing field');
    }

    /**
     * No class needs to declare `access`, and one that does changes nothing.
     */
    public function testDeclaringAccessCreatesNothing(): void
    {
        $before = $this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'access');
        self::assertNotNull($before);
        self::assertFalse($before['unique']);

        self::assertSame([], $this->indexes->reconcile(AccessRecord::class));
        self::assertSame([], $this->indexes->conflicts());
        self::assertSame($before, $this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'access'));
    }

    public function testAListValueIsNoLongerMatchedOnceTheFieldIsIndexed(): void
    {
        $this->saveAll(['list' => ['f' => ['a', 'b']], 'object' => ['f' => ['k' => 'a']], 'scalar' => ['f' => 'a']]);
        self::assertSame(['scalar', 'object', 'list'], $this->ids(['f' => 'a']));
        self::assertSame(['scalar', 'object', 'list'], $this->ids(new NotIn('f', [])));

        $this->indexes->reconcile(FieldRecord::class);

        self::assertSame(['scalar'], $this->ids(['f' => 'a']));
        self::assertSame(['scalar'], $this->ids(new NotIn('f', [])));
        self::assertSame([], $this->ids(['f' => 'b']));
        self::assertSame(['object', 'list'], $this->ids(['_id' => ['$in' => ['list', 'object']]]));
    }

    public function testAValueOf300BytesInAnIndexedFieldIsSavedAndFoundInFull(): void
    {
        $this->indexes->reconcile(FieldRecord::class);
        $long = str_repeat('x', 255) . str_repeat('y', 45);
        $other = str_repeat('x', 255) . str_repeat('z', 45);
        self::assertSame(300, strlen($long));

        $this->saveAll(['long' => ['f' => $long], 'other' => ['f' => $other]]);

        self::assertSame(['long'], $this->ids(['f' => $long]));
        self::assertSame(['other'], $this->ids(['f' => strtoupper($other)]));
        self::assertSame([], $this->ids(['f' => str_repeat('x', 255)]), 'the whole value is compared, not the indexed prefix');
        self::assertSame($long, $this->store->findById('entities', 'long')['f'] ?? null);
    }

    /**
     * Two values that differ only in an emoji are different values in an indexed field too (a
     * collation that gave every four-byte character the same weight would equate them).
     */
    public function testValuesThatDifferOnlyInAnEmojiAreDifferentValues(): void
    {
        $this->indexes->reconcile(FieldRecord::class);
        self::assertContains('f', $this->catalog->indexedFields('entities'));
        $this->saveAll(['party' => ['f' => "#party\u{1F389}"], 'balloon' => ['f' => "#party\u{1F388}"]]);

        self::assertSame(['balloon'], $this->ids(['f' => "#party\u{1F388}"]));
        self::assertSame(['balloon'], $this->ids(['f' => ['$in' => ["#party\u{1F388}"]]]));
        self::assertSame(['party'], $this->ids(['f' => ['$not' => "#party\u{1F388}"]]));
        self::assertSame(['party'], $this->ids(['f' => "#party\u{1F389}"]));
    }

    public function testAUniqueFieldRefusesADuplicateIgnoringCase(): void
    {
        self::assertSame(['entities.code'], $this->indexes->reconcile(UniqueRecord::class));

        $this->saveAll(['first' => ['code' => 'ABC']]);
        self::assertFalse($this->store->save('entities', ['_id' => 'second', 'uuid' => 'http://indexed.test/view/second', 'created' => 1_700_000_100, 'code' => 'abc']));
        self::assertNull($this->store->findById('entities', 'second'));
        // Records without the field, or whose field is a list (no indexed value), are never in conflict.
        $this->saveAll(['none1' => ['title' => 'a'], 'none2' => ['title' => 'b'], 'none3' => [], 'list1' => ['code' => ['ABC']], 'list2' => ['code' => ['ABC']]]);
        self::assertSame(6, $this->store->count('entities', Criteria::all()));
        // Re-saving the record itself is no conflict.
        $this->saveAll(['first' => ['code' => 'ABC', 'title' => 'again']]);
        self::assertSame('again', $this->store->findById('entities', 'first')['title'] ?? null);
        self::assertSame(['unique' => true, 'length' => $this->expectedLength(IndexedField::MAX_LENGTH)], $this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'code'));
    }

    public function testAUniqueIndexIsNotCreatedOverDuplicateValues(): void
    {
        $this->saveAll(['a' => ['code' => 'DUP'], 'b' => ['code' => 'dup']]);

        try {
            $this->indexes->reconcile(UniqueRecord::class);
            self::fail('A unique index was created over duplicate values.');
        } catch (DriverException) {
            $this->addToAssertionCount(1);
        }

        self::assertNull($this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'code'));
        self::assertSame(['b', 'a'], $this->ids(['code' => 'DUP']), 'queries still answer');
    }

    public function testAnInvalidNameIsRejected(): void
    {
        foreach (['', 'Upper', 'has-dash', 'has space', 'dotted.name', str_repeat('a', 49), "é"] as $name) {
            try {
                new IndexedField($name);
                self::fail('"' . $name . '" was accepted.');
            } catch (\InvalidArgumentException) {
                // As it should.
            }
        }
        foreach ([0, 256, -1] as $length) {
            try {
                new IndexedField('f', length: $length);
                self::fail('The length ' . $length . ' was accepted.');
            } catch (\InvalidArgumentException) {
                // As it should.
            }
        }
        new IndexedField(str_repeat('a', 48), true, 1);
        new IndexedField('a_1', length: 255);

        try {
            $this->indexes->reconcile(InvalidNameRecord::class);
            self::fail('A class with an invalid declaration was reconciled.');
        } catch (\InvalidArgumentException) {
            // As it should, before creating anything (not even the class's valid declaration).
        }
        self::assertSame(['access'], $this->catalog->indexedFields('entities'));
    }

    public function testAColumnFieldNameIsRejected(): void
    {
        foreach (RecordIndex::COLUMN_FIELDS as $name) {
            try {
                new IndexedField($name);
                self::fail('"' . $name . '" was accepted.');
            } catch (\InvalidArgumentException) {
                // As it should.
            }
        }
        self::assertContains('_id', RecordIndex::COLUMN_FIELDS);
    }

    public function testTheDialectRefusesAnInvalidNameInDdl(): void
    {
        foreach (['bad"name', 'x`; drop table idno_entities; --', 'Upper', ''] as $name) {
            try {
                $this->dialect->indexedFieldStatements($this->connection, 'idno_entities', $name, false, 255);
                self::fail('DDL was built for "' . $name . '".');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            try {
                $this->dialect->indexedFieldValue('t', $name);
                self::fail('SQL was built for "' . $name . '".');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIndexesSurviveAndAreNeverDropped(): void
    {
        $this->indexes->reconcile(ActorRecord::class);
        $this->saveAll(['a' => ['actor_id' => 'x']]);

        self::assertSame([], $this->indexes->reconcile(ActorRecordWithoutIndex::class));
        self::assertSame([], $this->indexes->reconcileDirectory(sys_get_temp_dir() . '/idno-no-such-directory-' . bin2hex(random_bytes(4))));

        self::assertSame(['access', 'actor_id'], $this->catalog->indexedFields('entities'));
        self::assertNotNull($this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'actor_id'));
        self::assertSame(['a'], $this->ids(['actor_id' => 'X']));
    }

    public function testASecondDeclarationWithOtherOptionsChangesNothingAndIsReported(): void
    {
        $this->indexes->reconcile(ActorRecord::class);

        self::assertSame([], $this->indexes->reconcile(ConflictingRecord::class));

        self::assertCount(1, $this->indexes->conflicts());
        self::assertStringContainsString('entities.actor_id', $this->indexes->conflicts()[0]);
        self::assertSame(['unique' => false, 'length' => $this->expectedLength(IndexedField::MAX_LENGTH)], $this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'actor_id'));
        // The next call reports its own conflicts only.
        $this->indexes->reconcile(ActorRecord::class);
        self::assertSame([], $this->indexes->conflicts());
    }

    public function testDeclarationsOfParentClassesApply(): void
    {
        self::assertSame(['reader.parent_field', 'reader.child_field'], $this->indexes->reconcile(ChildRecord::class));
        self::assertSame(['access', 'child_field', 'parent_field'], $this->catalog->indexedFields('reader'));
        self::assertSame(['access'], $this->catalog->indexedFields('entities'));
    }

    public function testAClassWithoutItsOwnEntityTypeCreatesNothing(): void
    {
        self::assertSame([], $this->indexes->reconcile(DeclaringParent::class, \stdClass::class));
        self::assertSame(['access'], $this->catalog->indexedFields('entities'));
        self::assertSame(['access'], $this->catalog->indexedFields('reader'));
    }

    public function testACollectionWithoutATableIsSkipped(): void
    {
        self::assertSame([], $this->indexes->reconcile(MissingCollectionRecord::class));
        self::assertSame([], $this->indexes->conflicts());
    }

    public function testReconcileDirectoryAppliesTheDeclarationsOfTheClassesFound(): void
    {
        $classes = EntityClassFinder::in(self::fixtures());
        self::assertSame([
            AccessRecord::class,
            ActorRecord::class,
            ActorRecordWithoutIndex::class,
            ChildRecord::class,
            ConflictingRecord::class,
            DeclaringParent::class,
            FieldRecord::class,
            MissingCollectionRecord::class,
            UniqueRecord::class,
        ], $classes, 'the unloadable class and the classes without attributes are left out');
        self::assertFalse(class_exists('Tests\\Storage\\Fixtures\\IndexedFields\\DocblockOnly', false), 'a file that mentions the attributes only in a docblock is not even loaded');

        self::assertSame(['entities.actor_id', 'reader.parent_field', 'reader.child_field', 'entities.f', 'entities.code'], $this->indexes->reconcileDirectory(self::fixtures()));
        self::assertCount(1, $this->indexes->conflicts(), 'ConflictingRecord');
        self::assertSame([], $this->indexes->reconcileDirectory(self::fixtures()));
    }

    /**
     * reconcileDirectoryUntil(): bounded by a deadline checked after each index it creates. With a
     * deadline already past it creates exactly one index and says it is not complete; repeated, it
     * creates the rest one at a time, and reports complete once nothing is left to create. Without a
     * deadline it is reconcileDirectory().
     */
    public function testReconcilingCanBeBoundedByADeadline(): void
    {
        $all = ['entities.actor_id', 'reader.parent_field', 'reader.child_field', 'entities.f', 'entities.code'];
        $created = [];
        for ($i = 0; $i < count($all); ++$i) {
            $result = $this->indexes->reconcileDirectoryUntil(microtime(true) - 1, self::fixtures());
            self::assertCount(1, $result['created'], 'pass ' . $i);
            $created[] = $result['created'][0];
            self::assertSame($i === count($all) - 1, $result['complete'], 'pass ' . $i);
        }
        self::assertSame($all, $created);
        self::assertSame(['created' => [], 'complete' => true], $this->indexes->reconcileDirectoryUntil(microtime(true) - 1, self::fixtures()));
        self::assertSame(['created' => [], 'complete' => true], $this->indexes->reconcileDirectoryUntil(null, self::fixtures()));
    }

    public function testAQueryOnAnIndexedFieldIsAComparisonWithTheIndexedValue(): void
    {
        $this->indexes->reconcile(FieldRecord::class);
        $this->saveAll(['a' => ['f' => 'x']]);

        $this->ids(['f' => 'x']);
        $sql = $this->recorder->last('/^select t\./')['sql'];

        self::assertStringContainsString($this->dialect->indexedFieldValue('t', 'f'), $sql);
        self::assertStringNotContainsString('json_each', $sql);
        self::assertStringNotContainsString('json_table', $sql);
    }

    /**
     * The prefix length the engine reports for an index declared with this length (null when it
     * indexes the whole value).
     */
    abstract protected function expectedLength(int $declared): ?int;
}
