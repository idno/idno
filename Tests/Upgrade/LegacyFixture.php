<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Doctrine\DBAL\Connection;
use Idno\Storage\IndexedFields;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\RecordIndex;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\Assert;
use Tests\Storage\PrivateDatabase;

/**
 * A private database on the rig's MySQL or MariaDB server holding a 1.x site's tables: the schema
 * of Tests/Upgrade/fixtures/mysql-schema-2020120301.sql, and with $schema 2026111401 the statements
 * of mysql-upgrade-2026111401.sql applied after it, and with $collation every 1.x table then
 * converted to utf8mb4 in that collation (as a site that converted its tables to store emoji did).
 * Never the rig site's database: it is
 * PrivateDatabase::mysql(), which skips the test, saying why, on a site that is not on MySQL or
 * MariaDB (an SQLite rig). drop() removes it; call it in tearDown().
 *
 * insert() writes a record the way the last 1.x backend's saveRecord() laid it out: the main row,
 * its search row and one metadata row per value. The search text is today's derivation of 1.x's
 * (RecordIndex::searchText(), without the quote normaliser), not 1.x's own code. prepareNewSchema() prepares the same database the way the upgrade
 * does before it copies: the schema migrations, then the indexes the classes under Idno/ declare.
 */
final class LegacyFixture
{
    public const SCHEMAS = [2020120301, 2026111401];

    private const SITE_URL = 'https://legacy.example/';

    private int $sequence = 0;

    private function __construct(public readonly Connection $connection)
    {
    }

