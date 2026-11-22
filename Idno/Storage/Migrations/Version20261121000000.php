<?php

declare(strict_types=1);

namespace Idno\Storage\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The table of the queue of events (`idno_messages`), the one Symfony Messenger's Doctrine
 * transport reads and writes: the columns, their types and the index are those the installed
 * symfony/doctrine-messenger declares (Connection::configureSchema()), written out as literal SQL
 * per platform. The site creates its schema only through migrations, so the transport is
 * configured with auto_setup off and never issues DDL.
 *
 * `id` is the auto-increment key; `body` and `headers` hold the serialised message and its
 * headers; `queue_name` names the queue (`idno` and `failed`); `created_at`, `available_at` and
 * `delivered_at` are datetimes without a fraction, in UTC, written and compared by the transport
 * itself. The index is the one the transport's own query (the oldest available message of a queue)
 * uses.
 *
 * Every statement is safe to repeat (`IF NOT EXISTS`), because on MySQL and MariaDB DDL is not
 * transactional and an interrupted run must be completed by the next one.
 */
final class Version20261121000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The queue of events: the table Messenger\'s Doctrine transport uses.';
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
            $this->addSql(<<<'SQL'
                CREATE TABLE IF NOT EXISTS idno_messages (
                  id BIGINT NOT NULL AUTO_INCREMENT,
                  body LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                  headers LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                  queue_name VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
                  created_at DATETIME NOT NULL,
                  available_at DATETIME NOT NULL,
                  delivered_at DATETIME DEFAULT NULL,
                  PRIMARY KEY (id),
                  KEY queue_available (queue_name, available_at, delivered_at, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
                SQL);

            return;
        }
        $this->abortIf(!$platform instanceof SQLitePlatform, 'The schema supports MySQL, MariaDB and SQLite only.');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS idno_messages (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              body CLOB NOT NULL,
              headers CLOB NOT NULL,
              queue_name VARCHAR(190) COLLATE NOCASE NOT NULL,
              created_at DATETIME NOT NULL,
              available_at DATETIME NOT NULL,
              delivered_at DATETIME DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idno_messages_queue_available ON idno_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The queue of events is not removed by a migration.');
    }
}
