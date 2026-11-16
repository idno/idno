<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Connection;
use Idno\Storage\Platform\SqliteDialect;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * SqliteConnectionSetup runs on EVERY new physical connection: after close(), the next query
 * opens a new one, set up as the first was. On a database file (not `:memory:`, which a new
 * connection could not reopen) in a temporary directory the test creates and removes.
 */
final class SqliteConnectionSetupTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/idno-sqlite-setup-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * Every setting SqliteConnectionSetup applies, as the connection reports it.
     *
     * @return array<string, string>
     */
    private static function settings(Connection $connection): array
    {
        return [
            'busy_timeout' => (string) $connection->fetchOne('pragma busy_timeout'),
            'journal_mode' => strtolower((string) $connection->fetchOne('pragma journal_mode')),
            'foreign_keys' => (string) $connection->fetchOne('pragma foreign_keys'),
        ];
    }

    public function testEveryNewConnectionIsSetUpAfterClose(): void
    {
        $expected = ['busy_timeout' => (string) SqliteDialect::BUSY_TIMEOUT_MILLISECONDS, 'journal_mode' => 'wal', 'foreign_keys' => '1'];
        $connection = PrivateDatabase::sqliteFile($this->dir . '/site.sqlite');

        $this->assertSame($expected, self::settings($connection));
        $first = $connection->getNativeConnection();

        $connection->close();
        $this->assertFalse($connection->isConnected());
        $this->assertSame(1, (int) $connection->fetchOne('select count(*) from idno_migrations'), 'the next query opens a new connection');

        $this->assertNotSame($first, $connection->getNativeConnection(), 'a new physical connection');
        $this->assertSame($expected, self::settings($connection));
        $connection->close();
    }
}
