<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordStore;

/**
 * Builds the container's RecordStore at runtime, when it is first fetched: a DbalRecordStore on the
 * `database_connection` service, with the dialect of the site's database (from the boot
 * configuration: MySqlDialect for MySQL, SqliteDialect on the same connection for SQLite),
 * LegacyRecordDefaults, the legacy quote normaliser for the search text (Language::uncurlQuotes(),
 * as MySQL::saveRecord() applies it) and the site's `boolean_search` / `bypass_fulltext_search`
 * settings as they are when the store is built.
 *
 * Any other database (MongoDB, a backend supplied by a plugin) has no record store: create() throws
 * a \LogicException, without touching the connection. It needs the legacy application, so it must
 * not be called before the legacy application has booted.
 */
final class LegacyRecordStoreFactory
{
    /**
     * @param \Closure(): Connection $connection the `database_connection` service, fetched only for an SQL site
     */
    public function __construct(
        private readonly \Closure $connection,
        private readonly BootConfig $bootConfig,
        private readonly LegacyRecordDefaults $defaults,
    )
    {
    }

    public function create(): RecordStore
    {
        $family = ConnectionParameters::driverFamily($this->bootConfig->all());
        if ($family !== 'mysql' && $family !== 'sqlite') {
            $database = $this->bootConfig->get('database');
            throw new \LogicException(sprintf(
                'The site\'s database ("%s") has no record store: only MySQL and SQLite sites have one.',
                is_scalar($database) ? (string) $database : get_debug_type($database),
            ));
        }
        $site = Idno::site();
        if (!$site instanceof Idno) {
            throw new \LogicException('The record store needs the legacy application, which has not booted yet (a kernel built with bootLegacy: false?).');
        }

        $connection = ($this->connection)();
        $dialect = $family === 'mysql' ? new MySqlDialect() : new SqliteDialect($connection);
        $config = $site->config();

        return new DbalRecordStore(
            $connection,
            $dialect,
            $this->defaults,
            static fn (string $text): string => (string) Idno::site()->language()->uncurlQuotes($text),
            (bool) $config->boolean_search,
            (bool) $config->bypass_fulltext_search,
        );
    }
}
