<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\DialectFactory;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SettingsRecordStore;
use Idno\Storage\TypeMap;

/**
 * Builds the container's RecordStore at runtime, when it is first fetched: a SettingsRecordStore
 * (the collection `config` in `idno_settings`) over a JsonRecordStore (every other collection in
 * its table `idno_<collection>`), both on the `database_connection` service, with the dialect of
 * the site's database (DialectFactory, from the boot configuration: MySqlDialect for MySQL and
 * MariaDB, SqliteDialect for SQLite), LegacyRecordDefaults, the container's TypeMap (the entities'
 * aliases) and SchemaCatalog (shared with IndexedFields, which tells it when it creates an index),
 * the legacy quote normaliser for the search text (Language::uncurlQuotes(), as the old backend's
 * saveRecord() applied it) and the site's `boolean_search` / `bypass_fulltext_search` settings,
 * read from the legacy config each time a search condition is built (as the legacy backend read
 * them per query).
 *
 * Any other database (a loadable backend class supplied by a plugin, or a site still configured for MongoDB, which the schema gate holds closed: see
 * ConnectionParameters::driverFamily(), which also makes a `database` name that is no loadable class
 * MySQL) has no record store: create() throws a \LogicException, without touching the connection or
 * the catalog. It needs the legacy application, so it must not be called before the legacy
 * application has booted.
 */
final class LegacyRecordStoreFactory
{
    /**
     * @param \Closure(): Connection    $connection the `database_connection` service, fetched only for an SQL site
     * @param \Closure(): SchemaCatalog $catalog    the SchemaCatalog service, fetched only for an SQL site
     */
    public function __construct(
        private readonly \Closure $connection,
        private readonly \Closure $catalog,
        private readonly BootConfig $bootConfig,
        private readonly LegacyRecordDefaults $defaults,
        private readonly TypeMap $types,
    )
    {
    }

    public function create(): RecordStore
    {
        $family = ConnectionParameters::driverFamily($this->bootConfig->all());
        if ($family !== 'mysql' && $family !== 'sqlite') {
            $database = $this->bootConfig->get('database');
            throw new \LogicException(sprintf(
                'The site\'s database ("%s") has no record store: only MySQL, MariaDB and SQLite sites have one.',
                is_scalar($database) ? (string) $database : get_debug_type($database),
            ));
        }
        $site = Idno::site();
        if (!$site instanceof Idno) {
            throw new \LogicException('The record store needs the legacy application, which has not booted yet (a kernel built with bootLegacy: false?).');
        }

        $connection = ($this->connection)();
        // The one mapping from the database to its dialect (the container's Dialect is built by it too).
        $dialect = (new DialectFactory($this->bootConfig))->create();
        $documents = new JsonRecordStore(
            $connection,
            $dialect,
            $this->defaults,
            $this->types,
            ($this->catalog)(),
            static fn (string $text): string => (string) Idno::site()->language()->uncurlQuotes($text),
            // Read when each search runs, as the legacy backend read them (after the stored config is
            // merged, and whatever a plugin changes at runtime).
            static fn (): bool => (bool) Idno::site()->config()->boolean_search,
            static fn (): bool => (bool) Idno::site()->config()->bypass_fulltext_search,
        );

        return new SettingsRecordStore($documents, $connection, $dialect, $this->defaults);
    }
}
