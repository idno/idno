<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\RecordStore;
use Tests\Storage\Contract\RecordStoreContract;

/**
 * Runs the record storage contract against the legacy backend, Idno\Data\MySQL. Since Task 7
 * Idno::site()->db() is the storage adapter, so the test constructs the legacy backend itself
 * (its own PDO connection to the site's database). It needs the booted legacy application
 * (the normal rig suite); the contract itself does not. Deleted with the legacy backend.
 */
final class LegacyMySqlRecordStoreTest extends RecordStoreContract
{
    private static ?\Idno\Data\MySQL $legacy = null;

    private string|false $previousErrorLog = false;

    /**
     * The legacy MySQL backend, built once for the class.
     */
    private static function legacy(): \Idno\Data\MySQL
    {
        return self::$legacy ??= new \Idno\Data\MySQL();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // MySQL-bound: on a site whose database is not MySQL (the CI MongoDB job) there is nothing to test.
        $config = \Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        if (\Idno\Storage\ConnectionParameters::driverFamily($config) !== 'mysql') {
            self::markTestSkipped('The site\'s database is not MySQL.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Several contract tests make the legacy backend fail on purpose (unknown
        // tables, an invalid query) and it logs each failure; keep the output clean.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        parent::tearDown();
    }

    protected function store(): RecordStore
    {
        return new LegacyBackendBinding(self::legacy());
    }

    protected function newId(): string
    {
        return self::legacy()->generateID();
    }

    protected function currentSiteId(): ?string
    {
        $site = \Idno\Core\Idno::site()->site_details();

        return $site ? $site->uuid() : null;
    }

    // ------------------------------------------------ raw legacy arrays on MySQL

    /**
     * Every shape below is sent RAW to Idno\Data\MySQL (getRecords()/countRecords()) and, parsed
     * by Criteria::fromLegacyArray(), to the store; the contract runs through Criteria, so this
     * is what proves that the parser changes nothing MySQL would have done with the raw array.
     * 'none' shapes match no record by nature; 'fails' shapes are ones MySQL cannot run.
     *
     * @return array<string, array{array<int|string, mixed>, string}>
     */
    public static function rawLegacyShapes(): array
    {
        // A no-op operand is combined with a real condition ('owner' => 'o1'), so that "ignored"
        // is visible: the result must be that condition's records, not a filter on the operand.
        $o1 = ['owner' => 'o1'];
        $shapes = [
            // The odd shapes the contract pins one by one
            'zero $lt' => [['n' => ['$lt' => 0]] + $o1, 'subset'],
            'zero $gt' => [['n' => ['$gt' => '0']] + $o1, 'subset'],
            'empty $in' => [['n' => ['$in' => []]] + $o1, 'subset'],
            '$not 0' => [['n' => ['$not' => 0]] + $o1, 'subset'],
            '$not empty string' => [['n' => ['$not' => '']] + $o1, 'subset'],
            '$not empty list' => [['n' => ['$not' => []]] + $o1, 'subset'],
            'unknown operator $regex' => [['n' => ['$regex' => 'x']] + $o1, 'subset'],
            'unknown operator $exists' => [['n' => ['$exists' => true]] + $o1, 'subset'],
            'unknown and known operator' => [['n' => ['$regex' => 'x', '$gt' => '3']], 'subset'],
            'known and unknown operator' => [['n' => ['$gt' => '3', '$regex' => 'x']], 'subset'],
            'plain list as a field value' => [['labels' => ['red', 'blue']] + $o1, 'subset'],
            'empty list as a field value' => [['labels' => []] + $o1, 'subset'],
            'empty search term' => [['$search' => ['']] + $o1, 'subset'],
            'zero search term' => [['$search' => ['0']] + $o1, 'subset'],
            'empty search list' => [['$search' => []] + $o1, 'subset'],
            'only the first search term' => [['$search' => ['abc', 'xyz']], 'subset'],
            'search as a plain string is a field' => [['$search' => 'abc'], 'none'],
            // The MongoDB list form of $or: invalid SQL on MySQL, an AnyOf with no children in memory
            '$or as a list' => [['$or' => [['slug' => 'a'], ['slug' => 'b']]], 'fails'],
            // Shapes the parser refuses (MySQL binds a PHP array and fails)
            'array operand for $lt' => [['n' => ['$lt' => ['x']]], 'refused'],
            '$not holding an array without $in' => [['n' => ['$not' => ['x']]], 'refused'],
            'non-array $in' => [['n' => ['$in' => 'x']], 'refused'],
            // The query shapes the application uses
            'scalar equality' => [['slug' => 'a'], 'subset'],
            'equality with true' => [['flag' => true], 'subset'],
            'equality with null' => [['slug' => null], 'none'],
            'entity_subtype scalar' => [['entity_subtype' => 'T\\A'], 'subset'],
            'entity_subtype $in' => [['entity_subtype' => ['$in' => ['T\\A', 'T\\B']]], 'subset'],
            'entity_subtype $not' => [['entity_subtype' => ['$not' => 'T\\A']], 'subset'],
            'entity_subtype $not $in' => [['entity_subtype' => ['$not' => ['$in' => ['T\\A']]]], 'subset'],
            'entity_subtype $in and $not $in' => [['entity_subtype' => ['$in' => ['T\\A', 'T\\B'], '$not' => ['$in' => ['T\\A']]]], 'subset'],
            'access $in' => [['access' => ['$in' => ['PUBLIC']]], 'subset'],
            'queue garbage collection' => [['completedTs' => ['$lt' => 5000], 'complete' => true, 'queue' => 'q'], 'subset'],
            'queue pending' => [['queue' => 'q', 'complete' => ['$not' => ['$in' => [true]]]], 'subset'],
            'search with a scalar condition' => [['$search' => ['abc'], 'owner' => 'o1'], 'subset'],
            'dotted field name' => [['dotted.field' => 'dv'], 'subset'],
            '$or with an entry per field' => [['$or' => ['slug' => 'a', 'owner' => 'o2']], 'subset'],
        ];
        $out = [];
        foreach ($shapes as $name => [$raw, $kind]) {
            $out[$name] = [$raw, $kind];
        }

        return $out;
    }

    /**
     * The five records the raw shapes are run against: each shape matches some and not all.
     */
    private function rawShapeFixture(): int
    {
        $fixture = [
            ['slug' => 'a', 'n' => 5, 'owner' => 'o1', 'labels' => ['red', 'blue'], 'title' => 'xxabcxx', 'flag' => true, 'dotted.field' => 'dv', 'entity_subtype' => 'T\\A', 'access' => 'PUBLIC', 'queue' => 'q', 'complete' => true, 'completedTs' => 1000],
            ['slug' => 'b', 'n' => -3, 'owner' => 'o2', 'labels' => ['red'], 'title' => 'something', 'flag' => false, 'entity_subtype' => 'T\\B', 'access' => 'PRIVATE', 'queue' => 'q', 'complete' => false],
            ['slug' => 'c', 'n' => 0, 'owner' => 'o1', 'labels' => ['green'], 'title' => 'xxxyzxx', 'entity_subtype' => 'T\\C', 'access' => 'PUBLIC', 'queue' => 'q', 'complete' => true, 'completedTs' => 100000],
            ['n' => 9, 'owner' => 'o3', 'title' => 'xxabcxx', 'entity_subtype' => 'T\\A', 'access' => 'PRIVATE'],
            ['slug' => 'e', 'n' => 4, 'owner' => 'o2', 'labels' => ['blue'], 'entity_subtype' => 'T\\B', 'access' => 'PUBLIC', 'queue' => 'other'],
        ];
        foreach ($fixture as $fields) {
            $this->put($this->r($fields));
        }

        return count($fixture);
    }

    /**
     * Raw legacy array on MySQL == its parsed Criteria through the legacy adapter, which writes it
     * back with toLegacyArray(). Legacy-only on purpose: it
     * dies with Idno\Data\MySQL (Task 9), when the raw arrays stop reaching a database.
     *
     * @dataProvider rawLegacyShapes
     *
     * @param array<int|string, mixed> $raw
     */
    public function testARawLegacyArrayOnMysqlReturnsWhatItsParsedCriteriaReturns(array $raw, string $kind): void
    {
        $total = $this->rawShapeFixture();
        $db = self::legacy();
        $raw += ['contract_marker' => $this->marker];

        $rawRecords = $db->getRecords([], $raw, 100, 0, 'entities');
        $rawIds = is_array($rawRecords) ? array_map(static fn (array $record): string => $record['_id'], $rawRecords) : [];
        $rawCount = $db->countRecords($raw, 'entities');

        if ($kind === 'refused') {
            // MySQL cannot run it (a PHP array is bound as a value) and the parser rejects it.
            $this->assertSame([], $rawIds);
            $this->assertEmpty($rawCount);
            $this->expectException(\InvalidArgumentException::class);
            Criteria::fromLegacyArray($raw);

            return;
        }

        $criteria = Criteria::fromLegacyArray($raw);
        $storeIds = array_map(static fn (array $record): string => $record['_id'], $this->store()->find('entities', $criteria, 100, 0));

        $this->assertSame($rawIds, $storeIds, 'same records in the same order');
        if ($kind === 'fails') {
            $this->assertSame([], $rawIds);
            $this->assertFalse($rawCount, 'MySQL fails the count');
            // The parsed form fails the same way through the legacy adapter (which throws on a failed count).
            $this->expectException(\RuntimeException::class);
            $this->store()->count('entities', $criteria);

            return;
        }
        $this->assertSame($rawCount, $this->store()->count('entities', $criteria));
        $this->assertSame(count($rawIds), $rawCount);
        if ($kind === 'none') {
            $this->assertSame([], $rawIds);
        } else {
            $this->assertNotEmpty($rawIds, 'the shape matches something');
            $this->assertLessThan($total, count($rawIds), 'and not everything');
        }
    }

    // deleteAll() stays skipped here (ownsPrivateDatabase() is false): this
    // binding shares the rig's database, where emptying a collection would
    // destroy other tests' fixtures and the site's own configuration.
}