    /**
     * @param string $purpose the private database's suffix (see PrivateDatabase::mysql())
     */
    public static function create(string $purpose, int $schema = 2020120301, ?string $collation = null): self
    {
        if (!in_array($schema, self::SCHEMAS, true)) {
            throw new \InvalidArgumentException('No 1.x fixture schema ' . $schema . '.');
        }
        $config = \Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        if (\Idno\Storage\ConnectionParameters::driverFamily($config) !== 'mysql') {
            Assert::markTestSkipped('No 1.x site runs on SQLite (or MongoDB): the copy of a 1.x database is tested on a MySQL or MariaDB rig only.');
        }
        $connection = PrivateDatabase::mysql($purpose, false);
        try {
            foreach (self::statements(__DIR__ . '/fixtures/mysql-schema-2020120301.sql') as $statement) {
                $connection->executeStatement($statement);
            }
            if ($schema === 2026111401) {
                foreach (self::statements(__DIR__ . '/fixtures/mysql-upgrade-2026111401.sql') as $statement) {
                    $connection->executeStatement($statement);
                }
            }
            if ($collation !== null) {
                if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $collation) !== 1) {
                    throw new \InvalidArgumentException('Not a utf8mb4 collation: ' . $collation);
                }
                // MariaDB refuses to change a column a foreign key uses, even with the checks off: the
                // foreign keys (`<c>_search`/`_metadata` to `<c>`) are dropped, the tables converted,
                // and the same foreign keys made again.
                $keys = $connection->fetchAllNumeric(
                    'select table_name, constraint_name, referenced_table_name from information_schema.referential_constraints where constraint_schema = database()',
                );
                foreach ($keys as [$table, $name]) {
                    $connection->executeStatement('alter table ' . $connection->quoteSingleIdentifier((string) $table) . ' drop foreign key ' . $connection->quoteSingleIdentifier((string) $name));
                }
                foreach (\Idno\Upgrade\LegacyDataCopier::LEGACY_TABLES as $table) {
                    $connection->executeStatement('alter table ' . $connection->quoteSingleIdentifier($table) . ' convert to character set utf8mb4 collate ' . $collation);
                }
                foreach ($keys as [$table, $name, $referenced]) {
                    $connection->executeStatement(
                        'alter table ' . $connection->quoteSingleIdentifier((string) $table) . ' add constraint ' . $connection->quoteSingleIdentifier((string) $name)
                        . ' foreign key (`_id`) references ' . $connection->quoteSingleIdentifier((string) $referenced) . ' (`_id`) on delete cascade on update cascade',
                    );
                }
            }
        } catch (\Throwable $e) {
            PrivateDatabase::drop($connection);

            throw $e;
        }

        return new self($connection);
    }

    /**
     * Runs the schema migrations and creates the indexes the entity classes under Idno/ declare, as
     * the upgrade prepares the database before it copies the 1.x tables.
     */
    public function prepareNewSchema(): void
    {
        (new SchemaMigrator($this->connection))->migrate();
        $catalog = new SchemaCatalog($this->connection);
        (new IndexedFields($this->connection, new MySqlDialect(), $catalog))->reconcileDirectory(dirname(__DIR__, 2) . '/Idno');
    }

    public function drop(): void
    {
        PrivateDatabase::drop($this->connection);
    }

    /**
     * Writes a record to the 1.x collection laid out as saveRecord() did: _id, uuid and owner filled
     * in when empty, the document JSON-encoded as it was then, the search row (RecordIndex's text),
     * and a metadata row per value (arrays one per element, nested arrays JSON-encoded, empty values
     * 0, cut to 255 bytes).
     *
     * @param array<int|string, mixed> $document
     * @param array<string, string>    $columns     values for the main row's columns instead of the
     *                                              ones derived from the document (uuid, _id, siteid,
     *                                              owner, entity_subtype, created as a datetime,
     *                                              publish_status)
     * @param string|null              $rawContents the bytes of `contents` instead of the encoded document
     *
     * @return array{uuid: string, _id: string, contents: string} what the main row holds
     */
    public function insert(string $collection, array $document, array $columns = [], ?string $rawContents = null, bool $withSearchRow = true): array
    {
        if (empty($document['_id'])) {
            $document['_id'] = sprintf('%08x%016x', 0x1d2026, ++$this->sequence) . bin2hex(random_bytes(4));
        }
        if (empty($document['uuid'])) {
            $document['uuid'] = self::SITE_URL . 'view/' . $document['_id'];
        }
        if (empty($document['owner'])) {
            $document['owner'] = '';
        }
        $contents = $rawContents ?? json_encode($document, JSON_THROW_ON_ERROR);
        $search = RecordIndex::searchText($document, static fn (string $text): string => $text);

        $row = [
            'uuid' => (string) $document['uuid'],
            '_id' => (string) $document['_id'],
            'siteid' => isset($document['siteid']) ? (string) $document['siteid'] : null,
            'owner' => (string) $document['owner'],
            'entity_subtype' => empty($document['entity_subtype']) ? 'Idno\\Common\\Entity' : (string) $document['entity_subtype'],
            'contents' => $contents,
            'publish_status' => empty($document['publish_status']) ? 'published' : (string) $document['publish_status'],
            'created' => date('Y-m-d H:i:s', empty($document['created']) || !is_numeric($document['created']) ? time() : (int) $document['created']),
        ];
        foreach ($columns as $column => $value) {
            if (!array_key_exists($column, $row) || $column === 'contents') {
                throw new \InvalidArgumentException('No column ' . $column . ' to set.');
            }
            $row[$column] = $value;
        }

        $table = $this->connection->quoteSingleIdentifier($collection);
        $this->connection->insert($table, array_combine(
            array_map($this->connection->quoteSingleIdentifier(...), array_keys($row)),
            array_values($row),
        ));
        if ($withSearchRow) {
            $this->connection->executeStatement(
                'insert into ' . $this->connection->quoteSingleIdentifier($collection . '_search') . ' (`_id`, `search`) values (?, ?) on duplicate key update `search` = values(`search`)',
                [$row['_id'], $search],
            );
        }
        $this->connection->executeStatement('delete from ' . $this->connection->quoteSingleIdentifier($collection . '_metadata') . ' where `_id` = ?', [$row['_id']]);
        foreach ($document as $key => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }
                if (empty($value)) {
                    $value = 0;
                }
                $value = (string) $value;
                if (strlen($value) > 255) {
                    $value = mb_strcut($value, 0, 255, 'UTF-8');
                }
                $this->connection->executeStatement(
                    'insert into ' . $this->connection->quoteSingleIdentifier($collection . '_metadata') . ' set `_id` = ?, `name` = ?, `value` = ?',
                    [$row['_id'], (string) $key, $value],
                );
            }
        }

        return ['uuid' => $row['uuid'], '_id' => $row['_id'], 'contents' => $contents];
    }

    /**
     * Writes what a 1.x site needs for the legacy application to boot on it and serve pages once it
     * is upgraded, at the site URL $url (`http://host:port/`): the site's configuration (its title,
     * URL and plugins), its `site` record (the host as `domain`), an admin user (`handle`) and one
     * public status update by that user whose body is `body`, each with `created` and `updated` as a
     * 1.x save wrote them. Its records' uuids are under $url.
     *
     * @return array{user: string, handle: string, post: string, body: string} the user's and the
     *                                                                         post's _id, the
     *                                                                         handle, the body
     */
    public function insertSite(string $url, string $handle = 'upgradeduser', array $plugins = ['Status', 'Text']): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $created = 1_700_000_000;
        $this->insert('config', [
            'title' => 'A site from Idno 1.x',
            'url' => $url,
            'description' => 'Upgraded in place',
            'plugins' => $plugins,
            'open_registration' => false,
            'created' => $created,
        ]);
        $this->insert('site', [
            'entity_subtype' => 'Idno\\Core\\Site',
            'domain' => $host,
            'access' => 'PUBLIC',
            'created' => $created,
            'updated' => $created,
        ]);
        $userId = 'u' . bin2hex(random_bytes(8));
        $this->insert('entities', [
            '_id' => $userId,
            'uuid' => $url . 'view/' . $userId,
            'entity_subtype' => 'Idno\\Entities\\User',
            'handle' => $handle,
            'email' => $handle . '@example.com',
            'password' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
            'title' => 'Upgraded User',
            'admin' => true,
            'access' => 'PUBLIC',
            'publish_status' => 'published',
            'created' => $created + 1,
            'updated' => $created + 1,
        ]);
        $postId = 'p' . bin2hex(random_bytes(8));
        $body = 'A status update written on Idno 1.x, still here after the upgrade.';
        $this->insert('entities', [
            '_id' => $postId,
            'uuid' => $url . 'view/' . $postId,
            'entity_subtype' => 'IdnoPlugins\\Status\\Status',
            'owner' => $url . 'view/' . $userId,
            'body' => $body,
            'access' => 'PUBLIC',
            'publish_status' => 'published',
            'slug' => 'a-status-update-from-1x',
            'created' => $created + 2,
            'updated' => $created + 2,
        ]);

        return ['user' => $userId, 'handle' => $handle, 'post' => $postId, 'body' => $body];
    }

    /**
     * Writes $count small records quickly (multi-row inserts of the main and search rows, no
     * metadata, which the copy never reads): record $i (from 1) has _id `many<i, five digits>`,
     * uuid manyUuid($i), and a title.
     *
     * @return list<string> their _ids
     */
    public function insertMany(string $collection, int $count): array
    {
        $ids = [];
        $table = $this->connection->quoteSingleIdentifier($collection);
        $search = $this->connection->quoteSingleIdentifier($collection . '_search');
        for ($start = 1; $start <= $count; $start += 200) {
            $main = [];
            $searches = [];
            for ($i = $start; $i < $start + 200 && $i <= $count; ++$i) {
                $id = sprintf('many%05d', $i);
                $ids[] = $id;
                $document = ['title' => 'Record ' . $i, 'created' => 1_700_000_000 + $i, '_id' => $id, 'uuid' => self::manyUuid($i), 'owner' => ''];
                array_push($main, self::manyUuid($i), $id, 'IdnoPlugins\\Status\\Status', json_encode($document, JSON_THROW_ON_ERROR), date('Y-m-d H:i:s', 1_700_000_000 + $i));
                array_push($searches, $id, 'record ' . $i . ' ');
            }
            $rows = intdiv(count($main), 5);
            $this->connection->executeStatement(
                'insert into ' . $table . ' (`uuid`, `_id`, `owner`, `entity_subtype`, `contents`, `created`) values ' . implode(', ', array_fill(0, $rows, "(?, ?, '', ?, ?, ?)")),
                $main,
            );
            $this->connection->executeStatement('insert into ' . $search . ' (`_id`, `search`) values ' . implode(', ', array_fill(0, $rows, '(?, ?)')), $searches);
        }

        return $ids;
    }

    /**
     * The uuid of record $i of insertMany(): they sort as their numbers do.
     */
    public static function manyUuid(int $i): string
    {
        return self::SITE_URL . 'view/' . sprintf('%05d', $i);
    }

    /**
     * The SQL statements of a fixture file: comments dropped, split at each semicolon that ends a line.
     *
     * @return list<string>
     */
    private static function statements(string $file): array
    {
        $sql = (string) file_get_contents($file);
        $lines = array_filter(explode("\n", $sql), static fn (string $line): bool => !str_starts_with(ltrim($line), '--'));
        $statements = [];
        foreach (preg_split('/;\s*$/m', implode("\n", $lines)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }

        return $statements;
    }
}
