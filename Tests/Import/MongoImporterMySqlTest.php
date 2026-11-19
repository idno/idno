<?php

declare(strict_types=1);

namespace Tests\Import;

use Doctrine\DBAL\Connection;
use Tests\Import\Contract\MongoImporterContract;
use Tests\Storage\PrivateDatabase;

/**
 * The importer's contract on a private MySQL or MariaDB database of the rig's server (dropped after
 * each test): the target the end-to-end test cannot reach where MongoDB runs. Skips on an SQLite
 * rig (PrivateDatabase::mysql() says why); the contract runs on SQLite in the unit tier.
 */
final class MongoImporterMySqlTest extends MongoImporterContract
{
    protected function target(): Connection
    {
        return PrivateDatabase::mysql('mongoimport', migrate: false);
    }

    protected function dropTarget(Connection $connection): void
    {
        PrivateDatabase::drop($connection);
    }

    protected function equatedUuids(): array
    {
        // utf8mb4_unicode_520_ci: case, and ß with ss.
        return [
            ['http://example.com/view/Abc', 'http://example.com/view/abc'],
            ['http://example.com/view/straße', 'http://example.com/view/strasse'],
        ];
    }

    protected function tooDeep(): int
    {
        // Beyond what the server's JSON functions accept (MySQL 8: 100 levels, MariaDB 10.6: 31), and
        // within what json_encode() writes: the store refuses it.
        return 120;
    }
}
