<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Criteria;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use Tests\Storage\Contract\IndexedFieldsContract;
use Tests\Storage\Fixtures\IndexedFields\ActorRecord;
use Tests\Storage\Fixtures\IndexedFields\FieldRecord;

/**
 * Declared indexed fields on MySQL or MariaDB: a private database on the rig's server per test
 * (PrivateDatabase::mysql('indexed_fields'), dropped after it), reached through a second
 * connection that records its statements. Skipped when the site's database is not MySQL or MariaDB.
 *
 * An indexed field is a VIRTUAL generated column `ix_<field>` (utf8mb4_unicode_520_ci) with an index
 * of the same name on a prefix of the value (255 characters unless declared otherwise).
 */
final class IndexedFieldsMySqlTest extends IndexedFieldsContract
{
    /** @var array<int, Connection> the private databases' own connections, by the recording connection's object id */
    private array $owners = [];

    protected function newConnection(StatementRecorder $recorder): Connection
    {
        $owner = PrivateDatabase::mysql('indexed_fields');
        $connection = DriverManager::getConnection($owner->getParams(), (new Configuration())->setMiddlewares([$recorder]));
        $this->owners[spl_object_id($connection)] = $owner;

        return $connection;
    }

    protected function dropConnection(Connection $connection): void
    {
        $connection->close();
        $owner = $this->owners[spl_object_id($connection)] ?? null;
        unset($this->owners[spl_object_id($connection)]);
        if ($owner !== null) {
            PrivateDatabase::drop($owner);
        }
    }

    protected function newDialect(Connection $connection): Dialect
    {
        return new MySqlDialect();
    }

    protected function expectedLength(int $declared): ?int
    {
        return $declared;
    }

    public function testTheIndexedFieldIsAVirtualGeneratedColumnWithAnIndex(): void
    {
        $this->indexes->reconcile(FieldRecord::class);

        $column = $this->connection->fetchAssociative(
            'select data_type as data_type, extra as extra, collation_name as collation_name from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            ['idno_entities', 'ix_f'],
        );
        self::assertIsArray($column);
        self::assertSame('longtext', strtolower((string) $column['data_type']));
        self::assertStringContainsString('VIRTUAL', strtoupper((string) $column['extra']));
        self::assertSame('utf8mb4_unicode_520_ci', $column['collation_name']);
        self::assertSame(
            [['index_name' => 'ix_f', 'non_unique' => 1, 'sub_part' => 255]],
            array_map(static fn (array $row): array => ['index_name' => (string) $row['index_name'], 'non_unique' => (int) $row['non_unique'], 'sub_part' => (int) $row['sub_part']], $this->connection->fetchAllAssociative(
                'select index_name as index_name, non_unique as non_unique, sub_part as sub_part from information_schema.statistics where table_schema = database() and table_name = ? and column_name = ?',
                ['idno_entities', 'ix_f'],
            )),
        );
    }

    public function testFourByteCharactersAreIndexedInFull(): void
    {
        // MariaDB's JSON_UNQUOTE() returns utf8mb3, which would turn them into '?'.
        $this->indexes->reconcile(FieldRecord::class);
        $this->saveAll(['emoji' => ['f' => "\u{1F600}x"], 'question' => ['f' => '?x']]);

        self::assertSame("\u{1F600}x", $this->connection->fetchOne('select ix_f from idno_entities where id = ?', ['emoji']));
        self::assertSame(['emoji'], $this->ids(['f' => "\u{1F600}x"]));
        self::assertSame(['question'], $this->ids(['f' => '?x']));
    }

    public function testAnInterruptedCreationIsCompleted(): void
    {
        // The column was added, then the request ended before the index was made.
        $statements = $this->dialect->indexedFieldStatements($this->connection, 'idno_entities', 'actor_id', false, 255);
        self::assertCount(2, $statements);
        $this->connection->executeStatement($statements[0]);
        $this->catalog->forget();
        self::assertSame(['actor_id'], $this->catalog->indexedFields('entities'));
        self::assertNull($this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'actor_id'));

