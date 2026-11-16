<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaMigrator;
use Tests\Storage\Contract\IndexedFieldsContract;
use Tests\Storage\Fixtures\IndexedFields\FieldRecord;
use Tests\Storage\Fixtures\IndexedFields\UniqueRecord;
use Tests\Storage\StatementRecorder;

/**
 * Declared indexed fields on SQLite: a new in-memory database per test, set up as the site's
 * connections are, with the schema migrations run. Needs nothing but PHP: the unit tier.
 *
 * An indexed field is an index on an expression (`idno_<collection>_ix_<field>`, COLLATE NOCASE);
 * SQLite indexes the whole value, so it reports no prefix length.
 */
final class IndexedFieldsSqliteTest extends IndexedFieldsContract
{
    protected function newConnection(StatementRecorder $recorder): Connection
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new SqliteConnectionSetup(), $recorder]),
        );
        (new SchemaMigrator($connection))->migrate();

        return $connection;
    }

    protected function dropConnection(Connection $connection): void
    {
        $connection->close();
    }

    protected function newDialect(Connection $connection): Dialect
    {
        return new SqliteDialect($connection);
    }

    protected function expectedLength(int $declared): ?int
    {
        return null;
    }

    public function testTheIndexIsAnExpressionIndexThatQueriesUse(): void
    {
        $this->indexes->reconcile(FieldRecord::class, UniqueRecord::class);
        $this->saveAll(['a' => ['f' => 'x', 'code' => 'c'], 'b' => ['f' => 'y']]);

        $indexes = $this->connection->fetchAllKeyValue("select name, sql from sqlite_master where type = 'index' and name like 'idno_entities_ix_%' order by name");
        self::assertSame(['idno_entities_ix_code', 'idno_entities_ix_f'], array_keys($indexes));
        self::assertStringStartsWith('CREATE UNIQUE INDEX', strtoupper((string) $indexes['idno_entities_ix_code']));
        self::assertStringContainsString('collate nocase', strtolower((string) $indexes['idno_entities_ix_f']));
        self::assertStringNotContainsString('idno_ci', (string) $indexes['idno_entities_ix_f'], 'built-in collations only');

        foreach ([new \Idno\Storage\Criterion\Equals('f', 'X'), new \Idno\Storage\Criterion\In('f', ['x', 'y'])] as $criterion) {
            self::assertNotSame([], $this->ids($criterion));
            $statement = $this->recorder->last('/^select t\./');
            $plan = implode("\n", array_column($this->connection->fetchAllAssociative('explain query plan ' . $statement['sql'], $statement['params']), 'detail'));
            self::assertStringContainsString('USING INDEX idno_entities_ix_f', $plan, $statement['sql']);
        }
    }
}
