<?php

declare(strict_types=1);

namespace Idno\Storage\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline schema: one table per collection (`idno_entities`, `idno_reader`, `idno_site`)
 * holding each document as JSON text with the columns queries use, the settings as one row per
 * key (`idno_settings`), PHP sessions (`idno_sessions`), and the bookkeeping of the upgrade from
 * the 1.x tables (`idno_upgrade_progress`, `idno_upgrade_rejects`).
 *
 * The SQL is written out per platform and must never change: a migration is a snapshot of the
 * schema at its version. Later changes are new migrations. On SQLite the schema uses only built-in
 * collations, so the database file opens with standard SQLite tools.
 *
 * Every statement is safe to repeat (`IF NOT EXISTS`): on MySQL and MariaDB the migration is not
 * transactional, so a run interrupted partway (a killed request, a refused statement) leaves some
 * tables and no recorded version, and the next run must complete the schema rather than fail.
 */
final class Version20261115000000 extends AbstractMigration
{
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
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_entities (
              id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              type VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              owner VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              created BIGINT NOT NULL,
              publish_status VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              search LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uuid (uuid),
              KEY type_created (type, created),
              KEY owner_created (owner, created),
              KEY created (created),
              FULLTEXT KEY search (search)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_reader (
              id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              type VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              owner VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              created BIGINT NOT NULL,
              publish_status VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              search LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uuid (uuid),
              KEY type_created (type, created),
              KEY owner_created (owner, created),
              KEY created (created),
              FULLTEXT KEY search (search)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_site (
              id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              uuid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              type VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              owner VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              created BIGINT NOT NULL,
              publish_status VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              data LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              search LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uuid (uuid),
              KEY type_created (type, created),
              KEY owner_created (owner, created),
              KEY created (created),
              FULLTEXT KEY search (search)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
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
              copied BIGINT NOT NULL,
              rejected BIGINT NOT NULL,
              position VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
              error TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NULL,
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
              PRIMARY KEY (n)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
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
              copied INTEGER NOT NULL,
              rejected INTEGER NOT NULL,
              position TEXT NULL,
              error TEXT NULL,
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
    }
}
