<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use Idno\Legacy\LegacySqlMaintenance;
use PHPUnit\Framework\TestCase;

/**
 * The statements of the legacy daily "optimise" (LegacySqlMaintenance::optimize()): on MySQL and
 * MariaDB `analyze table` on each table of the schema (statistics; never `optimize table`, which
 * rebuilds an InnoDB table and holds up writes), on SQLite `pragma optimize`; always false, as the
 * old backend returned. A PDO that records what it is sent stands in for the server.
 */
final class LegacySqlMaintenanceTest extends TestCase
{
    public function testOptimizeRefreshesStatisticsAndRebuildsNothing(): void
    {
        foreach (['mysql' => array_map(static fn (string $table): string => 'analyze table ' . $table, LegacySqlMaintenance::TABLES), 'sqlite' => ['pragma optimize']] as $driver => $expected) {
            $client = self::recordingPdo($driver);

            self::assertFalse((new LegacySqlMaintenance())->optimize($client), $driver);
            self::assertSame($expected, $client->sent, $driver);
            foreach ($client->sent as $statement) {
                self::assertStringNotContainsStringIgnoringCase('optimize table', $statement);
            }
        }
        self::assertSame(['idno_entities', 'idno_reader', 'idno_site', 'idno_settings', 'idno_sessions'], LegacySqlMaintenance::TABLES);
    }

    /**
     * An in-memory SQLite PDO that reports $driver as its driver and records each query() (answering
     * each with `select 1`).
     */
    private static function recordingPdo(string $driver): \PDO
    {
        return new class ($driver) extends \PDO {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(private readonly string $driver)
            {
                parent::__construct('sqlite::memory:');
            }

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === \PDO::ATTR_DRIVER_NAME ? $this->driver : parent::getAttribute($attribute);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->sent[] = $query;

                return parent::query('select 1');
            }
        };
    }
}
