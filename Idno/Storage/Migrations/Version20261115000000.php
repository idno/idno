<?php

declare(strict_types=1);

namespace Idno\Storage\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline schema: one table per collection (`idno_entities`, `idno_reader`, `idno_site`)
 * holding each document as JSON text with the columns queries use, the settings as one row per
 * key (`idno_settings`), PHP sessions (`idno_sessions`), and the bookkeeping of the upgrade from
 * the 1.x tables (`idno_upgrade_progress`, `idno_upgrade_rejects`).
 *
 * Every entity query filters on `access` (who may read the record), so the collection tables index
 * it themselves, with the type: on MySQL and MariaDB a STORED generated column `ix_access` (255
 * characters, as `uuid` and `owner`: a record with a longer access is refused) and the index
 * `ix_access (type, ix_access)`, on SQLite the index `idno_<collection>_ix_access` on the type and
 * the same value. They are named as the column and index of a declared indexed field are (see
 * Idno\Storage\IndexedFields), so the schema catalog lists `access` as indexed, queries compare the
 * indexed value, and a class that declares `access` adds no second index; a count by types and
 * access is answered from the index alone. The type comes first: with `access` first, MariaDB 10.6
 * chooses this index and a filesort for a listing page beyond the first few dozen, where reading
 * `created` backwards is a hundred times faster.
 *
 * The SQL is written out per platform (none of it comes from the dialect classes, which may
 * change) and must never change once released: a migration is a snapshot of the schema at its
 * version, and later changes are new migrations. (Before the first 2.x release it was edited in
 * place once, to add `ix_access`.) On SQLite the schema uses only built-in
 * collations, so the database file opens with standard SQLite tools.
 *
 * Every statement is safe to repeat (`IF NOT EXISTS`): on MySQL and MariaDB the migration is not
 * transactional, so a run interrupted partway (a killed request, a refused statement) leaves some
 * tables and no recorded version, and the next run must complete the schema rather than fail.
 */
final class Version20261115000000 extends AbstractMigration
{
    /**
     * The value the index on `access` holds on SQLite: the same rule as mySqlAccessValue(), with
     * built-in functions only.
     */
    private const SQLITE_ACCESS_VALUE = '(case json_type("data", \'$."access"\')'
        . ' when \'text\' then (case when json_extract("data", \'$."access"\') = \'\' then \'0\' else json_extract("data", \'$."access"\') end)'
        . ' when \'true\' then \'1\' when \'false\' then \'0\' when \'null\' then \'0\''
        . ' when \'integer\' then (case when json_extract("data", \'$."access"\') = 0 then \'0\' else cast(json_extract("data", \'$."access"\') as text) end)'
        . ' when \'real\' then (case when json_extract("data", \'$."access"\') = 0 then \'0\' else ("data" -> \'$."access"\') end)'
        . ' end)';

    public function getDescription(): string
    {
        return 'The baseline schema: collections as JSON documents, settings, sessions and the upgrade bookkeeping.';
    }