        self::assertSame(['entities.actor_id'], $this->indexes->reconcile(ActorRecord::class));
        self::assertSame(['unique' => false, 'length' => 255], $this->dialect->indexedFieldIndex($this->connection, 'idno_entities', 'actor_id'));
    }

    public function testCreatingWhatAnotherRequestJustCreatedIsNoFailure(): void
    {
        // What a second, concurrent reconcile() meets: the column and the index exist already.
        $statements = $this->dialect->indexedFieldStatements($this->connection, 'idno_entities', 'actor_id', false, 255);
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
        foreach ($statements as $statement) {
            try {
                $this->connection->executeStatement($statement);
                self::fail('The statement ran twice: ' . $statement);
            } catch (DriverException $e) {
                self::assertTrue($this->dialect->isAlreadyDefined($e), $e->getMessage());
            }
        }
    }

    public function testHotQueriesUseAnIndex(): void
    {
        $root = dirname(__DIR__, 2);
        $created = array_merge($this->indexes->reconcileDirectory($root . '/Idno'), $this->indexes->reconcileDirectory($root . '/IdnoPlugins'));
        foreach (['entities.slug', 'entities.access', 'entities.handle', 'entities.email'] as $field) {
            self::assertContains($field, $created);
        }

        $user = 'Idno\\Entities\\User';
        $types = ['IdnoPlugins\\Status\\Status' => 300, 'IdnoPlugins\\Text\\Entry' => 300, 'IdnoPlugins\\Photo\\Photo' => 300, 'IdnoPlugins\\Like\\Like' => 300, $user => 200, 'Idno\\Entities\\AsynchronousQueuedEvent' => 300, 'IdnoPlugins\\ActivityPub\\Entities\\ActivityPubFollower' => 300];
        $rows = [];
        $n = 0;
        foreach ($types as $type => $count) {
            for ($i = 0; $i < $count; $i++, $n++) {
                $id = sprintf('%024x', $n + 1);
                $document = ['_id' => $id, 'uuid' => 'http://indexed.test/view/' . $id, 'entity_subtype' => $type, 'created' => 1_700_000_000 + $n, 'owner' => 'http://indexed.test/profile/user' . ($n % 200), 'access' => ['PUBLIC', 'SITE', 'http://indexed.test/view/group' . ($n % 50)][$n % 3]];
                $document += $type === $user
                    ? ['handle' => 'user' . $i, 'email' => 'user' . $i . '@indexed.test', 'admin' => $i === 0]
                    : ['slug' => 'post-' . $n, 'title' => 'Post ' . $n, 'tags' => ['a', 'b']];
                $rows[] = [$id, $document['uuid'], $type, $document['owner'], (string) $document['created'], 'published', (string) json_encode($document), ''];
            }
        }
        self::assertCount(2000, $rows);
        foreach (array_chunk($rows, 250) as $chunk) {
            $this->connection->executeStatement(
                'insert into idno_entities (id, uuid, type, owner, created, publish_status, data, search) values ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')),
                array_merge(...$chunk),
            );
        }
        $this->connection->fetchAllAssociative('analyze table idno_entities');

        $listed = ['IdnoPlugins\\Status\\Status', 'IdnoPlugins\\Text\\Entry'];
        $lookups = [
            'slug' => [['slug' => 'post-123'], 'ix_slug'],
            'user by handle' => [['entity_subtype' => $user, 'handle' => 'user42'], 'ix_handle'],
            'user by email' => [['entity_subtype' => $user, 'email' => 'USER42@indexed.test'], 'ix_email'],
        ];
        foreach ($lookups as $name => [$query, $key]) {
            [$plan, $description] = $this->explain($name, $query, 1);
            // The field's own index: what fails if the column had no index, or if the comparison
            // wrapped the column or used another collation.
            self::assertSame($key, $plan['key'], $description);
            self::assertContains($plan['type'], ['ref', 'eq_ref', 'const', 'range'], $description);
        }

        // A listing of one type: an index serves both the filter and the order (no filesort).
        [$plan, $description] = $this->explain('one type and access', ['entity_subtype' => $listed[0], 'access' => ['$in' => ['PUBLIC', 'SITE']]], 10);
        self::assertNotSame('ALL', $plan['type'], $description);
        self::assertStringNotContainsString('filesort', (string) $plan['Extra'], $description);
        self::assertContains($plan['key'], ['type_created', 'ix_access', 'created'], $description);
        self::assertContains('ix_access', explode(',', (string) $plan['possible_keys']), $description);

        // Several types: no full scan, and a filesort only after a range or ref lookup on an index
        // that narrows the rows.
        [$plan, $description] = $this->explain('several types and access', ['entity_subtype' => ['$in' => $listed], 'access' => ['$in' => ['PUBLIC', 'SITE']]], 10);
        self::assertNotSame('ALL', $plan['type'], $description);
        if (str_contains((string) $plan['Extra'], 'filesort')) {
            self::assertContains($plan['key'], ['ix_access', 'type_created'], $description);
            self::assertContains($plan['type'], ['range', 'ref'], $description);
        }
        self::assertNotNull($plan['key'], $description);
        self::assertContains('ix_access', explode(',', (string) $plan['possible_keys']), $description);
    }

    /**
     * Runs the query through the store, then EXPLAINs the statement the store sent.
     *
     * @param array<string, mixed> $query
     *
     * @return array{array<string, mixed>, string} the plan's one row, and a description for failure messages
     */
    private function explain(string $name, array $query, int $expected): array
    {
        $found = $this->store->find('entities', Criteria::fromLegacyArray($query), 10, 0);
        self::assertCount($expected, $found, $name);
        $statement = $this->recorder->last('/^select t\./');
        self::assertStringContainsString('limit 0,10', $statement['sql']);
        $plan = $this->connection->fetchAllAssociative('explain ' . $statement['sql'], $statement['params']);
        $description = $name . ': ' . $statement['sql'] . "\n" . json_encode($plan, JSON_PRETTY_PRINT);
        self::assertCount(1, $plan, $description);

        return [$plan[0], $description];
    }
}