    /**
     * MySQL and MariaDB commit implicitly on DDL, so the migration runs outside a transaction there.
     */
    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }
        $this->abortIf(!$platform instanceof SQLitePlatform, 'The schema supports MySQL, MariaDB and SQLite only.');
        $this->upSqlite();
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The baseline schema cannot be removed by a migration.');
    }

    private function upMySql(): void
    {
        $access = $this->mySqlAccessValue();
        $this->addSql(strtr(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_entities (
              id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              type VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              owner VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              created BIGINT NOT NULL,
              publish_status VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              search LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              ix_access VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci GENERATED ALWAYS AS ({access}) STORED,
              PRIMARY KEY (id),
              UNIQUE KEY uuid (uuid),
              KEY type_created (type, created),
              KEY owner_created (owner, created),
              KEY created (created),
              KEY ix_access (type, ix_access),
              FULLTEXT KEY search (search)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL, ['{access}' => $access]));
        $this->addSql(strtr(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_reader (
              id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              type VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              owner VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              created BIGINT NOT NULL,
              publish_status VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              search LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              ix_access VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci GENERATED ALWAYS AS ({access}) STORED,
              PRIMARY KEY (id),
              UNIQUE KEY uuid (uuid),
              KEY type_created (type, created),
              KEY owner_created (owner, created),
              KEY created (created),
              KEY ix_access (type, ix_access),
              FULLTEXT KEY search (search)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL, ['{access}' => $access]));
        $this->addSql(strtr(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_site (
              id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              type VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              owner VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              created BIGINT NOT NULL,
              publish_status VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              search LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              ix_access VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci GENERATED ALWAYS AS ({access}) STORED,
              PRIMARY KEY (id),
              UNIQUE KEY uuid (uuid),
              KEY type_created (type, created),
              KEY owner_created (owner, created),
              KEY created (created),
              KEY ix_access (type, ix_access),
              FULLTEXT KEY search (search)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL, ['{access}' => $access]));
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_settings (
              name VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              position INT NOT NULL,
              PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_sessions (
              session_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              session_value MEDIUMBLOB NOT NULL,
              session_lifetime INT NOT NULL,
              session_time INT NOT NULL,
              PRIMARY KEY (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_upgrade_progress (
              collection VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              source_rows BIGINT NOT NULL,
              source_existed SMALLINT NOT NULL,
              copied BIGINT NOT NULL,
              rejected BIGINT NOT NULL,
              position VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
              verify_cursor VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
              error TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
              error_detail TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
              completed_at BIGINT NULL,
              PRIMARY KEY (collection)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_upgrade_rejects (
              n BIGINT NOT NULL AUTO_INCREMENT,
              collection VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              legacy_uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              legacy_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              reason VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              contents LONGBLOB NOT NULL,
              PRIMARY KEY (n),
              KEY collection_uuid (collection, legacy_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
    }

    /**
     * The value of `ix_access` on MySQL and MariaDB: the `access` of the document as queries compare
     * it (a string itself, '0' for an empty one, for false, null and zero, '1' for true, a number's
     * text), NULL when the document has none or holds a list or an object there. A string is
     * unquoted with JSON_UNQUOTE() on MySQL and with JSON_VALUE() on MariaDB, whose JSON_UNQUOTE()
     * returns utf8mb3 (and MySQL's JSON_VALUE() a VARCHAR(512)).
     */
    private function mySqlAccessValue(): string
    {
        $json = 'json_extract(`data`, \'$."access"\')';
        $text = $this->connection->getDatabasePlatform() instanceof MariaDBPlatform
            ? 'if(json_type(' . $json . ') = \'STRING\', json_value(`data`, \'$."access"\'), json_unquote(' . $json . '))'
            : 'json_unquote(' . $json . ')';

        return 'case when ' . $json . ' is null then null else case coalesce(json_type(' . $json . '), \'NULL\')'
            . ' when \'STRING\' then if(char_length(' . $text . ') = 0, \'0\', ' . $text . ')'
            . ' when \'BOOLEAN\' then if(' . $text . ' = \'true\', \'1\', \'0\')'
            . ' when \'NULL\' then \'0\''
            . ' when \'ARRAY\' then null when \'OBJECT\' then null'
            . ' else if(' . $text . ' + 0 = 0, \'0\', ' . $text . ') end end';
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_entities (
              id TEXT NOT NULL COLLATE NOCASE,
              uuid TEXT NOT NULL COLLATE NOCASE,
              type TEXT NOT NULL COLLATE NOCASE,
              owner TEXT NOT NULL COLLATE NOCASE,
              created INTEGER NOT NULL,
              publish_status TEXT NOT NULL COLLATE NOCASE,
              data TEXT NOT NULL,
              search TEXT NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS idno_entities_uuid ON idno_entities (uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_entities_type_created ON idno_entities (type, created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_entities_owner_created ON idno_entities (owner, created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_entities_created ON idno_entities (created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_entities_ix_access ON idno_entities (type, ' . self::SQLITE_ACCESS_VALUE . ' COLLATE NOCASE)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_reader (
              id TEXT NOT NULL COLLATE NOCASE,
              uuid TEXT NOT NULL COLLATE NOCASE,
              type TEXT NOT NULL COLLATE NOCASE,
              owner TEXT NOT NULL COLLATE NOCASE,
              created INTEGER NOT NULL,
              publish_status TEXT NOT NULL COLLATE NOCASE,
              data TEXT NOT NULL,
              search TEXT NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS idno_reader_uuid ON idno_reader (uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_reader_type_created ON idno_reader (type, created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_reader_owner_created ON idno_reader (owner, created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_reader_created ON idno_reader (created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_reader_ix_access ON idno_reader (type, ' . self::SQLITE_ACCESS_VALUE . ' COLLATE NOCASE)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_site (
              id TEXT NOT NULL COLLATE NOCASE,
              uuid TEXT NOT NULL COLLATE NOCASE,
              type TEXT NOT NULL COLLATE NOCASE,
              owner TEXT NOT NULL COLLATE NOCASE,
              created INTEGER NOT NULL,
              publish_status TEXT NOT NULL COLLATE NOCASE,
              data TEXT NOT NULL,
              search TEXT NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS idno_site_uuid ON idno_site (uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_site_type_created ON idno_site (type, created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_site_owner_created ON idno_site (owner, created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_site_created ON idno_site (created)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_site_ix_access ON idno_site (type, ' . self::SQLITE_ACCESS_VALUE . ' COLLATE NOCASE)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_settings (
              name TEXT NOT NULL,
              value TEXT NOT NULL,
              position INTEGER NOT NULL,
              PRIMARY KEY (name)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_sessions (
              session_id varchar(255) NOT NULL,
              session_value mediumblob NOT NULL,
              session_lifetime int(11) NOT NULL,
              session_time int(11) NOT NULL,
              PRIMARY KEY (session_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_upgrade_progress (
              collection TEXT NOT NULL,
              source_rows INTEGER NOT NULL,
              source_existed INTEGER NOT NULL,
              copied INTEGER NOT NULL,
              rejected INTEGER NOT NULL,
              position TEXT NULL,
              verify_cursor TEXT NULL,
              error TEXT NULL,
              error_detail TEXT NULL,
              completed_at INTEGER NULL,
              PRIMARY KEY (collection)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_upgrade_rejects (
              n INTEGER PRIMARY KEY AUTOINCREMENT,
              collection TEXT NOT NULL,
              legacy_uuid TEXT NOT NULL,
              legacy_id TEXT NOT NULL,
              reason TEXT NOT NULL,
              contents BLOB NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_upgrade_rejects_collection_uuid ON idno_upgrade_rejects (collection, legacy_uuid)');
    }
}
