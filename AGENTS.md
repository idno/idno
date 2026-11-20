# Idno (Known)

Idno is an IndieWeb-first social publishing platform written in PHP. Users publish
notes, articles, photos, events, bookmarks, check-ins, and media on their own
domain and syndicate to social networks. It federates with the Fediverse via
ActivityPub.

## A note about the name

This system was originally called Idno. For a startup in 2014-2026, it was called Known. You
may find references to both in the codebase, but any new code should refer to the
system as Idno.

## Build & Test

```bash
# Install dependencies
composer install

# Local test rig: a database + php -S web server (http://127.0.0.1:8089/)
composer test:up        # start the rig on SQLite: needs nothing but PHP (the default); creates the schema
composer test:up:mysql  # start the rig on MySQL 8 in Docker (127.0.0.1:33306)
IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql  # the same, on MariaDB 10.6 (any MySQL or MariaDB image)
composer test           # run the full suite (core + plugins + unit tier); needs the rig, either mode
composer test:down      # stop the rig, whichever mode, and delete its database

# The dependency-free unit tier (Tests/Unit/): needs no rig, no database server, no configuration
composer test:unit

# The MongoDB import's end-to-end test (Tests/Import) against a real MongoDB, in Docker (needs nothing
# but Docker; see "Importing a MongoDB site")
composer test:mongo-import

# Run one directory or file (a plugin's tests, the kernel tests, ...)
composer test -- IdnoPlugins/ActivityPub/Tests/
composer test -- Tests/Kernel

# Coding standard and PHP syntax check
vendor/bin/phpcs -n
php -l path/to/File.php

# The command line (Symfony's commands and the legacy console plugins), against the test rig
composer console -- list
composer console -- idno:upgrade   # create or upgrade the database (the rig runs it on test:up)
composer console -- idno:upgrade --drop-legacy   # list (and with --yes delete) a completed upgrade's legacy_ tables
composer console -- idno:import:mongo --target=sqlite:///data/new.sqlite   # move a MongoDB site into a new, empty database
```

- `composer test` is `IDNO_DOMAIN=127.0.0.1 IDNO_PORT=8089 php -d error_reporting=22527 vendor/bin/phpunit`:
  the rig's address, and PHP deprecations excluded (on PHP 8.5 legacy code emits them, and the rig's
  `pedantic_mode` turns them into exceptions). Do not run bare `vendor/bin/phpunit` locally. CI
  (`.github/workflows/tests.yml`) does run bare `vendor/bin/phpunit`, because its php.ini already
  excludes deprecations.
- Every Composer script that runs PHPUnit or the rig (`test`, `test:unit`, `test:up`,
  `test:up:mysql`, `test:down`) is a list whose first entry is `Composer\Config::disableProcessTimeout`:
  Composer's default 300-second process timeout would kill the whole suite on MySQL (about four
  minutes). Arguments after `--` still reach the command. Keep that entry when you change one.
  Rough times: the unit tier a few seconds; the whole suite about a minute on SQLite, two to four
  minutes on MySQL or MariaDB.
- Likewise run the console as `composer console -- <command>` against the rig; on a site without
  `pedantic_mode` it is `php bin/console <command>` (`idno.php` and `known.php` are aliases). See
  "Console commands" below.
- PHPUnit config: `phpunit.xml` (bootstrap: `Tests/_bootstrap.php`; it sets `memory_limit` to 256M, because the
  whole suite runs in one process and peaked at 124 MB of PHP's default 128 MB on MySQL) for `composer test`;
  `phpunit.unit.xml` (bootstrap: `Tests/_bootstrap_unit.php`, one suite `Unit` covering `Tests/Unit/`)
  for `composer test:unit`
- Test tiers. **Unit** (`Tests/Unit/`, `composer test:unit`): runs with nothing but PHP and Composer's
  autoloader (and the PHP extensions `composer.json` requires, `pdo_sqlite` included).
  `Tests/_bootstrap_unit.php` loads only `vendor/autoload.php`: a unit test must not use
  `Idno::site()`, boot the kernel, read `configuration/` or the environment's site settings,
  connect to a database SERVER, open a database FILE outside a temporary directory it creates and
  removes, touch the network, or need the web server. An in-memory SQLite database (`:memory:`) is
  allowed: the storage contract runs on one in the unit tier. It must not depend on which
  bootstrap ran: `Tests/Unit/` is also part of `composer test` (it falls under the `Idno Basic
  Unit Tests` suite's `Tests/` directory, once), where it runs after the full bootstrap.
  **Everything else** (`composer test`) needs the rig, in either mode (see below): kernel tests (`Tests/Kernel`),
  the storage contract bound to a private MySQL or MariaDB database of the rig's server
  (`Tests/Storage/JsonMySqlRecordStoreTest`: `JsonRecordStore` with `MySqlDialect`, which skips on
  SQLite; the contract itself, `Tests/Storage/Contract/RecordStoreContract`, is dependency-free and
  also runs against the in-memory store and against `JsonRecordStore` on in-memory SQLite in
  `Tests/Unit/Storage` (`InMemoryRecordStoreTest`, `JsonSqliteRecordStoreTest`); its expectations
  are the MySQL store's, and the hooks a binding overrides are only where the databases really
  differ: `expectLongSearchTermToMatchSubstring`, `expectBooleanModeSearch` (no full-text index in
  memory or on SQLite), `expectNonAsciiCaseToBeIgnored`, `expectAccentsToBeIgnored`,
  `expectExpansionsToBeEqual`, `expectTrailingSpacesToBeIgnored` and
  `expectTextToSortFoldedToUpperCase` (MySQL's `utf8mb4_unicode_520_ci` against the in-memory
  store's upper-case folding and SQLite's NOCASE),
  `expectCreatedToBeComparedInTheTimeZoneOfItsSave` (the in-memory store keeps `created` as text),
  `expectFloatsInExponentFormToMatchTheirJsonText` (MySQL 8's JSON functions); `SettingsRecordStore` has the same two bindings of one test case
  (`Tests/Storage/Contract/SettingsRecordStoreContract`: `Tests/Unit/Storage/SettingsRecordStoreTest`
  on an SQLite file in a temporary directory and `Tests/Storage/SettingsRecordStoreMySqlTest` on a private MySQL or MariaDB
  database); the
  SQLite tests that need a kernel or a database file, `SqliteConnectionTest` and
  `SqliteConcurrencyTest`, are in `Tests/Storage`; the entity query contract,
  `Tests/Storage/Contract/EntityQueryContract`, needs the rig and runs against
  `EntityRepository` in `EntityRepositoryTest` (MySQL and SQLite; both contracts were first proven
  against the old SQL backend, `Idno\Data\MySQL`, since removed, and pin its behaviour as
  literals, with the deliberate differences listed under the `db()` bullet); the storage services are tested in
  `StorageServicesTest`, and `EntityRepository::count()`'s criteria in the unit tier,
  `Tests/Unit/Storage/EntityRepositoryCriteriaTest`; the `db()` adapter in
  `Tests/Kernel/RepositoryDataConciergeTest`), the black-box characterisation suite
  (`Tests/Characterisation`, which drives the running site over HTTP) and the plugin tests.
- Tests bootstrap from environment variables `IDNO_DOMAIN` / `IDNO_PORT` (default `localhost`, 80)
- Tests require a running database and web server; `composer test:up` provides both (see `Tests/rig/` and `docs/developers/testing.md`)
- The rig has two modes, with the same web server (`php -S` on 127.0.0.1:8089, `Tests/rig/router.php`,
  `APP_ENV=test`, `APP_DEBUG=1`), the same `pedantic_mode` and the same plugins; only the database
  settings differ (`Tests/rig/config.sqlite.ini` and `Tests/rig/config.ini`, pinned by
  `Tests/Kernel/RigScriptsTest`). The web server runs four workers (`PHP_CLI_SERVER_WORKERS=4` in
  `up.sh`, as CI's servers run eight): the site makes requests to itself while it serves another (a
  webmention to a page of its own, an outbound request answered by a `/_test/` route), which would
  wait for ever with one worker. The workers are children of the server and outlive it, so `down.sh`
  stops them before it stops the server (`RigScriptsTest` pins the count and that they stop):
  - **SQLite** (`composer test:up`, the default): no Docker, nothing but PHP. The database file
    (`site.sqlite`, with its `-wal`/`-shm` files) and the upload directory (`uploads/`) are in
    `<sys_get_temp_dir()>/idno-test-rig-<first 12 hex digits of the SHA-256 of the checkout's real
    path>/`, so two checkouts (git worktrees) never share a database or uploads. `up.sh` creates it
    as an empty file (a re-run keeps the file there and its data). No step of this mode runs `docker`.
  - **MySQL** (`composer test:up:mysql`): MySQL 8 in Docker on 127.0.0.1:33306, database
    `known_unittest`, created empty by the image; the upload directory is in the same per-checkout
    temporary directory. Use it for anything that touches the SQL dialect, search (MySQL's full-text
    index), the schema migrations on MySQL or MySQL's refusals and error codes: the tests bound to
    those skip on SQLite and say why. Run the whole suite on MySQL (and on MariaDB, below) before
    merging such a change (CI runs SQLite, MySQL and MariaDB).
    `IDNO_RIG_MYSQL_IMAGE` selects the Docker image (default `mysql:8.0`; e.g. `mariadb:10.6`, whose
    image honours the `MYSQL_*` variables; the healthcheck uses `mysqladmin` or `mariadb-admin`,
    whichever the image has). The image the database was started from
    is recorded in the per-checkout temporary directory (`mysql-image`; none recorded means
    `mysql:8.0`): `test:up:mysql` with another image stops the running database first (`down.sh`),
    and the container is started with `--renew-anon-volumes`, so whenever compose recreates it (a new
    image, or any change to the service in `docker-compose.yml`) it starts on an empty data directory
    (the schema is created again, below), and the previous test data is gone; a re-run that needs no
    new container keeps its data. `down.sh` removes the container's data volume
    (`docker compose down -v`). The whole suite passes on MySQL 8.0 and MariaDB 10.6 alike.
  - In both modes, once the database is up and before the web server starts, `up.sh` creates the
    schema with `bin/console idno:upgrade` (the tables and the field indexes; see "Console
    commands"), in the rig's environment (`APP_ENV=test`, `APP_DEBUG=1`, deprecations excluded, as
    `composer console` runs it); on a database that has the schema it re-checks the core declared
    indexed fields and, last, those of the enabled plugins (creating any that are missing) and otherwise
    says there is nothing to do (the command then runs with the legacy application booted, as any command does
    with the schema gate open).
    No SQL file is loaded; `up.sh` has no special case for the gate.
  - Starting one mode while the other is running stops the other first (`down.sh`, which deletes its
    database). `composer test:down` stops whichever is running (it is safe when nothing is), deletes
    the per-checkout temporary directory (and, in MySQL mode, the container), and leaves
    `configuration/config.ini` as it is. It always completes that cleanup: when the container cannot
    be stopped (Docker absent or not running) it prints a warning and carries on. It signals only
    the rig's own `php -S` (the process in `Tests/rig/.server.pid` must be `php ... -S 127.0.0.1:<port>
    ... Tests/rig/router.php`; any other process is left alone and the stale pid file removed). The
    mode is recorded in `Tests/rig/.mode` (gitignored) only once its database is up, so a
    `test:up:mysql` that fails (Docker not running) leaves nothing a plain `test:up` must stop.
    `IDNO_RIG_PORT` overrides the web server's port for `RigScriptsTest` only (the rig's config says 8089).
  - `configuration/config.ini`: every config the rig writes starts with the marker line
    `; idno-test-rig: generated by the local test rig (Tests/rig/); composer test:up may replace this file.`
    (`Tests/rig/lib.sh`; a file starting with the rig's older first line,
    `; Idno configuration for the local test rig (see Tests/rig/up.sh).`, counts as the rig's too). The
    rig writes the file when there is none and replaces one that carries the marker (that is how the
    mode switches); it never touches one without the marker (a developer's own): `up.sh` exits 1 and
    says so, before starting or stopping anything.
  - On SQLite, these skip, each with its reason: the tests on private MySQL databases
    (`JsonMySqlRecordStoreTest`, `SettingsRecordStoreMySqlTest`, `IndexedFieldsMySqlTest`,
    `SchemaMigratorMySqlTest`, `InstallerSchemaTest`; their contracts run on SQLite in the unit
    tier; and `Tests/Upgrade/LegacyDataCopierTest`, `Tests/Upgrade/LegacyTablesTest` and every case of
    `Tests/Upgrade/UpgradeEndToEndTest` but the empty database's: no 1.x site runs on SQLite), the MySQL connection service's tests (`Tests/Storage/DatabaseConnectionTest`: nothing of
    it compiled into the container, lazy, the old backend's session, failures), and the `RepositoryDataConciergeTest` cases that need MySQL (`requireMysql()`: the old
    backend's MySQL connection, full-text settings, a lost MySQL connection, `database` names that
    mean MySQL). `EntityRepositoryTest` and `UpgradeCommandTest` run on both. Nothing else skips
    (the unreachable-database tests run on both: a closed port on MySQL, a database file in a
    missing directory on SQLite); the characterisation suite runs in full on both.
- Three test suites: `Idno Basic Unit Tests` (`Tests/`), `Characterisation` (`Tests/Characterisation/`), `Plugins`
- CI (`.github/workflows/tests.yml`, on PHP 8.4 and 8.5 each): `phpcs`; the unit tier as its own job
  (`composer test:unit`, no services); the SQLite job, which uses the rig exactly as a developer does
  (`composer test:up`, then `composer test`; no service containers, no Docker); and the MySQL,
  MariaDB 10.6 jobs, which do not use the rig: they copy `Tests/build/config_mysql.ini` to
  `configuration/config.ini`, create the schema with `php bin/console idno:upgrade` on the fresh, empty database, serve the site at
  `http://localhost/` with `php -S localhost:80` and `Tests/build/router.php`, and run bare
  `vendor/bin/phpunit`; and, on PHP 8.4 only, the `mongo-import` job (a `mongo:6` service and the
  SQLite rig: `composer test -- Tests/Import` with `IDNO_TEST_MONGO_URL`, the one test that needs a
  MongoDB server; then the same on a `mysql:8.0` service, set up as the MySQL job is, each run checked
  with `Tests/rig/mongo-import-check.php` that the end-to-end test ran). Tests must not assume a
  particular site URL (use the site's configured URL, e.g. `$this->site()->baseUrl()`), plugin list
  or stored content beyond what both the rig's and CI's configurations provide; derive expectations
  from the configuration or the filesystem. Both load the same plugins: keep `alwaysplugins[]` in
  `Tests/rig/config.ini`, `Tests/rig/config.sqlite.ini` and `Tests/build/config_mysql.ini` in step
  (`Tests/Kernel/RigScriptsTest` checks the rig's two and the CI copy, and that it is the only
  `Tests/build/config_*.ini`). Every test that touches the database runs on MySQL and SQLite (the
  storage-bound classes key their skips on `ConnectionParameters::driverFamily()`).

## Architecture

Idno is a legacy PHP application running inside a Symfony 8 kernel (`Idno\Kernel`). The
legacy application (its services, router, page classes and templates) still does almost
all of the work; it is being moved onto Symfony step by step, keeping the site working.

```
index.php                      HTTP front controller (every web request)
bin/console                    Console (CLI) entry point (refuses to run under a web server)
idno.php, known.php            Aliases for bin/console (backwards compatibility)
Idno/
  Kernel.php                   Idno\Kernel: the Symfony kernel (MicroKernelTrait)
  Kernel/                      Cache directory and code fingerprint for the compiled container; DoctrineTrimPass
  Http/                        FrontController (what index.php runs); Client/ (HttpClientFactory and
                               BoundedBody: the site's HTTP clients, see "Kernel and services")
  Console/                     Idno\Console\Application, the CLI application (see Entry points)
  Config/                      BootConfig, LegacyConfigLoader, SiteSettings (modern style, Symfony-wired)
  Legacy/                      Shims between the kernel and the legacy application (see below)
  start.php                    Boots the legacy application for code that includes it (tests, scripts)
  Common/                      Legacy base classes (Entity, Page, Plugin, ContentType, Theme)
  Core/                        Legacy singletons & services (Idno, Config, Session, PageHandler, Webmention, ...)
  Storage/                     The storage interface (modern style): the RecordStore interface; the query
                               model (Criteria and Criterion/*, value objects parsed from the legacy search
                               arrays); RecordIndex (the query values, search text and columns a store
                               derives from a record); RecordDefaults
                               (what a store fills in: _id, uuid, siteid); InMemoryRecordStore, the reference
                               implementation the storage contract also holds to MySQL's behaviour;
                               MySqlSemantics (the rules MySQL applies by itself, in PHP: `created`
                               literals, upper-case text folding, the in-memory store's search);
                               what is engine-specific in Platform/ (Dialect, MySqlDialect for MySQL and
                               MariaDB, SqliteDialect, and SqliteConnectionSetup, the DBAL middleware
                               that sets up every SQLite connection);
                               ConnectionParameters (legacy database settings -> DBAL parameters) and
                               BootConfigConnectionFactory (gives the DBAL connection those parameters at runtime);
                               EntityRepository (entities on a RecordStore, with the legacy access rules),
                               EntityQuery (its query) and AccessContext (who is asking; FixedAccessContext
                               for tests); SchemaMigrator (runs the schema migrations in Migrations/)
                               and SchemaCatalog (the tables and indexed fields that exist);
                               JsonRecordStore, the RecordStore on the tables the migrations create, with TypeMap (a class's value in the `type` column),
                               AttributeTypeMap (the aliases the entity classes declare with
                               Attribute/AsEntity) and IdentityTypeMap (no aliases); RecordIndex::queryValues() is what a
                               query on a document field compares with, in that store and in memory;
                               IndexedFields (creates the indexes entity classes declare with
                               Attribute/IndexedField), EntityClassFinder (the classes of a directory that
                               carry those attributes) and DialectFactory (the container's Dialect);
                               SettingsRecordStore, a RecordStore decorator that serves the collection
                               `config` (the site's configuration, ONE document) from the key/value table
                               `idno_settings` and every other collection from the store it decorates
                               (the container's RecordStore is one, over a JsonRecordStore);
                               Files/ holds the file store: FileStore (uploads on a Flysystem
                               filesystem, in the 1.x layout), StoredFile, FileStoreException and
                               FilesystemFactory (the filesystem from the boot configuration)
  Upgrade/                     The schema gate and the upgrade (modern style; runs while the legacy
                               application is NOT booted): SchemaState (what the database is: a
                               SchemaStatus), SchemaGate (whether the legacy application may boot),
                               ServerVersion (the MySQL/MariaDB floors), UpgradeRunner (one bounded step, or
                               a whole run), UpgradeGateListener (answers every request while the gate is
                               closed), UpgradeController (`POST /_upgrade/step`) and UpgradePage (the page);
                               PublicMessage (what may be shown to anyone), EnabledPlugins (which plugins
                               are enabled, read without the legacy application);
                               the copy of a 1.x database into the new schema: LegacyDataCopier (the
                               phases), its helpers LegacySource, RowWriter, ConfigCopier and DocumentCheck,
                               UpgradeLock (one process at a time) and UpgradeProgress (a value object);
                               LegacyTables (what a completed upgrade left: the legacy_ tables, the
                               rejects; deleting the legacy_ tables). See "The schema gate and the
                               upgrade", "Copying a 1.x database" and "The legacy tables";
                               Mongo/ is the import of a MongoDB site (MongoImporter, MongoSource and
                               ExtMongoSource on the mongodb extension, MongoDocument, ChunkStream,
                               ImportRecordDefaults): see "Importing a MongoDB site"
  Entities/                    Core entity types (User, BaseObject, File, AccessGroup, etc.)
  Pages/                       Built-in legacy page handlers (Account, Admin, Entity, Feed, etc.)
config/                        Kernel configuration: bundles.php, services.php, routes.php,
                               packages/ (framework, monolog, doctrine), routes/test/ (test-only routes),
                               services_test.php (test-only services)
var/                           The compiled container (var/cache/); must be writable by the web server
                               (or set IDNO_CACHE_DIR). config/, bin/, var/ and Tests/ each hold a
                               deny-all .htaccess; none of them may be served (see Code Style)
data/                          Where an SQLite site's database file lives (data/idno.sqlite): only its
                               deny-all .htaccess and readme.txt are tracked; never served (see Code Style)
templates/                     Legacy templates (.tpl.php), one directory per template type
IdnoPlugins/                   First-party content & feature plugins
ConsolePlugins/                CLI service plugins (EventQueueService, CronService, Export)
Themes/                        Visual themes; Twenty26 (Idno 2026) is the only bundled theme. A site
                               configured with a retired theme (Themes::RETIRED) is resolved to Twenty26
                               at load; the setting is saved, and the admin told, when an admin next
                               loads a page (Themes::announceRetiredTheme()); a theme pinned in
                               config.ini or the environment is never saved or announced, only resolved. Plugins and themes
                               are discovered only in IdnoPlugins/ and Themes/ under the project root
                               (no per-host directories)
Tests/                         Unit (Tests/Unit, dependency-free), kernel (Tests/Kernel), storage (Tests/Storage,
                               with the contracts in Tests/Storage/Contract), upgrade (Tests/Upgrade, on
                               private 1.x databases), the MongoDB import (Tests/Import, with its fixture captured
                               from the removed MongoDB backend and the importer's contract) and black-box characterisation tests;
                               Tests/Fixtures holds test-only controllers, a command, a service and DBAL
                               middlewares;
                               Tests/rig/ is the local test rig (and mongo-import.sh with its Dockerfile,
                               the import's test in Docker), Tests/build/ the CI configs and router
```

### Entry points

These entry points build the kernel, with `Idno\Kernel::fromGlobals()`: environment from
`APP_ENV` (default `prod`), debug from `APP_DEBUG` (`1`/`true`/`on`/`yes` are true;
anything else, or unset, is false), both read from `$_SERVER`.

- **`index.php`** (HTTP): every web request. See the request lifecycle below.
- **`Idno/start.php`** (boot only): boots the kernel and so the legacy application, and
  returns. Used by `Tests/_bootstrap.php` and by third-party scripts that need
  `Idno::site()`. It does not handle a request. On a site with no configuration it sends
  the same redirect to `./warmup/` as `index.php` and exits. While the schema gate is closed
  (see "The schema gate and the upgrade") it prints one line on stderr (`Idno's database needs
  an upgrade: load the site in a browser or run php bin/console idno:upgrade`,
  `SchemaGate::CLOSED_MESSAGE`), sets status 503 and exits 1; for a site configured for MongoDB
  the line is `PublicMessage::mongoRetired()` instead (`SchemaGate::closedMessage()` chooses, for
  this entry point and the console). Pinned by `Tests/Kernel/SchemaGateBootTest`, through
  `Tests/Kernel/boot_schema_gate.php`.
- **`bin/console`** (CLI): first refuses to run under any SAPI but `cli` or `phpdbg` (a web
  server that executes it gets a 404 with an empty body). It then defines `IDNO_CONSOLE` (before the kernel boots, because it makes
  `LegacyBootstrap` skip the web-only boot steps), boots the kernel (as every entry point
  does, whatever the command), and runs `Idno\Console\Application`, a subclass of
  FrameworkBundle's console application. While the schema gate is closed the kernel does not
  boot the legacy application: the legacy console plugins and the `version` command are not
  registered, and every command but `idno:upgrade` and `idno:import:mongo` (the ways out of a closed
  gate; their help too) first prints `SchemaGate::closedMessage()` on stderr (`CLOSED_MESSAGE`, or
  the MongoDB message for a site configured for MongoDB; also before the "not defined" error for a
  legacy command); Symfony's own commands, `idno:upgrade` and `idno:import:mongo` run (pinned by
  `Tests/Kernel/SchemaGateBootTest`). On a
  site with no configuration it prints one line to stderr and exits 1, before anything connects
  (pinned by `Tests/Kernel/UpgradeCommandNotInstalledTest`); on any other
  exception while booting it prints `Idno could not start: <exception class>: <message>`
  to stderr and exits 1 (it does not carry on). `idno.php` and
  `known.php` are aliases that repeat the SAPI check and require `bin/console`; use either.
  See "Console commands" below.

These run WITHOUT the kernel or the container, so installer code cannot use container
services, `Idno::site()` or `Idno::container()`:

- **`warmup/index.php`** (web installer, a separate web entry point that the web server
  serves directly): loads `vendor/autoload.php` and `warmup/WebInstaller.php` only.
- **`warmup/CLI/CLIInstaller.php`** (command-line installer): loads `vendor/autoload.php` only.
  It writes `configuration/config.ini` only; per-domain config files are no longer read, so
  `install <name>` refuses any other name.

Both installers set up MySQL, MariaDB or SQLite, through ONE method of their shared base,
`Idno\Core\Installer::databaseParams(string $database, array $input)`: it checks the chosen
database and returns the values `buildConfig()` fills `warmup/webserver-configs/config.ini.template`
with, or throws `Idno\Exceptions\ConfigurationException` with the message to show (the web installer
escapes it). `$database` is read by `Installer::databaseType()`: `sqlite`, `SQLite` and `sqlite3`
(with spaces around them) are SQLite, `mongo` and `mongodb` (any case, with spaces around them) are
REFUSED (a `ConfigurationException`: MongoDB is no longer supported, the choices are MySQL or SQLite,
and `idno:import:mongo` moves a MongoDB site; it is thrown by `databaseType()` itself, so by
`databaseParams()` and by both installers before anything is written; the web installer shows it on its
settings page, the CLI installer lets it end the command with the message), anything else is MySQL or
MariaDB. For MySQL the input is
`name`, `user`, `pass` and `host`, the check is a connection (`installSchema()`), and the value written
is `MySQL`. For SQLite the input is `path`: empty is `<project>/data/idno.sqlite`, a relative path is
under the project directory (as the site reads it). `Installer::sqlitePath()` (also what `makeconfig`
calls) checks the path WITHOUT touching the disk and refuses with a `ConfigurationException`, saying
why, a path with a single quote or a control character (it cannot be written in the configuration
file), a segment `..` (either slash), and a file name that does not match
`ConnectionParameters::SQLITE_FILE_NAME` (`/^[A-Za-z0-9._-]+\.sqlite\z/`, lower case only: the Apache
rules match case-sensitively): `.sqlite` is the suffix every shipped web-server configuration refuses
to serve and PHP never executes; `ConnectionParameters::fromLegacyConfig()` applies the same rule to an SQLite `dbname` (other than
`:memory:`) and throws a `\LogicException` naming the setting, so no configuration, however written,
can make the site create its database under an executable name; the import command refuses such a target
before connecting. `Installer::buildConfig()` (every installer) refuses a value containing a quote, a
control character or ending in a backslash, with a `ConfigurationException` naming the setting, because
values are written inside single quotes and such a value could add or change another setting (pinned by
the property test in `InstallerDatabaseTest`) and fills the template in ONE pass, so every value is written exactly as given. Both installers call `Installer::assertWritableConfigValues()` with the values they will write BEFORE they create or write anything (the SQLite file, `.htaccess`, the configuration), so a refused value leaves nothing behind; the web installer builds the file's text before it writes anything and shows the refusal on the settings page. A database file can neither be fetched nor run through the site
(directory names may hold anything else, spaces included). `sqliteParams()` then checks what it can
without creating anything (not a directory, the existing directory and file writable) and creates the
directory (0755) and an empty file last; the file is opened through DBAL with `SqliteConnectionSetup`
(which refuses SQLite below 3.38), so an existing file that is not an SQLite database is refused and
left as it is (an empty file is a valid new database). The value written is exactly `sqlite` (`Sqlite3` in a configuration means MySQL: see
`ConnectionParameters::driverFamily()`) and `dbname` is the absolute path; `dbuser`, `dbpass` and
`dbhost` are empty. Neither installer loads SQL: the tables are created by the upgrade, as for MySQL.
`Installer::requiredModules()` no longer lists `pdo_mysql`: `Installer::databaseModules()` is
`pdo_mysql` and `pdo_sqlite`, and the requirements page and `check-requirements` need at least one
(`Installer::databaseModuleAvailable()`). The web installer's settings page has the choice as two
radio buttons in one `fieldset` (MySQL or MariaDB by default) and NO SQLite path: the installer of a
site that is not set up is reachable by anyone, so the web installer's SQLite database is always
`<project>/data/idno.sqlite` (the page says where, escaped, and that another place needs the CLI
installer or a hand-written `config.ini`; a `sqlite_path` in the request is ignored). What the
settings page decides is `Installer::checkWebSettings($method, $request)` (no output, no exit; the
page, `WebInstaller::pageSettings()`, only draws): nothing is checked against the database, and no file
or directory is created, unless the method is POST and the upload path has passed its own check (a
failed submission leaves nothing behind); a value that is not a string (`upload_path[]=x`) is empty;
an empty upload path says "You need to specify an upload path." for either database. Do not add a
disk path the visitor chooses to the web installer. The CLI asks
`Please enter the database type (mysql or sqlite): `, and for SQLite the path in place of the
database's name, user, password and host (manifest keys `database` and `sqlite_path`; `makeconfig`
writes the SQLite settings without creating the file). The installers change no file's mode: an SQLite
site set up from the command line has files owned by that user, which the web server cannot write, so
the CLI installer (and the import command, for an SQLite target) prints
`Installer::sqliteOwnershipNotes()`: the file, its directory and the files SQLite and the upgrade
create beside it (`-wal`, `-shm`, `.upgrade.lock`) must be owned by or writable for the web server's
user (`chown -R www-data data/`), and console commands on the site run as that user. `Tests/Unit/Install/InstallerDatabaseTest`
pins the SQLite side in the unit tier (a test subclass whose root is a temporary directory),
`WebSettingsTest` the web installer's decision, `CliInstallerTest` `makeconfig` and the ownership notes,
`Tests/Kernel/InstallerSchemaTest` the MySQL side (skips on SQLite). `ext-pdo_mysql` is a Composer `suggest`, not a
`require`, as is `ext-pdo_sqlite`.

### Request lifecycle

1. `index.php` checks the PHP version, registers the fatal-error page
   (`Idno\Legacy\FatalErrorPage`), loads the autoloader,
   then runs `Idno\Http\FrontController`.
2. `FrontController::requestFromGlobals()` builds the Symfony `Request` (it never parses
   the body, so `php://input` stays readable for legacy code), and
   `FrontController::handle()` calls `Kernel::handle()`.
3. The kernel boots: the container is built (or loaded from cache); a site with no
   configuration throws `NotInstalledException` (before anything connects), which
   `FrontController` turns into the redirect to `./warmup/`; the schema gate is asked
   (`Idno\Upgrade\SchemaGate`, see "The schema gate and the upgrade"); and, when it is open, the
   legacy application is booted by `Idno\Legacy\LegacyBootstrap` (configuration, `Idno::site()`,
   plugins, the legacy session — `session_start()` happens here, before routing). When the gate
   is closed the legacy application is NOT booted (no `Idno::site()`, no session, no legacy
   error handler) and `Idno\Upgrade\UpgradeGateListener` (a `kernel.request` listener at priority
   300, before the router) answers every request with the upgrade page or a plain 503, except one
   the router will match to the step route (`UpgradePage::isStepRequest()`: POST as sent and as
   Symfony reads it, despite any `X-HTTP-Method-Override`, to exactly `/_upgrade/step`), which
   reaches `UpgradeController`; so no request reaches the router's legacy catch-all, and steps 4 to
   6 below do not happen for them (`Kernel::terminate()` still runs; no Idno listener is on it).
   While the database is unreachable the legacy application boots as before (its database error
   page), except for an upgrade step, which answers 503 itself (see "The schema gate and the
   upgrade").
   `LegacyFallbackListener` leaves a request to Symfony while the legacy application is not
   booted.
4. Symfony's router matches the request. Attribute-routed controllers under `Idno\`
   (and, in the `test` environment only, `Tests\Fixtures\`) are tried first. Anything
   they do not match goes to the lowest-priority catch-all route `idno_legacy`
   (`/{path}`, any path, any HTTP method): `Idno\Legacy\LegacyController`.
5. `LegacyController` returns an `Idno\Legacy\LegacyResponse`: a streamed response that
   sends no status line and no headers of its own. Symfony's response listeners still
   modify the object (they set a charset, and `X-Robots-Tag: noindex` in debug mode), but
   that has no effect, because `LegacyResponse` sends none of it. When `index.php` sends it, its callback runs
   `Idno\Legacy\LegacyDispatcher::dispatch()`, which is the old `index.php` routing:
   registers the Toro `404` hook, adds the `/` route under the site's subdirectory if
   any, and calls `PageHandler::serve()`. The legacy router (Toro) then runs the matching
   `Idno\Common\Page` class, which reads the superglobals and writes the response itself
   with `header()`, `http_response_code()`, `echo` and often `exit`.
6. If the page returns rather than exits, `index.php` calls `Kernel::terminate()`. Pages
   that `exit` skip it, so nothing essential may live in a `kernel.terminate` listener.

Because legacy pages run while the response is sent (after `Kernel::handle()` has
returned), an exception thrown by a legacy page is handled by the legacy exception
handler (`Page::exception()`, the 500 page), never by Symfony. If the kernel fails
before a controller is chosen because Symfony rejects the request itself (a
`BadRequestHttpException`: a malformed Host header, an invalid `X-HTTP-Method-Override`),
`Idno\Legacy\LegacyFallbackListener` logs a warning through the legacy logger
(`Idno\Core\Logging`, so the site's log level and line format apply; the request-derived
detail is stripped of control characters and cut to 200 bytes) and hands the request to
the legacy router, so such requests are served exactly as before. (The listener receives the
logger as a service closure and fetches it only then: building it needs the legacy
application, and in a second kernel would replace the PHP error handler.) Any other
exception before routing (a broken route definition, a failing listener) and any
exception thrown by a new-style controller is handled by Symfony: it is logged to PHP's
error log by the `symfony` Monolog handler (see "Kernel and services") and shown as
Symfony's error page.

### Adding a route (the new way)

New HTTP endpoints are Symfony controllers, not `Idno\Common\Page` classes, with one
limit: **there is no session authentication, access control or CSRF protection for
new-style controllers yet** (Symfony Security arrives in a later phase). Legacy `Page`
classes get the logged-in user, `gatekeeper()`/`adminGatekeeper()` and CSRF token checks
on POST automatically; a controller gets none of them. Until then, any endpoint that needs
a logged-in user, checks permissions, or accepts a state-changing request from a browser
MUST remain a legacy `Idno\Common\Page`. New-style controllers are only for public,
read-only endpoints, or endpoints that authenticate each request themselves (e.g. a signed
request or a bearer token).

The one exception is `POST /_upgrade/step` (`Idno\Upgrade\UpgradeController`): it changes
state without authentication or CSRF protection, because while the schema gate is closed nobody
can log in (the legacy application does not boot) and whoever loads the site must be able to
finish its upgrade. It is safe by construction, and must stay so: it takes no input that selects
what it does (only the presentation flag `?html=1`); with the gate open it only reports `current`
(no migration, no index, no copy, no lock, the runner not even built); every call does bounded
work (a deadline five seconds ahead) and concurrent calls are serialised by `UpgradeLock` (the one
that does not get it reports `working` and does nothing); every response carries `Cache-Control:
no-store`; the `303` of `?html=1` goes to the request's own base path plus `/`, never to a URL
from the request; nothing from the database reaches the response but JSON-encoded counts, phase and
the copier's stored error (the page HTML-escapes them). Pinned by `Tests/Unit/Upgrade/UpgradeControllerTest`
and `Tests/Upgrade/UpgradeEndToEndTest`. Do not add another.

- Put a controller class with a `#[Symfony\Component\Routing\Attribute\Route]`
  attribute in a directory that `config/routes.php` imports attribute routes from
  (today `Idno/Upgrade/` and `Idno/Legacy/`; add a new directory, e.g. `Idno/Http/Controller/`, to
  `config/routes.php` AND to `CodeFingerprint::SERVICE_DIRECTORIES` so the container
  and route cache are rebuilt when it changes).
- It always wins over the legacy catch-all, whatever the path: that route has priority
  `-1000`. So a new route also shadows any legacy page at the same path, but only for
  the HTTP methods it accepts: a request with another method still reaches the legacy
  router. For GET and HEAD, Symfony also answers the trailing-slash variant of the path
  (`/foo/` for `/foo`, and the reverse) with a 301 redirect to the route's own path.
- It must return a `Symfony\Component\HttpFoundation\Response`, and must take its input
  from the `Request` (inject it as an argument), never from `$_GET`, `$_POST`,
  `$_SERVER` or other superglobals, and must not call `header()`, `echo` or `exit`.
- The `Request` is built without parsing the body (see `FrontController::requestFromGlobals()`),
  so `$request->request` is empty for form-encoded `PUT`, `PATCH` and `DELETE` requests
  (it is filled for `POST`). Read the raw body with `$request->getContent()` and parse it
  yourself (e.g. `parse_str()` or `json_decode()`).
- Do not assume a clean header slate: the legacy application boots before routing, and
  the headers it has already set (`Set-Cookie`, `Expires`, `P3P`, `Link`, a
  `Cache-Control`, `Pragma: no-cache`) are sent with your response, alongside the response's own headers
  (so there can be two `Cache-Control` headers).
- Mark it `#[AsController]` (or register it as a service) when it needs constructor
  injection; services are autowired from the container.
- A test-only route goes in `Tests/Fixtures/` (loaded by `config/routes/test/fixtures.php`
  in the `test` environment only). `Tests/Fixtures/PingController` (`/_test/ping`) is the
  example, pinned by `Tests/Characterisation/KernelRoutingTest`; `ThrowingController`
  (`/_test/throw`) throws, for `Tests/Kernel/KernelExceptionTest`.
  The routes the outbound-HTTP tests use (`Tests/Characterisation/WebserviceWireTest`,
  `OutboundFlowsTest`, `TestRoutesTest`), each answering any method with `Cache-Control: no-store`:
  `/_test/echo` (JSON: `method`, `query`, `headers` with lower-case names, `content_type`, `body` as
  base64, `fields`, `files` with `field`, `name`, `type`, `size`, `sha256`; the description is
  `RequestDescription`); `/_test/redirect/{status}?to=<path>` (301, 302, 303, 307 or 308, to this
  site's own URL plus `to`, default `/_test/echo`; a `to` that does not start with `/_test/`, or holds
  a space or control character, is answered 400); `/_test/slow?seconds=N` (sleeps at most 30);
  `/_test/stream?bytes=N` (chunked, no `Content-Length`) and `/_test/sized?bytes=N` (the same body with
  a `Content-Length`), at most 20 MB; `/_test/sink/{name}` (appends the request, in `/_test/echo`'s
  shape, to a file of its own and answers 202) and `/_test/sink/{name}/log` (the recorded requests as
  a JSON list, then the file is emptied), with `name` matching `[a-z0-9-]{1,40}`; and
  `/_test/page/{name}` (an HTML page advertising `/_test/sink/{name}` as its webmention endpoint). The
  sink's files are in `<sys_get_temp_dir()>/idno-test-sink-<first 12 hex digits of the SHA-256 of the
  checkout's real path>/`, made on demand (CI's servers do not use the rig, so the rig's directory
  may not exist), and are written under `LOCK_EX` because the web server has several workers. A test
  takes the site's URL from the configuration (`$this->site()->baseUrl()`), never a fixed address,
  and asserts named headers only, never the complete set a request carried.

### Console commands

The test rig's configuration enables `pedantic_mode`, which turns every reported PHP error into
an exception. On PHP versions that emit deprecations for legacy code, a plain `php bin/console`
there prints them, one becomes a warning ("headers already sent") and the console stops with
`Idno could not start: ...`. Run it as `composer console -- <command>` (or
`php -d error_reporting=22527 bin/console <command>`), for the same reason tests run through
`composer test`. Sites without `pedantic_mode` are not affected.

The console's environment and debug flag come from the `APP_ENV` and `APP_DEBUG` environment
variables, through `Kernel::fromGlobals()`, e.g. `APP_ENV=test APP_DEBUG=1 php bin/console ...`.
Symfony's `--env`/`-e`, `--no-debug` and `--profile` options are listed by `--help` but have no
effect here. A legacy `ConsolePlugin` must not define options named `--env`/`-e`,
`--no-debug` or `--profile`: they are global options now.

`php bin/console list` shows Symfony's own commands (`cache:clear`, `debug:router`,
`debug:container`, ...) next to Idno's, in one application.

- **Adding a command (the new way)**: a class under `Idno/` that extends Symfony's
  `Command` and has the `#[Symfony\Component\Console\Attribute\AsCommand]` attribute,
  in a directory the container scans (`Idno/Console/` is the place; see
  `CodeFingerprint::SERVICE_DIRECTORIES`). It is autowired and autoconfigured, so it is
  tagged `console.command` and discovered automatically; take its dependencies in the
  constructor and put the work in `__invoke()` (or `execute()`). It must return an int.
  `Tests/Fixtures/ProbeCommand` (`idno:test:probe`, registered in `config/services_test.php`)
  is the test-environment-only example, pinned by `Tests/Kernel/ConsoleTest`; it is absent
  in `prod`. Console code may take a `Psr\Log\LoggerInterface` only when it never runs while
  the schema gate is closed: then the legacy application (which backs that logger) is not
  booted. Commands that must run then (`idno:upgrade`) take no logger and nothing of the legacy
  application.
- **`idno:upgrade`** (`Idno\Console\UpgradeCommand`): creates or upgrades the database of an
  SQL site (MySQL, MariaDB or SQLite) in one run, `UpgradeRunner::run()` (see "The schema gate and
  the upgrade"): the pending schema migrations, the indexes the core entity classes (`Idno/`)
  declare (re-checked on a current database too, so a newly declared field gets its index), the
  copy of a 1.x database if there is one, and LAST, once the schema is current, the declared indexes
  of each ENABLED plugin (`Idno\Upgrade\EnabledPlugins`). A plugin whose
  indexes cannot be created (an invalid declaration, a unique index over duplicate values, any DDL
  error) is skipped with one `Warning:` line and one log line on `upgrade`; the run goes on and exits
  0 if nothing else failed; so does a run that cannot get the lock for that last part within the wait
  (one `Warning:` line, one log line: run it again). A plugin directory that is there but not enabled is never read. It prints each migration it ran (`Ran the migration <version>.`), each index it
  created (`Created the index <collection>.<field>.`), each declaration that differs from its
  existing index, during a copy one line per collection whose count moved after each step
  (`entities: 1500 of 12000 copied`) and `The 1.x data is copied.`, or `Nothing to do: the schema is
  up to date.`; it exits 0 once the schema is current, and 1 with the public message on stderr (and
  the private detail on a `Detail:` line, the one place it is shown) for a 1.x database too old to
  copy, an unsupported database server, a lock held for over 60 seconds by another process, 1.x
  tables still in use by something else after the same 60 seconds, steps that change nothing, or an
  error. An unreachable database opens the gate, so the console boots the legacy application, whose
  database adapter prints its database error page on stdout and ends the process with exit 0, as for
  every command: `idno:upgrade` and `--status` are never reached then, and print no trace (pinned in
  a subprocess, on a closed port or an SQLite file in a missing directory, by
  `UpgradeCommandTest::testWithTheDatabaseDownTheLegacyErrorPageEndsTheRun`). Where the command is
  reached with the database unreachable (in-process), both print `PublicMessage::unreachable()` on
  stderr and exit 1 (`Tests/Unit/Upgrade/UpgradeCommandUnreachableTest`). `--status`
  prints `Schema status: <status>` (the `SchemaStatus` value), the pending migrations (`Pending
  migration: <version>`, or `No migration is pending.`) and, for a 1.x database, the copy's phase,
  counts, stored error and its detail, and changes nothing. It exits 1, with the public message on
  stderr (`PublicMessage::forStatus()`), for the statuses that refuse the upgrade
  (`SchemaStatus::refusesTheUpgrade()`: an unsupported database server, a 1.x database too old to
  upgrade, renamed 1.x tables with no upgrade on record, the 1.x tables beside a completed upgrade,
  a site configured for MongoDB) and for an unreachable database (exit 0 for every other status). It runs whether or not the legacy application booted
  (with the gate closed it does not), so it takes no logger and nothing from the legacy application.
  It reports the migrations THIS run executed (`SchemaMigrator::migrate()` returns them), never one
  another process ran meanwhile; while another process holds the `UpgradeLock` it says so once and
  waits half a second between attempts, for at most 60 seconds. On a site with no configuration it prints the one "not installed" line
  and exits 1 before anything connects. On a site configured for MongoDB every form of the command
  but `--status` (a run, `--drop-legacy`) prints `PublicMessage::mongoRetired()` on stderr and exits 1
  before fetching anything (the command takes the runner, the migrator and the copier as service
  closures, because building them fetches a connection that site has none of); `--status` prints
  `Schema status: mongodb retired` and the message and exits 1. On a site whose database is a backend
  class a plugin supplies it fails (building the runner fetches the connection, which throws) and exits 1. The rig and CI run it before the first
  request (when it fails, `up.sh` says so on stderr and exits non-zero without starting the web
  server). Pinned by `Tests/Kernel/UpgradeCommandTest` (subprocesses on a private empty database)
  and `Tests/Upgrade/UpgradeEndToEndTest` (a 1.x database). `--drop-legacy` (with `--yes`) deletes
  the `legacy_` tables of a completed upgrade and does nothing else (see "The legacy tables").
- **`idno:import:mongo`** (`Idno\Console\ImportMongoCommand`): moves a MongoDB site into a new,
  empty MySQL, MariaDB or SQLite database and its GridFS files into the upload directory
  (`--target=<database URL>` or the environment variable `IMPORT_TARGET_URL`, `--upload-path`,
  `--file-host`, `--source-uri`, `--source-db`); see "Importing a MongoDB site". Like `idno:upgrade` it
  takes nothing from the legacy application (its logger is the `upgrade` channel, wired by name in
  `config/services.php` with `%kernel.project_dir%`) and builds its target connection
  (`ConnectionFactory::forConfig()`), stores and file store itself.
- **Legacy console plugins still work**: `Idno\Common\ConsolePlugin` subclasses
  (`getCommand()`, `getDescription()`, `getParameters()`, `execute()`) are found by
  `Idno\Console\Application` once the kernel (and so the legacy application) has booted,
  in this order: `ConsolePlugins/<Name>/Main.php` and `ConsolePlugins/<Name>.php`, then
  `IdnoPlugins/<Name>/ConsoleMain.php` and `IdnoPlugins/<Name>/Console/*.php`. A legacy
  `execute()` that returns nothing exits 0. Do not add new ones; add a command class.
  The built-in `version` command is also registered there.
- `Idno\Console\Application` takes the kernel, so it is excluded from the container's
  service scan in `config/services.php` (as is `Idno/Console/functions.php`, which defines
  the global `application()` function that legacy console plugins call; it returns the
  application, which is also the global `$console`).
- In-process tests must not boot a second legacy kernel to run commands: build the
  application from `Idno::container()->get('kernel')` and use `ApplicationTester`. No test
  may use the network: the `version` command checks GitHub for a newer build, so run it only in
  a subprocess with `IDNO_PROXY_STRING=127.0.0.1:9` (see `ConsoleCompatibilityTest`). PHPUnit's
  bootstrap boots the web path and does not define `IDNO_CONSOLE`, so test anything that
  depends on the console path in a subprocess.

### Legacy pages and routes (still supported)

Existing `Idno\Common\Page` classes keep working unchanged: plugins and core still
register them with `Idno::site()->routes()->addRoute($pattern, $handlerClass)` (Toro-style
regex patterns, see `Idno\Core\PageHandler`), and they implement `getContent()` /
`postContent()` / `putContent()` / `deleteContent()`. They are reached through the
catch-all described above. Do not add new ones; add a controller instead, except where "Adding a
route" says an endpoint must stay a legacy page (a logged-in user, a permission check or a
state-changing browser request): the admin page `/admin/legacytables` is one, for that reason.

`Idno\Legacy\` is where the shims between the kernel and the legacy application live
(`LegacyBootstrap`, `LegacyServiceFactory`, `LegacySiteSettings`, `LegacyController`,
`LegacyDispatcher`, `LegacyResponse`, `LegacyFallbackListener`, `FatalErrorPage`,
`VerbatimLogFormatter`, `NotInstalledException`, and for storage `LegacyRecordStoreFactory`,
`LegacyRecordDefaults`, `LegacyAccessContext`, and the `db()` service of SQL sites,
`RepositoryDataConcierge`, with `LegacySqlMaintenance`). They
exist to be removed as the legacy code is replaced. New code must not depend on anything
in `Idno\Legacy\`; depend on the interfaces in `Idno\Config\` and on injected services.
The only classes outside `Idno\Legacy\` (and outside the legacy `Idno\Core`, `Idno\Common`,
... code) that reference it are `Idno\Kernel` and `Idno\Http\FrontController`, for wiring;
the entry points (`index.php`, `bin/console`, `Idno/start.php`) and the kernel configuration
(`config/services.php`, `config/routes.php`, `config/packages/monolog.php`) wire it too. No
other new code may.

### Kernel and services

- `Idno\Kernel` is configured by `config/` (`bundles.php`: FrameworkBundle, MonologBundle
  and DoctrineBundle (DBAL only, no ORM); `packages/framework.php`: Symfony's session and error
  handling are disabled, the legacy application owns both; `packages/doctrine.php`: the DBAL
  connection, see below; `services.php`; `routes.php`).
  `Kernel::boot()` refuses a site with no configuration (`LegacyBootstrap::ensureInstalled()`),
  asks the schema gate (`Idno\Upgrade\SchemaGate`, a public service, unless a kernel has already
  booted the legacy application in this process: `LegacyBootstrap::bootedInThisProcess()`), and
  boots the legacy application through `Idno\Legacy\LegacyBootstrap` only when the gate is open;
  `Kernel::legacyBooted()` says whether it did. Pass `bootLegacy: false` to the constructor to boot
  the kernel alone, as some tests do: such a kernel never asks the gate, and the gate's listener
  does nothing in it.
- The legacy application is a process-wide singleton (`Idno::site()`, `Idno::container()`,
  constants, shutdown functions). `LegacyBootstrap::boot()` is idempotent per process: once
  any kernel has booted it, booting another kernel leaves the first one's container and
  site in place. The trap: every kernel's container still builds its OWN legacy services
  through `LegacyServiceFactory` (a new, empty `PageHandler`, a new `Config`, ...). Only
  the first kernel booted in a process has legacy services wired to `Idno::site()`. In a
  second kernel, anything injected with `PageHandler` or `Config` gets objects that are not
  `Idno::site()`'s, and `LegacyDispatcher::dispatch()` throws a `\LogicException` rather
  than serve 404s from an empty route table.
- `config/services.php` scans `Idno/<dir>` for each directory in
  `Idno\Kernel\CodeFingerprint::SERVICE_DIRECTORIES` (`Kernel`, `Config`, `Http`,
  `Console`, `Legacy`, `Storage`, `Upgrade`) and registers the classes as autowired, autoconfigured
  services, except the files it excludes (classes that are not services, or that are wired
  by hand). Value objects and static helpers in a scanned directory are not services and
  must be added to the `$excludes` map in `config/services.php` (today in `Idno/Storage/`:
  `ConnectionFactory.php`, `ConnectionParameters.php`, `Criteria.php`, `Criterion/`, `EntityClassFinder.php`, `EntityQuery.php`,
  `Attribute/` (attribute classes), `FixedAccessContext.php`, `FixedRecordDefaults.php`, `IdentityTypeMap.php`, `Migrations/` (the schema migrations),
  `Files/` (the file store, below), `MySqlSemantics.php`, `Platform/`, `RecordDefaults.php`, `RecordIndex.php` and `SettingsRecordStore.php`; in `Idno/Legacy/`, `FlysystemFile.php`, `FlysystemFileSystem.php`, `RepositoryDataConcierge.php`
  and `LegacySqlMaintenance.php`, which `LegacyServiceFactory::db()`
  builds; in `Idno/Upgrade/`, the value objects `UpgradeProgress.php` and `PublicMessage.php`, the
  enum `SchemaStatus.php`, the exception `LegacyTableReferenced.php`, the static helpers
  `ServerVersion.php` and `UpgradePage.php`, and the copier's helpers `ConfigCopier.php`,
  `DocumentCheck.php`, `LegacySource.php`, `RowWriter.php`, and `Mongo/`, the MongoDB import, built with
  new by its command), or the container will try to autowire
  them. `RecordStore` implementations (`InMemoryRecordStore.php`,
  `JsonRecordStore.php`, `SettingsRecordStore.php`) are excluded too: the container's `RecordStore` is built by a factory (see
  the storage services below). The container's `Idno\Storage\TypeMap` is `AttributeTypeMap` (an alias in `config/services.php`). `BootConfigConnectionFactory.php` is
  excluded from the scan and wired by hand. In `Idno/Kernel/`, the compiler pass
  `DoctrineTrimPass.php` is excluded (the kernel adds it in `build()`).
- The database connection: DoctrineBundle's `database_connection` service (`doctrine.dbal.default_connection`,
  autowired as `Doctrine\DBAL\Connection`). `config/packages/doctrine.php` configures no credentials:
  `Idno\Storage\BootConfigConnectionFactory` decorates the bundle's connection factory and, when
  the service is first fetched, replaces the parameters with `ConnectionParameters::fromLegacyConfig()`
  of the `BootConfig` (through `Idno\Storage\ConnectionFactory`, a static helper that is the one place
  deciding a legacy configuration's parameters and middlewares: `ConnectionFactory::forConfig()` gives
  the same connection, lazy, for any configuration, as the MongoDB import builds its target) (plus the `RDS_*` environment variables for `beanstalk-mysql`), so no
  configuration value is compiled into the container. It reproduces the legacy connection
  exactly: DSN charset `utf8mb4` (the schema's tables are utf8mb4), `Pdo\Mysql::ATTR_LOCAL_INFILE => 1`,
  `ERRMODE_EXCEPTION`, emulated prepares, the same session `sql_mode` and time zone. On a site whose
  `database` is `sqlite` (`dbname` is the database file's path, or `:memory:`), the factory also
  adds `Idno\Storage\Platform\SqliteConnectionSetup` to the connection's middlewares; on every
  site it adds `Idno\Storage\Platform\ConnectFailureIsFinal` last (a first connect that fails is
  never retried on that connection: see "The schema gate and the upgrade"); the MySQL connection's
  parameters and options are as before. The service is
  lazy: fetching it does not connect (the first query does; on an SQL site the `db()` service
  fetches it and connects it during `Idno::init()`, see the `db()` bullet below). What the
  `database` setting means is decided by `ConnectionParameters::driverFamily()`, which the
  connection factory and `LegacyRecordStoreFactory` use for every value:
  `mysql`/`beanstalk-mysql` → `mysql`, `sqlite` → `sqlite`, `mongo`/`mongodb` and the removed backend's class name `Idno\Data\Mongo` (any case, leading backslash optional) → `mongo`; any other
  value is `other` when it names a loadable backend class the way the legacy
  `Idno::componentFactory()` reads it (a full class name, or a class in `Idno\Data\`, extending
  `Idno\Core\DataConcierge`; `ConnectionParameters::backendClass()`), and otherwise `mysql`, because
  `componentFactory()` fell back to the old MySQL backend for a name it could not load.
  `LegacyServiceFactory::db()` decides `beanstalk-mysql` itself, in its own `switch`, before it asks
  `driverFamily()`: it copies the `RDS_*` server variables into the legacy config and builds the
  adapter on MySQL (`ConnectionParameters::fromLegacyConfig()` repeats that `RDS_*` mapping for the
  connection: keep them in step if you change either); every other value goes to `driverFamily()`
  (`mysql`/`sqlite`: the adapter; `other`: the class, through `componentFactory()`; `mongo`: a
  `\LogicException` with the MongoDB message, which only code that builds the service although the
  schema gate is closed can reach). `mongo` stays a family so that nothing connects for such a site
  and the gate can say what to do (`SchemaStatus::MongoRetired`). On a MongoDB or
  `other` site the container compiles and the kernel boots as before, but fetching the
  service throws a `\LogicException`: nothing but the schema gate may fetch the connection while
  the kernel boots (no `kernel.request` listener, no service built at boot; the gate's
  `SchemaState` checks `driverFamily()` first and fetches nothing on a site that is not SQL), and
  code that uses it must only run on an SQL site. Query logging and profiling are off (`config/packages/doctrine.php`). DoctrineBundle
  brings only the connection: `Idno\Kernel\DoctrineTrimPass` (a compiler pass added in
  `Kernel::build()`) removes every console command the bundle registers (no `doctrine:*` or
  `dbal:*` command exists; `dbal:run-sql` and `doctrine:database:drop` would act on the site's
  database) and its idle-connection `kernel.request` listener. `Tests/Kernel/DoctrineTrimTest`
  pins both in the test and prod containers. Do not add them back.
- SQL against the site's tables (`JsonRecordStore`, `SettingsRecordStore`, see "Database"): bind
  EVERY value as a string, as the old backend did (true is '1', false '', numbers their text, null
  NULL), except a float operand of a document-field condition, which is its JSON text
  (`RecordIndex::floatText()`); the contract pins behaviour that depends on it (`$lt` on a document
  field compares text). Never interpolate a value; identifiers are collection names reduced to
  `[a-zA-Z0-9_]` and indexed-field names `[a-z0-9_]{1,48}`, quoted by the `Dialect`, and the JSON path
  of an ad-hoc field is a bound parameter. A collection `c` exists when its table `idno_<c>` does
  (the `SchemaCatalog` says so); any other name gives false, null, `[]` or 0 without querying it. A
  `created` operand is read in PHP (`MySqlSemantics::epoch()`): one that is not a datetime literal
  makes the query match nothing on every engine, and is never sent. A SELECT the database rejects for
  its operand matches nothing (`Dialect::isRejectedOperand()`: on MySQL and MariaDB, error 1064 only
  for a statement holding a boolean-mode full-text search, whose parser rejects terms such as
  `abc@def.com`); every other driver error (lost connection, broken SQL) propagates. A write returns
  false when the database refuses the record's data (`Dialect::isRefusedData()`, an allowlist:
  constraint violations, deadlock and lock-wait, and on MySQL and MariaDB 1292, 1366, 1406, and the
  JSON functions' refusals of a document: MySQL 3140, 3141, 3157; MariaDB 4035 to 4040 when the
  message speaks of JSON, because MySQL uses those numbers for other errors); every
  other error propagates. `JsonRecordStore::save()` is ONE statement, so it returns the id only when
  the row is stored, false when the data was refused, and throws otherwise; `SettingsRecordStore`
  rolls its transaction back before it returns false or throws. The `RecordStore` interface states
  this error contract for every implementation (what returns false, `[]`, 0 or null; everything else
  is thrown, from every method); `Tests/Unit/Storage/JsonRecordStoreFailureTest` and
  `SettingsRecordStoreContract` pin the throwing side.
- SQLite (`Idno\Storage\Platform\SqliteDialect`, SQLite 3.38 or later with the JSON functions,
  through `pdo_sqlite`; the connection setup refuses an older library, or one built without
  `json_valid()`, with a `\LogicException` naming the version required and the one found): the same
  stores, held to the same contracts with the hook values of `JsonSqliteRecordStoreTest`. **SQLite is
  a supported production database for a small site**: one server, a local disk, sessions as PHP files
  (see the `db()` bullet), search without a full-text index, ASCII-only case folding (below), backups
  with `.backup` or a copy of the file while no writer is active. It is NOT supported on a network
  filesystem (NFS, SMB) or with several web servers on one file. Both installers can set up an
  SQLite site (see "Entry points"), and so can a configuration written by hand (`database =
  sqlite`, `dbname = data/idno.sqlite`); the test rig's SQLite mode is an SQLite site too (see
  "Build & Test"). Where the file lives: `dbname` is the
  file's path; one that is not `:memory:` and not absolute (no leading `/`, no Windows drive) is under
  the project directory (`ConnectionParameters::fromLegacyConfig($config, $server, $projectDir)`: every
  caller passes it, the connection factory the kernel's `kernel.project_dir`; with null a relative path
  is left as written), and the `data/` directory (tracked only for its `.htaccess` and `readme.txt`;
  the rest is gitignored) is where a site's file belongs. The database URL (`IDNO_DATABASE_URL`,
  `KNOWN_DATABASE_URL`) is read by `ConnectionParameters::fromUrl()`, the one place: `sqlite` gives
  `database = sqlite` and `dbname` the URL's path less ONE leading slash (`sqlite:///data/idno.sqlite`
  is `data/idno.sqlite`, relative to the project; `sqlite:////var/lib/idno.sqlite` is
  `/var/lib/idno.sqlite`; `sqlite:///:memory:` is `:memory:`); every other scheme gives `database` the
  scheme and `dbname` the path's last segment, with `dbuser`, `dbpass`, `dbhost` and `dbport`. The
  upgrade's lock file is `<database file>.upgrade.lock`. Neither `data/` nor a file ending `.sqlite`,
  `.sqlite-wal`, `.sqlite-shm` or `.upgrade.lock` may be served: `.htaccess`,
  `warmup/webserver-configs/htaccess.dist`, `htaccess-2.4.dist` and `nginx.conf`,
  `docker/caddy/Caddyfile` and both routers refuse them, `data/.htaccess` denies all, and
  `Tests/Characterisation/DeniedPathsTest` pins every one. Every SQLite connection the storage uses MUST
  have `SqliteConnectionSetup` among its DBAL middlewares (the `database_connection` service of an
  SQLite site has it; a connection you build yourself needs
  `(new Configuration())->setMiddlewares([new SqliteConnectionSetup()])`, as
  `Tests\Storage\PrivateDatabase` does; the middleware refuses any connection that is not
  `Pdo\Sqlite`): on each new physical connection it sets `busy_timeout = 5000`,
  `journal_mode = WAL` and `foreign_keys = ON`. It registers nothing: the schema uses built-in
  collations and functions only, so the database file opens, checks (`PRAGMA integrity_check`),
  dumps and restores with the standard `sqlite3` tool. `new SqliteDialect()` takes nothing.
  - WAL needs shared memory between the processes using the file: never put the database on a
    network filesystem (NFS, SMB). A backup: a copy of the file with its `-wal`/`-shm` files while
    no writer is active, or `sqlite3`'s `.backup`.
  - Differences from MySQL: search never uses a full-text index (a term over 5 bytes matches any of
    its words as a substring, whatever `boolean_search` and `bypass_fulltext_search` say; no boolean
    operators, no parse errors); text compares with NOCASE, which ignores the case of ASCII letters
    only (`ÉTÉ` is not `été`, `café` is not `cafe`, `straße` is not `strasse`, `word ` is not
    `word`: MySQL and MariaDB equate each pair), and orders text folded to upper case (`_` after
    the letters, where MySQL sorts it before them);
    lengths are not enforced (an `_id` over the 36 characters of MySQL's `id` column is stored).
  - A write that cannot get the write lock within the busy timeout ("database is locked") is
    refused data: `save()` returns false (two processes writing at once both succeed: each waits for
    the other's lock, `SqliteConcurrencyTest::testConcurrentWritersDoNotFail`).
- The storage services, public, all built at runtime when first fetched: `Idno\Storage\RecordStore`
  (built by `Idno\Legacy\LegacyRecordStoreFactory`: a `SettingsRecordStore`, which serves the
  collection `config` from `idno_settings`, over a `JsonRecordStore`, which serves every other
  collection from its `idno_<collection>` table, both on the `database_connection` service with
  the dialect `DialectFactory` builds (the one mapping from the `database` setting to a dialect:
  `MySqlDialect` for a MySQL or MariaDB site, `SqliteDialect` for an SQLite site),
  `Idno\Legacy\LegacyRecordDefaults` (what the old backend's `saveRecord()` filled in), the
  container's `TypeMap` (`AttributeTypeMap`, the entities' aliases) and `SchemaCatalog` (public,
  shared with `IndexedFields`, which forgets it when it creates an index), the legacy quote
  normaliser, and the site's `boolean_search` / `bypass_fulltext_search`, read from the legacy config
  each time a search condition is built, as the legacy backend did (`JsonRecordStore` takes each as
  a bool or a closure); on a MongoDB or `other` site it throws a `\LogicException` without touching
  the connection or the catalog),
  `Idno\Storage\AccessContext` (`Idno\Legacy\LegacyAccessContext`: the legacy session's user,
  admin status and read groups, and the `db()` service's ignore-access flag, read at each call;
  with no session object it is anonymous with read groups `['PUBLIC']`) and
  `Idno\Storage\EntityRepository` (autowired from those two and the logger). Like the logger, they
  need the legacy application: do not fetch them in a `bootLegacy: false` kernel or while the kernel
  boots (nothing does; the container compiles and the kernel boots on every database, a
  MongoDB one included). On an SQL site `Idno::site()->db()`, and so every legacy `Entity::get*()` finder, uses
  them (next bullet).
- The `db()` service (`Idno::site()->db()`, `Idno\Core\DataConcierge`), built by
  `LegacyServiceFactory::db()`: on a site whose
  `database` names a loadable backend class (a class a plugin supplies, in `Idno\Data\` or fully
  qualified), that class (`componentFactory()`, as before); on a MySQL
  (`mysql`, `beanstalk-mysql`, or a name that is no loadable class) or SQLite site
  `Idno\Legacy\RepositoryDataConcierge`, the adapter that serves the public API of the old SQL backend
  (since removed: same method names, parameters, defaults, return and failure values, as
  listed in `RepositoryDataConciergeTest::OLD_BACKEND_METHODS`, except `build_where_from_array()`,
  which nothing outside that backend called) from the
  storage layer: records through the `RecordStore` service, `getObjects()`/`countObjects()`/`getObject()`
  through the `EntityRepository` service; `rowToEntity()`, `saveObject()` and `getAnyObject()` are
  `DataConcierge`'s. It holds no SQL and never creates or changes the schema (the upgrade does):
  `getVersions()` is one object, label `schema`, whose value is `SchemaMigrator::latest()` (the
  newest schema migration executed, `'0'` when none; false, written to PHP's error log, when it
  cannot be read); `checkAndUpgradeSchema()` does nothing; `optimize()` (the daily
  `/service/db/optimise` an admin's home page triggers: `analyze table` on `idno_entities`,
  `idno_reader`, `idno_site`, `idno_settings` and `idno_sessions` on MySQL and MariaDB, which
  refreshes the index statistics without rebuilding the table or blocking writes (observed on both),
  never `optimize table`, which on InnoDB rebuilds the whole table, full-text index and generated
  columns included, and holds up writes meanwhile; `pragma optimize` on SQLite; false, as before;
  the statements are pinned by `Tests/Unit/Legacy/LegacySqlMaintenanceTest`), `exportRecords()` (a
  page of a collection's table as `insert into idno_<collection> (...) values (...);` lines,
  `config` from `idno_settings`, its own columns only, never a generated `ix_` column; '' past the
  end; false, logged, for a name that is no collection; such an export cannot be loaded into a 1.x
  database) and the connection check of `reconnectIfNeeded()` are in
  `Idno\Legacy\LegacySqlMaintenance`, all on the shared connection. `getClient()` is the native PDO
  of the `database_connection` service (asked of the connection on each call, never a kept copy: a
  `Pdo\Mysql` on MySQL, a `Pdo\Sqlite` on SQLite), and on MySQL and MariaDB `handleSession()` installs the same
  `PdoSessionHandler` with the same options on that PDO, on the table `idno_sessions` (an advisory
  lock per session), so a request opens ONE database connection. On SQLite it installs NOTHING and
  `sessionHandler()` throws a `\LogicException`: PdoSessionHandler refuses advisory locks there, and an
  unlocked handler lets parallel requests of one session overwrite each other, so an SQLite site's
  sessions are PHP files, which PHP locks (`Idno\Core\Session` falls back to them, whatever
  `sessions_database` and `sessions_storage` say; `idno_sessions` is unused on SQLite). The old
  backend's `session` table is not read: users log in again after the upgrade (on SQLite, once more,
  when a development site's sessions moved from rows to files). `/_test/session`
  (`Tests/Fixtures/SessionProbeController`, test environment only) answers `{"module": ...}`:
  `files` on SQLite, `user` on MySQL and MariaDB, pinned by `KernelRoutingTest` and
  `RepositoryDataConciergeTest::testAnSqliteSiteInstallsNoSessionHandler`.
  `handleSession()` returns null, as the old backend's did, so `Idno\Core\Session` goes on to its
  `sessions_storage` step even when `sessions_database` (on by default) installed the handler.
  The old SQL backend classes (`Idno\Data\MySQL`, `Idno\Data\AbstractSQL`) have been removed.
  A site whose `sessions_storage` setting names `Idno\Data\MySQL` (in any case, with or without a
  leading backslash, as PHP matched the class name; it used to build a second connection whose
  handler replaced the adapter's) keeps its database sessions: `Idno\Core\Session` calls the
  adapter's `handleSession()` instead, so the same `idno_sessions` table and handler are used on the
  shared connection, with `sessions_database` on or off (pinned in
  `RepositoryDataConciergeTest::testASessionsStorageSettingNamingTheOldBackendKeepsDatabaseSessions`).
  Any other `sessions_storage` value behaves as before.
  `database` values naming the old class (`Idno\Data\MySQL`) or any other name that is no class
  mean MySQL, as they always did. The facts to keep:
  - Construction order: `Idno::init()` fetches `db()` early, before the logger, the session and the
    language exist. The adapter's constructor connects (as `MySQL::init()` did, at the same point
    of boot; it creates and changes no table); the record store, the repository and the logger are
    service closures fetched at first use (the store when the config is loaded, the repository when
    the first entity query runs). The legacy services are built in the same order as before
    (pinned in `RepositoryDataConciergeTest::testLegacyServicesAreBuiltInTheSameOrder`); do not
    give the adapter a collaborator that builds a later legacy service.
  - An unreachable database ends the request as `MySQL::init()` did: the driver's message is written
    to PHP's error log, then a redirect to `forward_on_empty` when it is set, otherwise status 500
    and `statics/db.php` (with the message and the connection string in debug mode); on the console
    the same page on stdout, exit 0 (pinned in `RepositoryDataConciergeTest`).
  - Which failures become a failure value: each method turns into its legacy failure value
    (`false`, `[]`) exactly the failures the old backend's own `try` covered: a failure of the
    database statement (a `Doctrine\DBAL\Exception` or a `\PDOException`, logged as before: the
    driver's PDOException message, as an error, through the site's logger), a search array
    `Criteria::fromLegacyArray()` cannot read (below), and a record `RecordIndex` cannot index
    (`saveRecord()`, below). Everything else PROPAGATES, as it did from the old backend, whose `try`
    held only its statements: an exception from the read-group lookup (the session and its
    `permission:read:ids` event), from hydration (`loadFromArray()`; an `\Error` while constructing or
    loading an entity is still logged and the record skipped, as `rowToEntity()` did), from
    `canRead()`, from an event listener or `RecordDefaults`, a `\LogicException`, a `\TypeError`. Never
    widen a `catch` in the adapter: an "empty" result from a broken listener makes callers act on
    nothing found (`Idno::site_details()` creates a new Site when its lookup is empty). Pinned in
    `RepositoryDataConciergeTest` (`testAFailureThatIsNotTheDatabasesPropagates` and the tests after it).
  - After a lost connection (a worker that outlives `wait_timeout`, a server restart), the next
    query fails (logged, the failure value) and DBAL closes the connection; the query after that runs
    on a new connection, and `getClient()` returns its PDO (pinned on MySQL in
    `testGetClientFollowsTheConnectionAfterItIsLost`). NOT recovered: a PDO a caller kept from an
    earlier `getClient()`, and the session handler, which `handleSession()` built on the PDO of that
    moment (it is not rebuilt). `reconnectIfNeeded()` checks the connection and replaces it at once.
  - Otherwise return values are the old backend's, with these deliberate differences, ALL of them
    (pinned in `RepositoryDataConciergeTest` and `EntityQueryContract`):
    - DIFFERENCE (unanswerable queries): a query the old backend sent to MySQL as invalid SQL, or that MySQL rejected for
      its operand, matches nothing, so `countRecords()`/`countObjects()` give 0 where they gave false
      (no caller can tell: `Entity::count()`/`countFromX()` are declared `int`);
    - DIFFERENCE (not logged): queries the store recognises as unanswerable (the rejected queries above, and
      any query or write on a collection that does not exist) are not logged; the old backend logged
      MySQL's error for each. `RecordStore` does not tell the adapter why it found nothing, and its
      interface stays free of logging policy;
    - DIFFERENCE (refused saves): `saveRecord()`/`saveObject()` return the id only when the record was stored. A
      save the database refuses (another record's uuid, on a new record or on a re-save; an `_id`
      longer than the 36 characters of MySQL's `id` column; on MySQL and MariaDB a document nested
      deeper than their JSON functions accept, 100 levels and 31, ...) returns false and stores nothing (an
      update leaves the previous version, and the other record is intact). The old backend returned
      the id of a save it had rolled back when a metadata row failed, so `Entity::save()` reported
      success and fired `saved`/`updated` (and federation) for a record that was not stored; now it
      returns false and fires neither. The old backend on MySQL also overwrote the OTHER record when
      an existing record was re-saved with its uuid; that is refused now on every database;
    - DIFFERENCE (empty `access`/`entity_subtype` conditions): see the `Criteria` merge below;
    - DIFFERENCE (unloadable classes): `getObjects()` leaves out a record whose class cannot be loaded instead of
      returning `false` in its place (`getFromX()` already filtered those out; counts still include it);
    - DIFFERENCE (publish-status override): `getObjects()` hides unpublished entities by
      `EntityRepository`'s rule computed from the AccessContext; an entity class overriding
      `isHiddenByPublishStatus()` is no longer consulted (no core or bundled plugin class overrides it);
    - DIFFERENCE (string read group): a string `$readGroups` given to `getObjects()` is one read group;
      the old backend ran it into `foreach` and failed (found nothing);
    - DIFFERENCE (`created`): a record whose `created` is not a timestamp makes `saveRecord()` return
      false and log why (`RecordIndex` cannot index it); the old backend raised a `TypeError`;
    - DIFFERENCE (`getClient()`): it returns a `Pdo\Mysql` on MySQL and a `Pdo\Sqlite` on SQLite
      (subclasses of `PDO`), not a plain `PDO`; and after a lost connection it is the new connection's
      PDO (above), where the old backend's stayed dead until `reconnectIfNeeded()`;
    - DIFFERENCE (lost connection): record queries reconnect by themselves after the one that found
      the connection lost (above); the session handler does not;
    - DIFFERENCE (ignore-access): `getObjects()` reads the ignore-access flag once per call, when it
      builds the query; the old backend read it again for each row (the publish-status check).
    The tables of the schema migrations make these differences too, in what queries match (pinned by
    `RecordStoreContract`, `IndexedFieldsContract`, `SettingsRecordStoreContract` and, through `db()`,
    `RepositoryDataConciergeTest`):
    - DIFFERENCE (order of equal `created`): records with an equal `created` come back newest id first
      (`_id` descending), in pages too; the old backend left their order undefined;
    - DIFFERENCE (whole values): a document field's value is compared whole; the old backend compared
      only its first 255 bytes, so a longer value was found by its first 255 bytes and not by itself;
    - DIFFERENCE (nested values): an element of a list or object that is itself a list or object is
      not queryable; the old backend matched it against its JSON encoding. A field that holds a list
      or an object is still queryable by its scalar values (never its keys), as before;
    - DIFFERENCE (stored, not refused): four-byte characters (emoji) anywhere, and field names over
      64 characters, are stored and queryable; the old backend on MySQL refused such a save;
    - DIFFERENCE (identifier columns): on MySQL and MariaDB every text column of the schema compares
      with `utf8mb4_unicode_520_ci` (`MySqlDialect::COLLATION`), the collation the 1.x schema
      (2026111401) gave document values and the search text: it ignores case and accents, equates
      `ß` with `ss`, ignores trailing spaces, sorts `_` before the letters and gives every emoji a
      weight of its own (two values that differ in an emoji are different). What changed are the
      identifier columns `uuid`, `owner`, `type`, `publish_status` and `id`, which were `utf8`
      (utf8mb3) `general_ci` in 1.x: for example the uuids `…/straße` and `…/strasse` were two
      different values in 1.x and are the same value now (observed on MySQL 8.0 and MariaDB 10.6), so
      the second record is refused as a duplicate uuid. On SQLite text compares with NOCASE: ASCII
      case only (`ÉTÉ` is not `été`, `straße` is not `strasse`, accents and trailing spaces count);
    - DIFFERENCE (field names): a document field in a query is found by its exact name (`colour` does
      not find `Colour`); the old backend matched field names without regard to case;
    - DIFFERENCE (`site` rows): the `site` collection keeps exactly one row per record: `_id` is the
      primary key of every collection's table now. In `site`, whose `_id` was
      not unique in the old schema, a record re-saved with a new uuid keeps its one row (the old
      backend added a second row with the same `_id`), and one re-saved with another record's uuid is
      refused (the old backend overwrote that record's row);
    - DIFFERENCE (collection names): collection names ignore case on every database: a name is
      reduced to lower case, so `ENTITIES` is the collection `entities`; the old backend on MySQL
      (whose table names are case-sensitive on Linux) found no such collection;
    - (a rule of the store, as before) a type name with a leading backslash (`\IdnoPlugins\...`)
      matches nothing: types are compared as stored, never resolved from such a name;
    - DIFFERENCE (`created` literals): `created` is stored as a timestamp, and a datetime literal in
      a query is read in PHP's default time zone at the time of the query; the old backend compared it
      with the text formatted in the time zone of the save. A literal that is not a datetime makes the
      query match nothing on every database (the old backend's MySQL failed the query, which also
      matched nothing);
    - DIFFERENCE (floats): a float is compared as its JSON text: a float still finds itself, but the
      string `'0.3'` no longer finds `0.1 + 0.2` (stored as `0.30000000000000004`); on MySQL 8 (not
      MariaDB) a float that JSON writes in exponent form (`1.0e+25`) is not found by the same float,
      only by MySQL's own text of it (`9.999999999999999e24`);
    - DIFFERENCE (declared indexed fields): a field with a declared index (`#[IndexedField]`) matches
      scalar values only: a record whose field holds a list or an object is not found by a query on it
      (before its index exists it is; results are the same with and without the index only while every
      record holds single values in the field, which is what a declaration promises);
    - DIFFERENCE (`access`): the schema indexes `access` in every collection, so it too matches
      scalar values only (a record whose `access` is a list or an object is found by no query on
      `access`, so listings show it only to admins and with ignore-access), and on MySQL and MariaDB a record whose
      `access` is longer than 255 characters is refused (false), where the old backend stored it and
      compared its first 255 bytes;
    - DIFFERENCE (undeclared fields): a lookup on a document field that is NOT declared is served by
      no index (1.x had the metadata table for every field): it reads the documents of the matching
      types (measured at about 0.7 s per 100,000 entities on MySQL 8), so a field hot code looks up
      should be declared;
    - DIFFERENCE (settings): `config` holds one document (saving another `_id` replaces it), and a
      settings key ending in a space is refused (false, the previous document intact);
    - (unchanged, stated once) a whole float (`1.0`) reads back as the integer `1`, as before.
  - A search array `Criteria::fromLegacyArray()` rejects (an array operand, `'$not' => ['$in' =>
    []]`, a non-array `$in`, ...) matches nothing (`getRecords()` false, `getObjects()` `[]`, the counts
    false) and is logged as ONE warning naming the method and the query's shape: keys and operator
    names only, never values, control characters replaced, at most 200 bytes
    (`RepositoryDataConcierge::shape()`).
  - The schema catalog is read with ONE statement per request (`information_schema.columns` of the
    current database; `sqlite_master`), on the same connection: the schema gate reads it first, at
    boot, and the record store reuses it (the container's one `SchemaCatalog`); the old backend's
    `select * from versions` on every connection is gone. The gate adds ONE statement to every
    request on a migrated SQL site, whatever other tables it has (`legacy_` ones included):
    `SchemaMigrator::probe()`, `select version from idno_migrations union all select case when
    completed_at is null then '#incomplete' else '#complete' end from idno_upgrade_progress` (the
    second part only when the progress table exists). Only a database with the three 1.x `entities`
    tables and no complete upgrade on record costs more (the 1.x version is read), and so do the
    closed states that tell the owner to remove tables (the catalog read again once). Pinned by
    `UpgradeEndToEndTest::testTheHomepageStatementsArePinned` (the DBAL statements of a homepage
    request: the catalog, the probe, then the legacy application's, 13 on the fixture site, the same
    after a completed upgrade, with its `legacy_` tables deleted, with 1.x tables recreated, with
    another application's `legacy_config`).
  New code never calls `db()`: inject `EntityRepository` (entities) or `RecordStore` (records).
- Querying entities in new code: inject `Idno\Storage\EntityRepository` and query with an
  `EntityQuery` (class names, a `Criteria`, limit, offset, collection, read groups); never call
  `Idno::site()->db()` and never write SQL against the table names. `find()`, `findOneByUuid()`
  and `hydrate()` build legacy entities, so they need the legacy application (without it, hydration
  fails, is logged, and `find()` returns `[]`); only `count()` and the criteria it builds can be
  unit-tested without it, with `Idno\Storage\FixedAccessContext` (constructor-set user, admin flag,
  read groups, ignore-access; not a service) on an `InMemoryRecordStore`
  (`Tests/Unit/Storage/EntityRepositoryCriteriaTest`). The rules it applies are the old backend's
  `getObjects()` / `countObjects()` / `getObject()` (pinned by `EntityQueryContract` against both),
  with the two deliberate differences marked below:
  - types match the stored type exactly: a class matches the rows stored under its alias, its class
    name and the names it had before (`TypeMap::typesFor()`); a name with a leading backslash matches
    nothing, and a class never matches its subclasses; a leading `!` excludes; with one inclusion,
    exclusions are dropped;
  - unless the user is an admin, `access` must be one of the query's `readGroups` or, when it gives
    none, the user's (`PUBLIC`, `SITE` when logged on, the user's uuid, their access groups). Access
    goes by the `access` value only: an owner does not find their own entity in a group they are not
    a member of;
  - the `Criteria` is merged OVER those conditions: a top-level condition on `access` or
    `entity_subtype` REPLACES the repository's, so it can widen what a user reads. Pass one only
    when your code has already decided what the user may see. DIFFERENCE (empty conditions): a value that
    parses to no condition (`[]`, `['$in' => []]`, an operator with an empty operand) is no
    condition, so the viewer's read groups (or the types) stay; legacy drops the filter entirely;
  - `find()`: with ignore-access on there is no access condition; the page is cut in the database,
    then records whose class does not exist and unpublished entities the user may not see (only owner
    and admins see them) are dropped, so a page can come back short. The publish-status rule is
    computed from the AccessContext. DIFFERENCE (publish-status override): an entity class overriding
    `isHiddenByPublishStatus()` is not consulted, where legacy `getObjects()` consulted it. DIFFERENCE (unloadable classes): legacy `getObjects()` returns `false` in place of a record whose class does
    not exist; `find()` returns entities only;
  - `count()` applies the access condition but NOT the publish-status rule (drafts are counted), does
    NOT honour ignore-access, and counts records whose class does not exist (as `countObjects()`); it
    does honour `readGroups`;
  - `findOneByUuid()` is `getObject()`: the entity's own `canRead()` decides (it reads the legacy
    session, not the AccessContext, and ignores ignore-access); null where `getObject()` gives false;
  - `save()` / `delete()` only write to the store (`saveObject()` / `deleteRecord()`): events,
    permission checks and side effects stay in `Entity::save()` / `Entity::delete()`.
- The legacy services (`db`, `config`, `template`, `session`, `events`, `queue`, ...) are
  public container services built at runtime, when first fetched, by
  `Idno\Legacy\LegacyServiceFactory` (one method per `Idno::site()` accessor). `Idno::init()`
  fetches them in its original order, so `Idno::site()->db()` and
  `$container->get(\Idno\Core\DataConcierge::class)` are the same object.
- New code should have services injected rather than calling `Idno::site()`.
- The site's HTTP clients (`symfony/http-client`; FrameworkBundle's own `http_client` is switched off in
  `config/packages/framework.php`, so there is exactly one set). Two public services, built at runtime
  when first fetched, from the boot configuration through `Idno\Http\Client\HttpClientFactory`, so
  no configuration value is compiled into the container (pinned by
  `Tests/Kernel/HttpClientServicesTest`); neither needs the legacy application:
  - `idno.http_client`, autowired as `Symfony\Contracts\HttpClient\HttpClientInterface`: for every
    request to a URL the site chose itself (the site's own configuration, a fixed API).
  - `idno.http_client.remote`, autowired as an argument named `$remoteClient` of that type: for every
    request to a URL a REMOTE PARTY supplied (a webmention source, an ActivityPub actor or inbox, a
    Micropub media URL, a feed or page a user typed). It is the site client in `NoPrivateNetworkHttpClient`,
    which refuses a host that resolves to a loopback, private, link-local or reserved address, on the
    first request and on every redirect hop (a `TransportException`). Do not use the site client for
    such a URL, and do not add a second SSRF check by hand around it.
  - Both send `User-Agent: Idno https://idno.co`, fail after `HttpClientFactory::IDLE_TIMEOUT` (10 s)
    without receiving anything or `MAX_DURATION` (60 s) in all, follow at most `MAX_REDIRECTS` (5), and
    verify certificates unless the boot configuration's `disable_ssl_verify` is set. `proxy_string`,
    `proxy_type` and `disable_ssl_verify` are read from the BOOT configuration only (`Idno\Core\Config`
    drops them from the stored settings): a `proxy_string` without a scheme gets one from `proxy_type`
    (`socks4://`, `socks5://`, `socks5h://` for `socks5-hostname`, else `http://`). The transport is
    curl when the extension is loaded (`HttpClient::create()`). `HttpClientFactory::options()` is the
    pure mapping, unit-tested with arrays.
  - `Idno\Http\Client\BoundedBody::read($client, $response, $limit)` streams a body and cuts it at
    `$limit` bytes, cancelling the response once the limit is passed, whether or not the server declared
    a length; never more than one chunk beyond the limit is in memory.
  - In the test environment (`config/services_test.php`) both clients are wrapped in
    `Tests\Fixtures\LocalOnlyHttpClient`: a request goes out only to the site's own origin (host and
    port of the boot configuration's `url`, compared without the scheme; `localhost` and `127.0.0.1` are
    different hosts) or to a `host:port` listed in the environment variable `IDNO_TEST_HTTP_ALLOW`
    (comma-separated, read from the process's `$_SERVER` at each request, so it reaches the PHPUnit process and
    the subprocess servers a test starts, not the rig's web server); any other host throws a
    `TransportException` `No network in tests: <host>` (the host alone). So no test makes a real
    outbound request, and a test that needs another origin lists it. The wrapper adds
    `X-Idno-Test-Client: site` or `remote` to each request, and follows redirects itself (the inner client is sent `max_redirects` 0), checking every hop, so a redirect from an allowed origin to any other host is refused, so `/_test/echo` or a sink shows which client
    sent it. Only in the test wiring, the remote client's private-network refusal exempts the site's own
    origin (the rig's routes, on a loopback address, stand in for remote servers); an origin that only
    `IDNO_TEST_HTTP_ALLOW` lists still meets the refusal. The site's origin is the boot configuration's
    `url` in every process: the rig's web server and the PHPUnit process (`config.ini`: `http://127.0.0.1:8089/`),
    CI's servers and tests (`http://localhost/`), the console (the same `config.ini`), and a subprocess
    server a test starts on another port (its `IDNO_URL`). Neither the wrapper nor the exemption exists
    outside the test container: both are in `Tests/` and wired only in `config/services_test.php`.
- `Idno\Core\Webservice` (the legacy static client, ~40 call sites) sends its requests through those
  clients; no `curl_*` call is left in it. New code injects `HttpClientInterface` (`$remoteClient` for a
  URL a remote party supplied) instead of calling it. Pinned by `Tests/Kernel/WebserviceAdapterTest`
  (a `MockHttpClient` given through `Webservice::useClient()`) and, against the real routes,
  `Tests/Characterisation/WebserviceWireTest` and `OutboundFlowsTest`. What it does:
  - Every public static method keeps its name, parameters and defaults, and `send()` and its wrappers
    return `['header', 'content', 'response', 'effective_url', 'error']`. `safeSend($verb, $endpoint,
    $params = null, ?array $headers = null)` is `send()` on `idno.http_client.remote`, and `safeFileGetContents($url)` is `file_get_contents()` on
    it (the body, or false). The clients are
    fetched from the container at each call, never kept; `Webservice::useClient($client, $remote = null)`
    replaces them (a test's) and `useClient(null)` restores the container's. `webservice_exec()` and
    the cookie jar (the `cookie_jar` setting) are gone. `WebserviceFile::getCurlParameters()` still
    returns a `CURLFile`; `getDataPart()` is what the adapter uses.
  - `send()` never throws for a transport failure (a refused connection, a timeout, a certificate that
    does not verify, a host the remote client refuses): the result has `response` 0, empty `header` and
    `content`, `effective_url` the URL that was being requested and the failure's message in `error`;
    one error line is logged (`Webservice request failed: <scheme>://<host><path>: <message>`: no query,
    no credentials, control characters replaced, each part at most 200 bytes, never a header or a body).
    `error` is empty when a response was received and kept whole; a response refused or cut for its size has its
    status AND a non-empty `error`. The log line holds a fixed reason or the exception's class, never its message
    (which can quote the address), and the URL as scheme, host and path (`[unparseable address]` if it has none).
    No caller in the repository reads `$result['error']`.
  - Redirects are followed BY THE ADAPTER, one request per hop, each sent with `max_redirects` 0, so
    that `header` holds the header block of every hop in order, `effective_url` is the last URL, and the
    remote client checks every hop's address. Five are followed; a sixth is the failed result with the
    sixth response's status and URL, a non-empty `error` and empty `header` and `content`. On 301, 302
    and 303 a POST becomes a GET with no body and no Content-Type of its own, and a PUT or DELETE keeps
    its method and the caller's Content-Type but sends no body; on 307 and 308 the same method, body and
    Content-Type are sent again. `Authorization` and `Cookie` headers are not sent to another origin.
    No Referer is sent on a redirect. `file_get_contents_ex()` is `get()`; after too many redirects it
    returns the failed result and `file_get_contents()` returns false (neither throws).
  - Size limit: `webservice_max_download` (default 10 MB, read from the legacy config at call time). A
    response that DECLARES a `Content-Length` over it is refused without reading the body (`response` is
    its status, `error` non-empty, `header` and `content` empty, also for HEAD); a body with no declared
    length is read through `BoundedBody` and cut at the limit, with `error` non-empty and the cut `content`.
    Time limits and certificate verification come from the client, not per request.
  - Headers: a string key is a header name (`['Content-Type' => 'x']` is sent as `Content-Type: x`; it
    used to be dropped), an integer key is a full `Name: value` line, a line with no colon, and a header
    with an empty value (`Expect:`), are not sent. The `webservice:headers` event is asked as before and
    its headers merged after the caller's. A caller's Content-Type is used for every verb. With none of
    its own: POST is `application/x-www-form-urlencoded` for a string; PUT and DELETE claim a bare
    `multipart/form-data` when the request had no headers at all, else form-encoded; an array body is
    `multipart/form-data` with a boundary (`symfony/mime`'s `FormDataPart`; a `WebserviceFile` is a
    `DataPart`; nested arrays are `a[b]` fields). `put()` of an array sends only that body, `delete()`
    of an array sends the parameters in the query AND as a multipart body, `get()` and `head()` put them
    in the query. A body is built in memory (an upload is as large in memory as the file).
  - `getLastRequest()` is the request line and headers Idno sent for the last request, rebuilt (not curl's
    byte-exact copy), with the values of `Authorization`, `Proxy-Authorization`, `Cookie` and `Signature`
    replaced by `[redacted]`; `getLastResponse()` is the last body.
  - In the test environment the PHPUnit process and the rig's web server cannot reach a foreign host
    through `Webservice` (the clients are wrapped in `LocalOnlyHttpClient`): the default hub ping to
    `withknown.superfeedr.com` after a publish is refused inside the site and logged. A test that needs
    another origin lists it in `IDNO_TEST_HTTP_ALLOW`.
- The compiled container lives in `<cache base>/<env>/<build>-<key>` (cache base:
  `IDNO_CACHE_DIR`, else `var/cache`, else a private per-project temp dir; build number from
  `version.idno`). The key (`Kernel::getCacheDir()`) hashes the code fingerprint together with
  the project directory's real path, because the compiled container holds that absolute path
  (it reads that install's `config.ini`): two installs sharing one `IDNO_CACHE_DIR` never share
  a container, even with identical files. The fingerprint itself
  (`Idno\Kernel\CodeFingerprint`) does not depend on where the project is. The container is
  rebuilt automatically when any of these change: `version.idno`, `Idno/Kernel.php`, any PHP file under
  `config/` (recursively, except the generated `reference.php`), any PHP file under the
  directories in `CodeFingerprint::SERVICE_DIRECTORIES`, or the installed packages. When you
  add a directory that the container scans for services OR that `config/routes.php` imports
  attribute routes from, add it to `CodeFingerprint::SERVICE_DIRECTORIES`. In debug mode the
  route cache is also revalidated against its source files on each request. Deleting
  everything under `var/cache/` is always safe. Known limits:
  - detection uses file path, size and modification time, not contents, so an edit that
    keeps both (same size within the same second, or a deploy that normalises mtimes) is
    not detected;
  - a class in a scanned directory that extends or uses a class, interface or trait OUTSIDE
    the scanned directories is not rebuilt when only that outside file changes, so new code
    in scanned directories should not inherit constructor signatures from legacy classes;
  - the fingerprint is computed from the files on disk, but the code that compiles the
    container comes from opcache. On a server with `opcache.validate_timestamps=0` a request
    between replacing the files and restarting PHP can compile old definitions into the new
    fingerprint's directory, which is then never revalidated. Restart PHP (or reset opcache)
    immediately after replacing files, and if the site reports a container error after a
    deploy, delete everything under `var/cache/` (see `docs/install/upgrade.md`).

  If in doubt, delete `var/cache/`.
- PHPUnit and the test rig run the kernel with `APP_ENV=test` and `APP_DEBUG=1`; the
  default is `prod` without debug.
- Testing: in PHPUnit, `Tests/_bootstrap.php` (through `Idno/start.php`) has already
  booted the one kernel that owns the legacy application. To test a new controller
  in-process, either use `new \Idno\Kernel('test', true, bootLegacy: false)` and call
  `$kernel->handle(Request::create(...))` (fine as long as the controller does not need
  legacy services; a controller that takes `Psr\Log\LoggerInterface` does need them, since that
  logger is backed by the legacy application: construct it directly with a `Psr\Log\NullLogger`
  in a unit test instead, until the level filter moves into Monolog in a later phase), or test it black-box over HTTP. Do not boot a second kernel with
  legacy and expect its legacy services to be `Idno::site()`'s (see above). Legacy pages
  are tested black-box only, through the characterisation harness
  (`Tests/Characterisation/`, `$this->site()->request(...)` against the test rig).
- Logging goes through Monolog (`config/packages/monolog.php`), to PHP's error log
  (`error_log()`, as before Monolog; nothing is written under `var/`). Two handlers, neither
  of which registers a PHP error handler: `idno` writes each line of the `idno` channel
  verbatim. The site's log level and the line format (`Idno (<host>): <level> - <message>`)
  are still applied by `Idno\Core\Logging`, so `config/services.php` aliases
  `Psr\Log\LoggerInterface` to that service for application code. `symfony` writes every
  other channel (`request`, `console`, `php`, ...: Symfony's own services, which keep the
  `logger` / `monolog.logger.*` services they name) at `warning` and above, in Monolog's
  standard line format with channel and level; that includes the channel `upgrade`, on which
  `Idno\Upgrade\` code (the copier, the runner, the step endpoint) logs its failures, because it
  runs while the legacy logger does not exist. An ordinary legacy request logs nothing
  through it; an exception Symfony handles (before routing, or from a new-style controller)
  is one `request.CRITICAL` line (`request.ERROR` for an HTTP exception below 500), and a
  console command that fails is one `console.CRITICAL` line (on stderr when PHP's
  `error_log` setting is unset). A test that makes Symfony handle an exception should point
  `error_log` at a temporary file (`ini_set()`) to keep it out of the test output, as
  `Tests/Kernel/KernelExceptionTest` does. See Code Style.

### Key design patterns

- **Entity system**: All content inherits from `Idno\Common\Entity` (→ `BaseObject`).
  Entities are stored as JSON text, one row each, in `idno_<collection>`
  (MySQL, MariaDB, SQLite; see "Database"). Key
  methods: `save()`, `delete()`, `getByUUID()`, `getActivityStreamsObjectType()`,
  `getFormattedContent()`.
- **Plugin system**: Plugins extend `Idno\Common\Plugin`, register legacy routes via
  `registerPages()`, and content types via `registerContentTypes()`. They hook
  into events (`saved`, `updated`, `deleted`, `syndicate`, etc.) using the
  Symfony EventDispatcher (wrapped by `Idno\Core\EventDispatcher`).
- **Template system**: templates are `.tpl.php` files (PHP), found in `templates/<type>/`
  and in plugin and theme `templates/` directories, and rendered by the Bonita engine
  (`Idno\Core\Bonita`, through `Idno\Core\DefaultTemplate`). The site's template service
  is `Idno\Core\HybridTwigTemplate`, which could also render Twig templates, but there are
  none: Twig is not in use. Templates are extended via `extendTemplate()` to inject plugin
  UI into shell chrome, admin menus, etc.
- **Async queue**: Events can be processed asynchronously via
  `AsynchronousQueue`. The queue worker runs as a CLI service
  (`ConsolePlugins/EventQueueService`) polling for pending
  `AsynchronousQueuedEvent` entities. **Required for ActivityPub.**

### Database

- **MongoDB** is no longer a database Idno runs on (the backend, `Idno\Data\Mongo`, and its GridFS file
  system were removed). A site whose `database` is `mongo`, `mongodb` or `Idno\Data\Mongo` is held closed by the schema
  gate (`SchemaStatus::MongoRetired`) with a message that names `php bin/console idno:import:mongo`,
  which moves it to MySQL, MariaDB or SQLite (see "Importing a MongoDB site"). Both installers refuse
  `mongo` and `mongodb` (`Installer::databaseType()` throws a `ConfigurationException` saying MongoDB
  is no longer supported and the choices are MySQL or SQLite); any other unknown value still means
  MySQL. A `database` value naming a backend class a plugin supplies (`other`) boots as before.
- **MySQL 8.0 and MariaDB 10.6** (or later) — store each document as JSON text in the tables of the
  schema migrations (below), through `Idno\Storage\JsonRecordStore` and `SettingsRecordStore` on the
  DBAL connection; `db()` is `Idno\Legacy\RepositoryDataConcierge` over them (see the `db()` bullet
  under "Kernel and services"). The hand-written SQL backend that preceded it has been removed;
  `docs/install/upgrade.md` tells plugin authors what replaces it and what behaves differently.
- **SQLite** — the same stores with `SqliteDialect`; `database = sqlite` (with `dbname` the file's
  path) gives `db()` the same adapter. A supported production database for a small site on one server
  with a local disk (the default test rig runs on it; both installers can set up an SQLite site).
  See the SQLite bullet under "Kernel and services".
- The schema is created by the upgrade (see "The schema gate and the upgrade" below): the first
  page load of a new installation, or of a site whose files were replaced, shows the upgrade page,
  whose steps create the tables (and copy a 1.x database); `php bin/console idno:upgrade` does the
  same in one run. The 1.x tables (`entities`, `entities_metadata`, `entities_search`, `config`,
  `session`, `versions`, ...) are no longer read or written by the site: `Idno\Upgrade\LegacyDataCopier`
  copies them into the new tables (see "Copying a 1.x database" below) and renames them
  `legacy_<name>`, which stay until the site's admin deletes them (see "The legacy tables" below).
- Configured via `config.ini` (`database` / `dbname` / `dbhost` / etc.)

#### The schema (migrations)

The site's tables are created by schema migrations, which the upgrade runs (the upgrade page's
steps or `idno:upgrade`; the rig and CI run the command before the first request).

- `doctrine/migrations` is used as a library only (no bundle, no console command, no configuration
  file): `Idno\Storage\SchemaMigrator` (a container service; it takes only the DBAL `Connection` and
  queries nothing when constructed; the schema gate fetches it at boot on an SQL site, for
  `probe()`) builds a
  `DependencyFactory` on that connection with the migrations namespace `Idno\Storage\Migrations`,
  the directory `Idno/Storage/Migrations/` and the metadata table `idno_migrations` (created by
  `migrate()`). `pending()` lists the versions not yet executed (`'20261115000000'`, the numeric
  part of the class name; asking creates nothing), `probe($progressTable)` is what the schema gate
  asks: ONE statement (`select version from idno_migrations`, compared with the `Version*.php` files
  of `Idno/Storage/Migrations/`, `union all` one marker per row of the upgrade's progress table when
  it is given) saying whether a migration is pending and whether an upgrade is on record, complete or
  not; the table must exist: the gate asks only when the catalog lists it; `hasPending()` is its
  `pending` alone (`pending() !== []`), `migrate()` executes them and returns the versions it executed (a no-op, `[]`, when none is
  pending), `latest()` is the newest executed version, `'0'` when none. It writes no output.
- Migration classes (`Idno/Storage/Migrations/Version<YYYYMMDDhhmmss>.php`) are not services
  (excluded in `config/services.php`). Each holds literal SQL per platform (MySQL/MariaDB and
  SQLite; any other platform aborts) and never changes once released: a schema change is a new
  migration. (2.0 is not released yet. The baseline was last edited in place to add `ix_access`,
  below; a development database created from an earlier build must be recreated, `composer
  test:down` then `test:up...` for the rig, or it keeps its slower index on `access`.) On MySQL and MariaDB a migration is not transactional (DDL commits implicitly), so a
  run can be interrupted after some statements with no version recorded: every statement must be
  safe to repeat (`CREATE TABLE IF NOT EXISTS`, and on SQLite `CREATE INDEX IF NOT EXISTS`), so that
  the next run completes the schema (pinned by `testAnInterruptedBaselineCanBeRunAgain` on SQLite
  and MySQL).
- A migration that must ALTER a table that already holds rows (a new column or index on
  `idno_entities`, say) has no recipe yet, and needs a design of its own before it is written. Two
  constraints are known. MySQL has no `ADD COLUMN IF NOT EXISTS` or `ADD INDEX IF NOT EXISTS`
  (MariaDB has both), so to be safe to repeat such a migration must read the catalog first
  (`information_schema.columns` / `.statistics`, or `SchemaCatalog`) and skip what exists, as
  `IndexedFields` does for the declared indexes. And an ALTER of a large table can take far longer
  than a web step's budget (`UpgradeRunner::STEP_SECONDS`, 5 s) and longer than PHP's
  `max_execution_time`; the migrations run inside one step, so such a migration cannot simply be
  added to the list (the declared indexes are created one per step for this reason).
- The baseline, `Version20261115000000` (irreversible), creates: `idno_entities`, `idno_reader`,
  `idno_site` (one row per document: `id`, `uuid` (unique), `type`, `owner`, `created` (epoch
  seconds, `BIGINT`), `publish_status`, `data` (the document's JSON text, `LONGTEXT` `utf8mb4_bin`),
  `search` (full-text indexed on MySQL/MariaDB), `ix_access` (below); keys `type_created`,
  `owner_created`, `created`, `ix_access`);
  `idno_settings` (`name` and `value` `utf8mb4_bin`, `position` INT: one row per settings key, in
  order; served as the collection `config` by `SettingsRecordStore`, below); `idno_sessions` (the `session` table's columns, `session_id` `utf8mb4_bin`);
  `idno_upgrade_progress` and `idno_upgrade_rejects` (the upgrade's bookkeeping: see "Copying a 1.x
  database"; the rejects are indexed on `(collection, legacy_uuid)`). On MySQL/MariaDB
  every table is InnoDB, `utf8mb4`, and every text column names its collation:
  `utf8mb4_unicode_520_ci` (`MySqlDialect::COLLATION`) except the `utf8mb4_bin` ones above.
  The second migration, `Version20261115000001`, has no SQL: it is the indexed-fields marker (see
  "The schema gate and the upgrade"); `SchemaMigrator::migrate(exceptLast: true)` leaves the newest
  pending migration for the upgrade to record once the declared indexes exist. The one exception is `idno_migrations`,
  which doctrine/migrations creates itself with the database's default charset and collation; it
  holds only migration class names and timings. On SQLite the same tables, with
  `COLLATE NOCASE` on `id`, `uuid`, `type`, `owner` and `publish_status`, `INTEGER` for `created`,
  index names prefixed with the table's (`idno_entities_type_created`), no full-text index, and no
  custom collation or function, so the file opens with standard SQLite tools.
- `access` is indexed by the baseline itself, in all three collection tables, because every entity
  query filters on it (`EntityRepository` adds `access in (<read groups>)` for everyone but an
  admin). On MySQL and MariaDB: `ix_access`, a STORED generated column, `VARCHAR(255)`
  `utf8mb4_unicode_520_ci`, whose value is the indexed value of the document's `access` (the same
  rule as a declared indexed field's: the scalar's query value, NULL when the field is absent or
  holds a list or an object; the expression is written out in the migration, with JSON_VALUE() on
  MariaDB), and the index `ix_access (type, ix_access)` on the whole column. On SQLite: the index
  `idno_<collection>_ix_access` on `type` and the same value (`COLLATE NOCASE`). Both carry the
  names a declared indexed field's column and index have, so `SchemaCatalog::indexedFields()` lists
  `access` for every collection from the first migration on and `JsonRecordStore` compares
  `t.ix_access` (the indexed expression on SQLite) with no code of its own for it; no class
  declares `access`, and a class that does creates nothing and reports no conflict. Why this shape
  and not a declared index (a VIRTUAL `LONGTEXT` column with a 255-character prefix index): a
  prefix index cannot answer a query by itself, so the count every listing page makes (types and
  read groups) read every matching row and parsed its document; the whole value with the type in
  one index answers it from the index alone, and a STORED value is read from the row, without
  parsing JSON, when the rows come from another index (an owner's posts, a deep page). The type
  is the index's first column on purpose: with `access` first, MariaDB 10.6 chose this index and
  a filesort for every listing page from about the fiftieth on (0.27 s at 100,000 entities, where
  reading `created` backwards takes a few milliseconds); do not reorder it, and do not add a second
  index that starts with `ix_access` (the index is named `ix_access` so that a class declaring
  `access` finds it and creates none). Measured at 100,000 entities, before and after: a visitor's
  homepage count 0.97 s to 0.015 s on MySQL 8 and 0.62 s to 0.010 s on MariaDB 10.6; one owner's
  count 0.23 s to 0.07 s and 0.61 s to 0.06 s; page 500 of the listing 1.05 s to 0.005 s and
  0.040 s to 0.009 s. Pinned by
  `SchemaMigratorMySqlTest` / `SchemaMigratorTest` (the shape),
  `IndexedFieldsContract::testTheSchemasAccessIndexMatchesAsADeclaredFieldDoes` (every operator
  matches as on a declared field holding the same values, on all three engines) and
  `IndexedFieldsMySqlTest::testACountByTypesAndAccessIsAnsweredFromTheIndex` (EXPLAIN: the key
  `ix_access`, `Using index`). The column holds 255 characters, as `uuid` and `owner` do (an
  access value is `PUBLIC`, `SITE` or the uuid of a user or an access group): on MySQL and
  MariaDB a record whose `access` is longer is refused data (error 1406: `save()` returns false,
  the copy of a 1.x database makes the row a reject), never cut; SQLite enforces no lengths and
  stores it. That relies on the server's strict `sql_mode` (the default of MySQL 8 and MariaDB
  10.6), as the lengths of `id`, `uuid` and `owner` do.
- `Idno\Storage\SchemaCatalog` (a container service on the `Connection`; queries nothing when
  constructed; the schema gate reads it at boot on an SQL site, and the record store reuses what it
  read): `tables()` (lower case, sorted; SQLite's internal tables left out), `hasTable()`
  (ignores case), `indexedFields($collection)` (the fields with an index on `idno_<collection>`:
  on MySQL/MariaDB a generated column `ix_<field>`, on SQLite an index `idno_<collection>_ix_<field>`;
  field names `[a-z0-9_]{1,48}`, returned without the prefix). It reads everything with ONE
  statement at the first question (`information_schema.columns` of the current database;
  `sqlite_master`) and keeps it until `forget()`: call that after DDL.
- `Idno\Storage\JsonRecordStore` is the `RecordStore` on these tables (see "The JSON record store"
  below); the container's `RecordStore` is a `SettingsRecordStore` over one.
- Tests: `Tests\Storage\PrivateDatabase` makes databases of a test's own, migrated unless
  `migrate: false` (an empty database). `sqliteMemory()` (an in-memory database with
  `SqliteConnectionSetup`) and `sqliteFile($path)` (a database file at a path in a temporary
  directory the test makes and removes) need nothing but PHP and work in the unit tier;
  `mysql($purpose)` creates `<site dbname>_<purpose>` on the rig's MySQL or
  MariaDB server (dropping a leftover first; the site's dbname must match `^[A-Za-z0-9_]+$`) and
  returns a new connection to it (never the site's), dropping it again if the migrations fail, and
  skipping the test on an SQLite site; `drop()` removes either (call it in `tearDown()`;
  it refuses to drop the site's own database). `recordStore($connection)` builds the store the site
  builds on such a database, `dialect($connection)` the dialect of its platform. A test never writes
  to the rig site's tables directly. `Tests/Unit/Storage/SchemaMigratorTest` (SQLite) and
  `Tests/Storage/SchemaMigratorMySqlTest` (MySQL and MariaDB) pin the baseline and the catalog.

#### The schema gate and the upgrade

The legacy application must never boot on a database whose schema is not current (an empty one, a
pending migration, a 1.x database, a copy not complete). `Idno\Kernel::boot()` asks
`Idno\Upgrade\SchemaGate` first (after the "not installed" check), in a kernel that would boot the
legacy application; the gate keeps the status it computed for the life of its container (one per
request or console run). Everything in `Idno\Upgrade\` runs while the legacy application is not
booted: no `Idno::site()`, no `Idno\Legacy\`, no `Psr\Log\LoggerInterface` by its plain name (the
runner, the copier and the step endpoint log failures on the Monolog channel `upgrade`, wired by
name in `config/services.php`).

- `SchemaState::status()` (computed afresh at each call; the closures it is built with fetch the
  connection, the `SchemaCatalog`, the `SchemaMigrator` and the `LegacyDataCopier` only when needed),
  a `SchemaStatus`, in this order: `MongoRetired` when `ConnectionParameters::driverFamily()` is
  `mongo` (the `database` setting is `mongo`, `mongodb` or the class name `Idno\Data\Mongo`: Idno no longer runs on MongoDB);
  `NotApplicable` when it is neither that nor `mysql` nor `sqlite` (a backend class a plugin
  supplies); nothing is fetched and nothing connects for either;
  `Unreachable` when CONNECTING fails (`Connection::getServerVersion()`, which connects and reads
  the version the driver reports, without a statement, throws while the connection is not
  connected; a failure after connecting is thrown, never `Unreachable`); `UnsupportedServer` on MySQL below 8.0 or
  MariaDB below 10.6 (`ServerVersion::refusal()`, which reads both `10.6.28-MariaDB-…` and the
  `5.5.5-` prefixed form; a version it cannot read is not refused; SQLite's floor is the connection
  setup's); then the catalog's tables (its one statement, which the record store reuses); on a
  migrated database the probe (`SchemaMigrator::probe()`, the gate's one added statement: pending
  migrations, and whether an upgrade is on record, complete or not); on MySQL/MariaDB only, the 1.x
  part (below); then `Empty` without `idno_migrations`, `PendingMigrations` when the probe says so,
  else `Current`. An SQLite site never asks
  about 1.x tables. A failure after connecting (the catalog cannot be read) is thrown: the gate never
  opens on a database it could not check.
- The 1.x rule, in one place (`SchemaState::status()`, with `LegacyDataCopier::EVIDENCE_TABLES`,
  `isOneXDatabase()`): an upgrade on record and not complete (the probe found an incomplete progress
  row) → `Legacy`, always, whatever the tables look like: a 1.x table dropped by hand in the copy
  phase, one or every `legacy_` table dropped in the verify phase, all keep the gate closed, and the
  copier's impossible-state message names what is missing (for a document collection it requires
  `<c>`, `<c>_metadata` and `<c>_search` under the name the phase reads). Otherwise a database holds
  a 1.x Idno only when `entities`, `entities_metadata` AND `entities_search` all exist and no complete
  upgrade is on record: `Legacy`, or `LegacyTooOld` when `legacySchemaVersion()` is below 2020120301
  (Idno 1.6.4) or cannot be read (a `versions` table of another shape is "no version", never an
  exception). Everything else is not a 1.x database and asks nothing: a complete upgrade's `legacy_`
  tables, the same tables deleted by the admin, 1.x tables recreated after completion other than all
  three `entities` tables (an old process booting 1.x code after the rename runs every dated 1.x
  upgrade file and so re-creates `reader`, `versions`, `entities_search`, `entities_metadata`, `site`,
  `site_metadata` and `site_search`, but never `entities`; nothing reads them;
  `LegacyDataCopier::reappearedTables()` lists them, read-only, for the admin), another
  application's `entities`, `versions`, `config`,
  `session`, `site`, `reader`, `legacy_config`, .... One more case is closed: the three renamed tables
  `legacy_entities`, `legacy_entities_metadata` and `legacy_entities_search` with NO progress row at
  all (an operator dropped the new tables to start again without renaming the 1.x ones back; also on
  an empty database) → `RenamedWithoutRecord`: no step migrates, reconciles or copies anything (not
  even the baseline), the page and `idno:upgrade` (exit 1) say `PublicMessage::renamedWithoutRecord()`
  (rename them back to upgrade that site, or remove them to set up a new one), so an empty site is
  never set up beside the content. And one more: a COMPLETE upgrade on record and `entities`,
  `entities_metadata` AND `entities_search` present again (`LegacyDataCopier::isOneXBesideCompletedUpgrade()`,
  the ONE predicate, which `LegacyTables` also uses) → `OneXBesideCompletedUpgrade`. A booting 1.x
  process re-creates two of those three (`entities_metadata`, `entities_search`) but never `entities`,
  so all three mean an undo or a restored backup: opening the site on the earlier copy would silently
  drop what was written to 1.x since. That is why the rule needs ALL THREE: never loosen it to two
  (the predicate's docblock lists what an old process creates, file by file). It is handled exactly as
  `RenamedWithoutRecord` in every entry point (the page with no script and no form, the plain text,
  the step's JSON `error`, the runner's `step()`/`run()` returning before any lock, migration,
  reconcile or copy, `idno:upgrade` and `--status` exit 1, `Idno/start.php` and the console's closed
  line), with `PublicMessage::oneXBesideCompletedUpgrade()` (nothing was changed; to upgrade the 1.x
  site again remove the `idno_` tables first; to keep the upgraded site remove or rename the 1.x
  tables). The information is free: the catalog and the probe. Pinned in
  `SchemaStateTest` (`testAnUpgradeOnRecordAndNotCompleteIsAlwaysClosed`,
  `testACompleteUpgradeIsOpenWhateverItsLegacyTables`, `testForeignTablesNeverCloseTheGate`,
  `testTheOneXEntitiesTablesBesideACompletedUpgradeCloseTheGate`) and
  `UpgradeEndToEndTest`. The copier's own idea of "there is a 1.x database here"
  (`UpgradeProgress::$noLegacyDatabase`) uses the same evidence.
- The table list is read before the probe, and the catalog keeps it for the request, so a list
  read before another process's step renamed the 1.x tables can disagree with a probe that already
  says complete. Before `SchemaState::status()` reports `OneXBesideCompletedUpgrade` or
  `RenamedWithoutRecord` on a migrated database (the statuses that tell the owner to remove tables),
  it forgets the catalog, reads it again (one more statement, only then) and decides again with the
  same probe (pinned by
  `SchemaStateTest::testAStaleTableListIsReadAgainBeforeTellingTheOwnerToRemoveTables`).
- What each status means is decided in ONE place, by exhaustive `match`es (no `default`): on
  `SchemaStatus`, `closesTheGate()` (closed for `Empty`, `PendingMigrations`, `Legacy`,
  `LegacyTooOld`, `RenamedWithoutRecord`, `OneXBesideCompletedUpgrade`, `MongoRetired` and
  `UnsupportedServer`; open for `Current`, `Unreachable` and `NotApplicable`), `advancesBySteps()` (`Empty`,
  `PendingMigrations`, `Legacy`: the page has its script and form, the runner works) and
  `refusesTheUpgrade()` (`UnsupportedServer`, `LegacyTooOld`, `RenamedWithoutRecord`,
  `OneXBesideCompletedUpgrade`, `MongoRetired`: nothing is done until a person acts; no script and no form, the
  plain text `Idno cannot start: <why>`, `idno:upgrade` and `--status` exit 1); and the public
  message of a status is `PublicMessage::forStatus($status, $serverRefusal)` (the five refused states
  and `Unreachable`; none for the others). The page, the plain text, the runner and the command all
  use them; a new status must be decided there, and `Tests/Unit/Upgrade/SchemaStatusTest` lists every
  case, so adding one without deciding it fails.
- `Unreachable` is final for the request (and the process): `Idno\Storage\Platform\ConnectFailureIsFinal`,
  a DBAL middleware `BootConfigConnectionFactory` adds last to the site's connection, makes every
  later connect attempt of a connection whose FIRST attempt failed fail with that same failure,
  without a second attempt. So the legacy application, which boots on an unreachable database to
  show its own database error page (or redirect to `forward_on_empty`, or print it on the console)
  exactly as before, can never boot on a database the gate did not check because a second attempt
  succeeded ("Too many connections" during an upgrade), and a host that times out is waited for
  once. A connection that has once succeeded reconnects as before (a lost connection). For an upgrade
  step (`UpgradePage::isStepRequest()`, noted by `Kernel::handle()` before it boots) the kernel does
  not boot the legacy application while the database is unreachable, and the step answers `503`,
  JSON status `working`, `PublicMessage::unreachable()`, `Retry-After: 30`: never `current`. Pinned by
  `Tests/Unit/Storage/ConnectFailureIsFinalTest` and, over HTTP, by
  `UpgradeEndToEndTest::testAConnectionRefusedOnceNeverBootsTheSiteOnADatabaseThatIsNotCurrent` (empty,
  1.x and mid-copy databases; nothing written) and `testADownDatabaseIsConnectedToOncePerRequest`
  (exactly one attempt).
- With the gate closed: web requests get `UpgradeGateListener`'s answer (a `GET`/`HEAD` whose
  `Accept` includes `text/html`: the upgrade page, status 503, a `Content-Security-Policy` with a
  per-response nonce that allows only the page's own inline script and style, its requests to its
  own origin and its form's post; everything else: 503 `text/plain`, `Idno is upgrading its
  database.`, or for `LegacyTooOld`, `UnsupportedServer`, `RenamedWithoutRecord`,
  `OneXBesideCompletedUpgrade` and `MongoRetired` `Idno cannot start: <why>`; both with
  `Retry-After: 30` and `Cache-Control: no-store`; a `HEAD` gets the headers only), except a request
  the router will match to the step route: `UpgradePage::isStepRequest()`, a POST both as sent
  (`getRealMethod()`) and as Symfony reads it (`getMethod()`, which honours `X-HTTP-Method-Override`),
  to exactly `/_upgrade/step` (an override Symfony refuses, a trailing slash, any other method: the
  503). So no request reaches the router's legacy catch-all while the legacy application is not
  booted. `Idno/start.php` exits 1 with its line; the console runs without the legacy commands (see
  "Entry points"). The listener takes no logger (it is built for the first request event) and acts
  only when the gate was asked, so it does nothing in a `bootLegacy: false` kernel. The step URL
  comes from the request's base path (`UpgradePage::basePath()`: a site in a subdirectory posts
  `/blog/_upgrade/step`; a base path that starts with `//` or holds a backslash or a control character
  is replaced by the root), never from stored configuration.
- Errors shown to anyone are `Idno\Upgrade\PublicMessage`s only: the page and the step's JSON are
  served to anonymous visitors. A PublicMessage can only be made by its named constructors (fixed
  templates whose parameters are table names of `LEGACY_TABLES`, plain or `legacy_`, the fixed
  `idno_` names of `LegacyDataCopier::MUST_BE_EMPTY`, counts and version numbers). A failure that carries anything else (a record's uuid, document data, a driver or
  exception message, a file path, a connection detail) is shown as `PublicMessage::stopped()` ("The
  upgrade stopped. The details are in the server's error log, and php bin/console idno:upgrade
  --status shows them."); its detail is logged on the `upgrade` channel, stored apart (the copier's
  `idno_upgrade_progress.error_detail`, `UpgradeProgress::$errorDetail`, the runner's result
  `detail`) and shown by `idno:upgrade` only (a `Detail:` line). Pinned by
  `UpgradeEndToEndTest::testAFailuresDetailIsNeverPublic` (a verification mismatch, a driver error).
- `UpgradePage` (a static helper): one self-contained document (inline style and script with the
  nonce, no external request of any kind, no legacy template): one `h1`, a `role="status"`
  `aria-live` region, light and dark colours (`prefers-color-scheme`), fits 320 px; what is
  happening, that the page can be closed and opened again, that old Idno processes (a queue or cron
  service started before the files were replaced) must be stopped first because what they write is
  not carried over, that the old tables are kept (renamed `legacy_<name>`) and nothing in them is
  changed, and where to look if it stops (the stored public error is shown). Its script POSTs the step
  URL until `current` and reloads; on `error`, `too_old` or `unsupported` it stops and shows the
  message; on a network failure, a 503 answer or an answer that is not JSON it retries after 2 s,
  doubling to 60 s (or the answer's `Retry-After`, at most 60 s, when that is longer); after
  `UpgradePage::MAX_ANSWERS_NOT_JSON` (5) answers in a row that are not JSON (a PHP fatal error
  during a step returns an HTML page) it stops with `PublicMessage::stopped()`; a `working` answer's
  message (the rename waiting for the 1.x tables) is shown once `UpgradePage::NOTICE_AFTER` (3)
  answers in a row have carried it; never more than one request a second. The script's constants and
  messages are pinned by `UpgradePageTest::testTheScriptsLimitsAndMessages`; its behaviour in a
  browser is not run by the test suite. A `<noscript>` form posts `?html=1`. `LegacyTooOld`,
  `UnsupportedServer`, `RenamedWithoutRecord`, `OneXBesideCompletedUpgrade` and `MongoRetired` have no
  script and no form (the MongoDB page names `php bin/console idno:import:mongo`). Everything from the database or the request is
  escaped (HTML in the markup, JSON with HTML characters escaped in the script, `textContent` only).
  The 1.6.4 sentence is `PublicMessage::tooOld()` everywhere; the progress wording is
  `UpgradeProgress::countText()`/`line()` (`entities: 1500 of 12000 copied, 3 not carried over`) in the
  page, the runner and the command (the page's script repeats it).
- The indexed-fields marker. The newest pending migration is recorded only once the declared indexed
  fields exist: on a new or 1.x database that is `Version20261115000001`, a migration with no SQL that
  stands for "the declared indexed fields have been reconciled". The runner, whenever migrations are
  pending, runs every pending migration but the newest (`SchemaMigrator::migrate(exceptLast: true)`),
  reconciles the indexed fields (`IndexedFields::reconcileDirectoryUntil()`), and runs the newest only
  when a reconcile pass finds nothing left to create. A step killed anywhere before that (a
  `max_execution_time` kill in the middle of building indexes) leaves a migration pending, so the gate
  stays closed and the next step carries on. The marker concerns the CORE declarations (classes under
  `Idno/`) only: only a core reconcile failure holds it and keeps the gate closed, and a new CORE
  declaration ships with a new, empty migration (its own version): that is what makes existing sites
  reconcile it. Plugin declarations (bundled plugins included) never hold the marker and never close
  the site: web steps never read `IdnoPlugins/` (on a current site a web step never reconciles at
  all); `idno:upgrade` reconciles core first (a failure stops it), then the copy, and LAST of all,
  once the schema is current and any copy is complete, each enabled plugin on its own (a failure is a
  warning, the plugin is skipped). Last, so that the settings hold the site's plugin list (copied from
  1.x) and a plugin's unique index can never turn a copied 1.x row into a reject.
  `Idno\Upgrade\EnabledPlugins` decides which plugins are enabled without the legacy application, by
  `Idno\Core\Config`/`Plugins`' rule exactly: the boot configuration merged over the stored settings
  with `array_replace_recursive()` (a boot `plugins` list replaces the stored one index by index; an
  empty boot list leaves the stored one; the same for `prerequisiteplugins`; `alwaysplugins` and
  `antiplugins` from the boot configuration only), prerequisites first, then plugins plus
  `alwaysplugins`, less `antiplugins`. The stored lists are rows of `idno_settings`, none when the table
  or row is not there; any other database error is a warning line for the plugin phase (no plugin
  indexes), never silently "no plugins".
- `UpgradeRunner::step($deadline)` (a public service, built when first fetched; the web step): the
  status (`UnsupportedServer`, `LegacyTooOld`, `RenamedWithoutRecord`, `OneXBesideCompletedUpgrade`,
  `MongoRetired`, `Unreachable` stop at once: `unsupported`, `too_old`, `error`, `error`, `error`,
  `error`, with
  `PublicMessage::forStatus()`; `Current` and `NotApplicable` are `current`, nothing done, no lock;
  one exhaustive `match`, `answer()`, used before and after the work); the `UpgradeLock`
  without waiting (not got: `working` with `locked`, nothing done; the copy's progress when it can be
  read, else none, never an error), held around everything that follows so that two processes never
  migrate or create indexes at once (`LegacyDataCopier::step()` works under a lock its caller holds
  and leaves it held); the pending migrations and the indexes as above, bounded: the deadline is
  checked after each index created (one index, its one or two DDL statements, is the unit: with a
  deadline already past a step creates exactly one index and reports `working`); for a 1.x database,
  once nothing is pending and the deadline has not passed (or the step did nothing else), one copier
  step until the deadline; then the status again: `current`, `working`, or `error`. A copier step
  whose rename waited for the 1.x tables and gave up (its progress carries the notice
  `PublicMessage::tablesInUse()`, see "Copying a 1.x database") is `working` with that notice as the
  message: never an error, nothing stored. An error the copier returns is never dropped, and `LegacyDataCopier::progress()` itself reports an impossible
  state the tables alone show (one no step could store: no progress row yet). It never throws: an
  exception is logged on `upgrade` (the copier logs its own) and reported as `error` with
  `PublicMessage::stopped()` and the private `detail`. The result is `array{status, progress:
  ?UpgradeProgress, message: ?string (public), detail: ?string (private), locked: bool}`.
- `UpgradeRunner::run()` (`idno:upgrade`; not bounded): steps until the status is not `working`; it
  re-checks the core declared indexes in its first step that gets the lock (a current database too),
  and, once the schema is current and any copy complete, reconciles the enabled plugins as its last
  part, under the lock (a plugin phase that cannot get the lock within the wait leaves the result
  `current`, with a warning). While another
  process holds the lock before that it prints `Waiting: another process is upgrading the database (…)` once and
  tries again every half second, and gives up after 60 seconds (`PublicMessage::lockHeld()`, exit 1).
  It also gives up after `STALLED_STEPS` (5) steps in a row that changed nothing (no count, cursor or
  phase moved, no line reported: no migration or index), whatever the status says
  (`PublicMessage::noProgress()`, exit 1). Steps whose rename waited for the 1.x tables are not
  counted as stalled: it prints `Waiting: <the notice>` once, steps again, and gives up after the same
  60 seconds with the notice as the error (exit 1; nothing is stored, and the next run carries on).
- `UpgradeController` (`POST /_upgrade/step`): see "Adding a route" for its safety conditions. Its
  JSON: `status` (`current`, `working`, `too_old`, `unsupported`, `error`), `message`, `phase`,
  `collections` (an object, `{}` when empty; per collection `source`, `copied`, `rejected`, `done`)
  and `error` (the copier's public error); a `working` answer's `message` is the notice of a rename
  that waited (or, with 503, the unreachable message); HTTP 500 for `error`, 503 for an unreachable database,
  200 otherwise; never a document, a rejected row or private detail. Its time budget is
  `UpgradeRunner::STEP_SECONDS` (5); in the test environment only, `config/services_test.php` lets
  `IDNO_TEST_UPGRADE_STEP_SECONDS` shorten it (no request can).
- After a web install the browser is sent to `begin/register`: that request gets the upgrade page
  (`Empty`), the steps set the database up, and the reload continues to registration.
- Test hooks of the test environment (`config/services_test.php`, inert unless their environment
  variable is set): `IDNO_TEST_UPGRADE_STEP_SECONDS` (above) and `Tests\Fixtures\FailFirstConnection`
  (a `doctrine.middleware`: with `IDNO_TEST_FAIL_FIRST_CONNECTION=1` the first connection attempt
  fails, as a server refusing one connection would).
- Tests: `Tests/Unit/Upgrade/` (`SchemaStateTest` with the evidence rule, the version rule and the
  MongoDB case, `SchemaStatusTest` (every status decided), `UpgradeRunnerTest` on SQLite files,
  `UpgradeGateListenerTest`, `UpgradeControllerTest`, `UpgradePageTest`,
  `UpgradeCommandUnreachableTest`), `Tests/Unit/Storage/ConnectFailureIsFinalTest`, `Tests/Kernel/SchemaGateBootTest`
  (`Idno/start.php` and the console, closed, open and for a MongoDB site, in subprocesses) and
  `Tests/Upgrade/UpgradeEndToEndTest`: `php -S` on a free port (never 8089) in a subprocess with
  `PHP_CLI_SERVER_WORKERS=2`, through a router of the test's own that passes `IDNO_DBNAME`, `IDNO_URL`,
  `IDNO_PROXY_STRING`, `IDNO_DATABASE`, `IDNO_DBHOST` and `IDNO_DBPORT` into `$_SERVER` and runs
  `Tests/rig/router.php`, with the rig server's credentials against a private database only, on a
  `LegacyFixture` database (`insertSite()` writes a configuration, a `site` record, a user and a post)
  or an empty one; it stops the server (a bounded wait, then SIGKILL) and drops the database in
  `tearDown()`. Serialisation is proven by a step made while the test holds the lock (it copies
  nothing); two steps sent together are checked only for copying every row once. Run it on MySQL and
  MariaDB after changing the gate, the runner or the copier.

#### Copying a 1.x database

A 1.x site's data is in its 1.x tables (per collection `entities`, `reader`, `site`, `config`: the
tables `<c>`, `<c>_metadata`, `<c>_search`; and `versions`, `session`).
`Idno\Upgrade\LegacyDataCopier` (MySQL and MariaDB only: no 1.x site runs on SQLite) copies them
into the new schema's tables, which must exist first (the schema migrations and the indexes the
classes under `Idno/` declare: it creates no table). `UpgradeRunner` calls it, after the migrations
and the field indexes (see "The schema gate and the upgrade"). It is a public container service
(its `Dialect` and connection built when it is fetched, without connecting; the schema gate fetches
it at boot only on a MySQL or MariaDB database that has 1.x or `legacy_` tables). Its helpers, built with
new: `LegacySource` (every statement that reads a 1.x table), `RowWriter` (writes `idno_<c>` and the
rejects, verifies), `ConfigCopier` (the settings) and `DocumentCheck` (what a row's contents allow).

It never writes to a 1.x table (no UPDATE, DELETE, ALTER or marker column); its only change to them
is ONE `RENAME TABLE`. `testTheOneXTablesAreNeverWritten` compares `CHECKSUM TABLE` of every 1.x
table before the first step with every `legacy_` table after completion.

The phases (each resumable at any point; the state is in `idno_upgrade_progress`, one row per
collection: `source_rows`, `source_existed`, `copied`, `rejected`, `position` (the copy cursor),
`verify_cursor`, `error` (a `PublicMessage`'s text), `error_detail` (the private detail of a
failure: an exception's class and message; null for an impossible state), `completed_at`):

0. **Start** (a 1.x database and no progress row at all): the copy starts only when it can finish.
   A `legacy_<name>` beside its `<name>` (the rename could never be made) stops it with
   `PublicMessage::renameBlocked()`, and so does any row in a table of
   `LegacyDataCopier::MUST_BE_EMPTY` (`idno_entities`, `idno_reader`, `idno_site`, `idno_settings`,
   `idno_upgrade_rejects`; one `select 1 ... limit 1` each, never a count) with
   `PublicMessage::newTablesNotEmpty()` naming them: rows there are an earlier upgrade's (one of its
   `idno_` tables missed when they were removed by hand) or a site in use (a 1.x backup restored into
   a used 2.x site's database: `idno_site`/`idno_settings` hold rows as soon as it has been used), and
   a copy beside them would reject every 1.x row sharing an `_id` or uuid with one of them and keep the
   older one (and the verify pass would count leftover rejects as accounted for). Nothing is written
   (no progress row), so every step says it again; `progress()` finds it from the tables too; never
   complete; `idno:upgrade` exits 1. A copy that has begun (progress rows) is never checked again for
   this: its own rows are in those tables.
1. **Copy**: per collection, a keyset pass by `uuid` over `<c>`, in batches; then the config.
2. **Rename**: when every keyset pass has reached its end, the progress rows are marked ready
   (`verify_cursor` set, one transaction), then ONE `RENAME TABLE` moves every table of
   `LEGACY_TABLES` that exists to `legacy_<name>`. From then on no process of the old version can
   write the source. A `legacy_<name>` that exists beside its `<name>` blocks it (checked again here:
   one may appear after the start): nothing is renamed,
   and the error names both and says that someone who knows what the `legacy_` table holds must check
   it and move or remove it (it does not claim the rows were copied). The rename waits for the tables
   at most `RENAME_WAIT_SECONDS` (2): the session's `lock_wait_timeout` is set for that statement
   only and restored afterwards, also on failure (the server's default is a year). Anything holding a
   metadata lock on a 1.x table (a backup's `mysqldump --single-transaction`, an old process's open
   transaction or slow query) makes it give up with error 1205 on MySQL 8 and MariaDB 10.6 alike
   (DBAL's `LockWaitTimeoutException`; observed on both): not an error. Nothing is renamed or stored,
   the step returns its progress with the notice `PublicMessage::tablesInUse()` (the old tables are
   in use by something else, such as a backup or an old Idno process; the upgrade goes on when that
   has finished), and the next step, the progress rows still marked ready, tries again. Pinned by
   `LegacyDataCopierTest::testARenameThatWaitsForTheOneXTablesGivesUpAndTheNextStepRenames` and
   `UpgradeEndToEndTest::testTablesInUseHoldUpTheRenameWithoutStoppingTheUpgrade`.
3. **Verify**: a second keyset pass by `uuid` over the now immutable `legacy_<c>`, `VERIFY_BATCH`
   (2,000) uuids at a time, with its own cursor: the uuids that are neither in `idno_<c>` nor among the
   collection's rejects, by EXACT bytes, are copied or rejected by the same code as phase 1 (at most
   `BATCH` per batch). Rows written behind the copy cursor, during the rename, or lost from the new
   table are caught here. The config is computed again from `legacy_config` when its rows are not the
   ones phase 1 used.
4. **Complete**: `completed_at` is written for every collection. `UpgradeProgress::complete` is true
   exactly then.

What it guarantees:

- `step($deadline)` (`microtime(true)`) takes `Idno\Upgrade\UpgradeLock` without waiting (another
  process holding it: `progress()` is returned and nothing is done), works until the deadline or
  completion, always finishes the batch it started, and checks the deadline only between batches:
  with a deadline already past it does exactly one unit of work (a batch, the config, the rename or
  the completion). `progress()` gives an `Idno\Upgrade\UpgradeProgress`: per collection source (the
  count when the upgrade began), copied, rejected, done; `complete`; `error`; and `phase`
  (`NOT_STARTED`, `COPYING`, `VERIFYING`, `COMPLETE`; "stopped" is `error !== null`), and
  `noLegacyDatabase`: no table of `LEGACY_TABLES` under either name and no upgrade begun, in which case
  `step()` writes nothing at all (not even a progress row; `idno_settings` untouched); and `notice`,
  set only on the progress `step()` returns when its rename waited and gave up.
  `legacySchemaVersion()` is the `schema` row of `versions`, or of `legacy_versions` after the rename,
  or null. `completion()` is null when no upgrade has begun (no progress row), false while one runs,
  true once complete: ONE statement on `idno_upgrade_progress` (which must exist), for the schema gate. `step()` leaves the lock as it found it: when its caller already holds the lock, it works
  under it and does not release it.
- Bounded: no statement in a step reads a whole 1.x table, except one `COUNT(*)` per collection when
  its progress row is made (`source_rows`, display only). Every other read of a 1.x table is a LIMIT on
  a range of its `uuid` primary key, or a list of primary-key values; the config table is read with
  `LIMIT 1001` (a site keeps its configuration in one row; more than 1,000 rows stops the upgrade with
  a message). `testNoStatementReadsAOneXTableUnbounded` checks every statement of a whole upgrade.
- Batches: at most `BATCH` (500) rows and about `BATCH_BYTES` (8 MiB) of contents and search text,
  less when `memory_limit` leaves less than six times that free; a single row too large for the
  memory left stops the upgrade with a message (raise `memory_limit`). Each batch is ONE transaction:
  it locks the collection's progress row (`FOR UPDATE`, reading its cursor from the database), checks
  that its connection still holds the lock (`IS_USED_LOCK() = CONNECTION_ID()`: a named lock belongs to
  a session, and a reconnected session holds nothing), inserts the rows, verifies them, writes the
  rejects and moves the counts and the cursor. Every value is bound; a row too large for one statement
  (`max_allowed_packet`) has its search text written by a second statement, and a document too large
  on its own stops the upgrade with a message saying to raise the setting.
- Collations: a 1.x table may be `utf8`/`utf8mb3` `general_ci` as shipped, or converted to `utf8mb4`
  in any collation. Every comparison of a 1.x column with a bound value converts the value to the
  column's own character set and collation (`convert(? using <charset>) collate <collation>`, read from
  `information_schema`), so the primary key's range is used; no 1.x column is ever compared with a
  column of another table. Whether a row is accounted for is decided in PHP by the exact bytes of its
  uuid. `testOneXTablesConvertedToUtf8mb4AreCopied` runs the whole copy on converted tables.
- A copied row: `id` = `_id`, `uuid`, `type` = `TypeMap::typeFor(entity_subtype)`, `owner`,
  `publish_status` from the 1.x columns; `created` the document's top-level `created` when it is a
  timestamp by `RecordIndex::created()`'s rule (what a save would write), otherwise the 1.x column's
  instant (read without decoding the whole document); `data` = `contents` byte for byte; `search` the
  `_search` row's text, or ''.
- Verification: each inserted row's columns and the SHA-256 of its `data` and `search`, read back from
  the database, must equal the source's; any difference rolls the batch back, is stored (public error
  `PublicMessage::stopped()`, the message naming the record as `error_detail`), logged and thrown
  (`\UnexpectedValueException`). Every exception a step throws is stored the same way.
- Rejects (`idno_upgrade_rejects`, indexed on `(collection, legacy_uuid)`, with the row's exact
  `contents` bytes and a reason of at most 255 characters): contents not valid UTF-8, not JSON,
  nested deeper than PHP's 512 levels, not an object or array, or a row the database refuses
  (`Dialect::isRefusedData()`, except lock waits and deadlocks, which are thrown and retried by the
  next step): a document its JSON functions refuse (too deep: MySQL allows 100 nested objects or arrays, MariaDB 31), a
  value a generated column cannot hold, an `_id` that is that of a copied row, or a `uuid` equal to a
  copied row's under the new collation (`utf8mb4_unicode_520_ci`: `…/straße` and `…/strasse` were
  different in 1.x). Of two such rows the one copied first is kept: in the copy pass the first in the
  1.x table's `uuid` order (`utf8_general_ci` sorts `ß` as `s`, so `…/straße` before `…/strasse`); a row
  written behind the cursor is reached by the verify pass and is the reject. A batch that hits a
  refusal is redone row by row under savepoints: the good rows are copied, exactly the refused ones
  are rejects. Nothing that is not JSON the engine accepts is ever left in `data` (a `JSON_VALID()`
  check after the insert is defence in depth: with the core indexed fields the generated columns
  refuse such documents first).
- `config` (`ConfigCopier`): one transaction recomputes `idno_settings` and the config rejects: the
  newest row (the 1.x `created` column, then the greatest uuid) whose contents are a document
  `SettingsRecordStore::rowsOf()` can hold becomes the settings (written directly, no defaults); the
  other valid rows are rejects `superseded configuration`, an invalid one gets its reason
  (`cannot be the settings: ...`). With none usable the settings stay empty. The settings are each
  key's value re-encoded by `json_encode()`, as a save writes them; the 1.x row stays in `legacy_config`.
- Impossible states stop the upgrade with a stored error that says what was found, and nothing else is
  written, in this step and every later one, and it is never `complete`: in the verify phase (marked
  ready and the renamed `entities` tables there: only the rename makes them, since a `legacy_` table
  beside its 1.x one blocks marking ready) tables under 1.x names again, created by an old process
  after the rename (`PublicMessage::recreatedAfterRename()`: the `legacy_` tables are the site's
  original data and must be kept; stop the process; remove the newly created old-name tables; checked
  before the two rules after it, which would otherwise read it as a rename still to do or a database
  renamed by halves); some 1.x tables renamed and some not; tables renamed before the copy was marked
  ready; or a source the upgrade began with that is
  no longer there under the name the phase reads (`<c>` while copying or before the rename,
  `legacy_<c>` while verifying, `config`/`legacy_config` included). A collection's progress row records
  whether its 1.x table existed when the row was made (`source_existed`): a collection this site never
  had (no `reader` tables, say) is copied as empty and needs no table later; one whose table existed
  and then vanished is the impossible state, and so is one whose table did NOT exist and whose
  `legacy_<c>` exists in the verify pass (it appeared during the upgrade, created by an old-version
  process, and was renamed with the others, but the verify pass never reads it: its rows would be
  lost); that is checked when a step starts in the verify phase and right after the rename. The
  error is a `PublicMessage` naming only tables of `LEGACY_TABLES`. When there is no progress row to
  store it in (the tables were renamed before any step), `progress()` finds it from the tables alone
  and reports it, and `step()` returns it: an impossible state is never silent. `ConfigCopier` never
  deletes the settings unless it has a readable config table and has read it (it throws without one).
- Not detected: a 1.x row CHANGED after it was copied (its uuid is accounted for). Whoever runs the
  upgrade must stop every process of the old version first.
- `UpgradeLock`: on MySQL and MariaDB the named lock `idno_upgrade_` + the first 16 hex digits of the
  SHA-1 of the database name (`GET_LOCK(name, 0)`); `acquire()` never takes it on a session that holds
  it and `release()` releases until the session holds it no more (GET_LOCK is re-entrant); on SQLite an
  exclusive non-blocking `flock()` on `<database file>.upgrade.lock` (an in-memory database always gets
  it). `holds()` asks the server.
- Tests: `Tests\Upgrade\LegacyFixture` makes a private 1.x database (`create($purpose, $schema,
  $collation)`: the fixture schema `Tests/Upgrade/fixtures/mysql-schema-2020120301.sql`, with
  `2026111401` also `mysql-upgrade-2026111401.sql`, with a collation every table converted to utf8mb4),
  `insert()` writes a record laid out as 1.x `saveRecord()` did, `insertMany()` many small ones, and
  `prepareNewSchema()` prepares it as the upgrade will (the migrations, then
  `IndexedFields::reconcileDirectory()` of `Idno/`). `Tests/Upgrade/LegacyDataCopierTest` runs on
  MySQL and MariaDB and skips on an SQLite rig; run it on both servers
  (`IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql`) after changing the copier.
  `Tests/Unit/Upgrade/` pins the SQLite lock and `DocumentCheck`.

#### The legacy tables

A completed upgrade leaves the 1.x tables renamed `legacy_<name>` (the whole old database), the
rejects (`idno_upgrade_rejects`, with each row's contents) and the progress rows. Nothing deletes
them automatically. `Idno\Upgrade\LegacyTables` (a public container service: the legacy admin page
fetches it with `Idno::container()->get()`; built from the `BootConfig` and service closures of the
connection, the `SchemaCatalog`, the `LegacyDataCopier` and the `Dialect`, fetched only when needed,
and nothing at all on a site whose database is not SQL, where every list is empty and `drop()` is 0):

- `names()`: the `legacy_` + `LEGACY_TABLES` tables that exist, from the catalog (no statement beyond
  the catalog's one, which the gate has read); `present()`: the same with APPROXIMATE row counts (on
  MySQL/MariaDB the engine's estimate, `information_schema.tables.table_rows`, ONE statement for all;
  `COUNT(*)` each on SQLite, where there are none in practice), shown as approximate by the page and
  the console; `oneXBesideCompletedUpgrade()`: the gate's predicate on the catalog and
  `completion()`; `deletable()`: complete and not that (the one decision whether the page shows its delete form); `lastDropped()`: what the last `drop()`
  removed;
  `rejects($limit = 100, $offset = 0)`: `n`, `collection`, `legacy_uuid`, `reason` (never `contents`),
  ordered by `n`, limit 1 to `MAX_LIMIT` (1,000); `rejectCount()`; `reappeared()`: 1.x-named tables an
  old process recreated after completion (`LegacyDataCopier::reappearedTables()`, MySQL/MariaDB only,
  asked only when the catalog lists a 1.x name); `isComplete()`: `LegacyDataCopier::completion()`.
- `worthShowing()` (the admin menu entry): no statement when legacy tables exist; else ONE
  (`select 1 from idno_upgrade_rejects limit 1`); and only when the catalog lists a 1.x table name,
  what `reappeared()` costs (three).
- `drop()`: forgets the catalog; refuses with a `\LogicException` whose message is
  `PublicMessage::oneXBesideCompletedUpgrade()` when the 1.x `entities` tables are back beside the
  complete upgrade (`idno:upgrade --drop-legacy` runs while the gate is closed, so the guard is in
  `drop()` itself); with no legacy table returns 0; otherwise refuses with a `\LogicException`
  (nothing dropped) unless the upgrade is complete; then drops exactly the
  `legacy_` + `LEGACY_TABLES` tables, the `_metadata`/`_search` tables first (their foreign keys
  reference `<c>`), ONE `DROP TABLE IF EXISTS` each, foreign-key checks left on (the same on MySQL 8
  and MariaDB 10.6); forgets the catalog again and returns how many it removed (tables that existed
  when it started). A failure part-way is thrown and leaves the rest; the next `drop()` finishes,
  except when a table outside the fourteen has a foreign key into a legacy table (a 1.x plugin's table
  that referenced `entities` followed the rename): that refusal (MySQL 3730, MariaDB 1451/1217, DBAL's
  `ForeignKeyConstraintViolationException`) is thrown as `Idno\Upgrade\LegacyTableReferenced`, naming
  the table and the referencing `<table>.<constraint>`s; the page logs it and tells the admin a table
  outside the fourteen refers to a legacy table and must be dealt with first; the console prints it. Never dropped: the rejects, the progress rows, the
  reappeared tables, any other `legacy_` table (`legacy_other`).
- `/admin/legacytables` (`Idno\Pages\Admin\LegacyTables`, a LEGACY page: it needs the admin
  gatekeeper and the CSRF check of `Page::post()`, which controllers do not have; route in
  `Idno\Core\Admin::registerPages()`; templates `templates/default/admin/legacytables.tpl.php`,
  `Themes/Twenty26/templates/modern/admin/legacytables.tpl.php` and the shared
  `admin/legacytables/body`, which escapes every value from the database): GET (admins) changes
  nothing and lists the tables with their approximate counts (with the 1.x `entities` tables back
  beside the upgrade: no delete form, and the gate's message instead; the gate is closed then, so the
  page is reached only in a process whose legacy application booted anyway), the rejects (100 a page, `?offset=`, the total and
  where the contents are), the reappeared tables with what they are, or an empty state. POST: after
  `Page::post()`'s token check, `adminGatekeeper()`, then only `confirm` exactly `delete` with a
  complete upgrade calls `drop()`; every outcome is a session message (what was deleted, or why
  nothing was) and a redirect to the page. The admin menu entry (both menus:
  `templates/default/admin/menu.tpl.php`, `Themes/Twenty26/templates/modern/admin/menu.tpl.php`) is
  `LegacyTables::showInMenu()`: false without a statement unless a logged-in admin is viewing (the
  admin shell also draws the menu on the 403 page of an `/admin` URL); else `worthShowing()`, once
  per request. On an SQLite site (or one whose database is not SQL) the route exists, the entry never appears, and the page
  shows the empty state.
- `idno:upgrade --drop-legacy` (no migration, no copy, no index: only this): the 1.x `entities`
  tables back beside the completed upgrade → the gate's message on stderr, exit 1 (with or without
  `--yes`); nothing there → `Nothing
  to delete: there are no legacy tables.`, exit 0; upgrade not complete → a line on stderr, exit 1;
  without `--yes` → the tables with `about <n> rows` on stdout, `Nothing was deleted. ...` on stderr,
  exit 1; with `--yes` → `Deleted <n> legacy tables: ...` and how many rejects are kept, exit 0 (a
  failure: stderr, exit 1). `--yes` without `--drop-legacy`, or `--drop-legacy` with `--status`, does
  nothing and exits 1.
- Tests: `Tests/Upgrade/LegacyTablesTest` (a completed `LegacyFixture` upgrade, MySQL and MariaDB;
  skips on SQLite), `Tests/Unit/Upgrade/LegacyTablesTest` (a site whose database is not SQL: nothing fetched; SQLite: empty,
  statement costs, the drop rules), `Tests/Characterisation/AdminLegacyTablesTest` (the rig site: who
  gets the page, its empty state, no menu entry, POSTs without a token or the confirmation; it logs in
  over HTTP), `UpgradeEndToEndTest::testTheAdminReviewsAndDeletesTheLegacyTables` (an upgraded site
  in Twenty26: the entry, the list, escaping, the delete) and
  `testTheConsoleDeletesTheLegacyTablesOnlyWhenToldTo`, `Tests/Kernel/UpgradeCommandTest`; the guard
  beside a restored 1.x site in `LegacyTablesTest::testDropRefusesWhenTheOneXEntitiesTablesAreBackBesideACompletedUpgrade`
  and, through every entry point and the console's `--drop-legacy`,
  `UpgradeEndToEndTest::testTheOneXEntitiesTablesBackBesideACompletedUpgradeCloseTheSite`; the foreign
  key from another table in `LegacyTablesTest::testADropBlockedByAForeignKeyFromAnotherTableIsExplained`.

#### The JSON record store

Entity type aliases. A record's `type` column holds a short, stable alias (`status`, `article`,
`user`), not the entity's PHP class name, so stored data does not depend on class names; the
document itself keeps the class name in `entity_subtype`, and hydration reads that. Every
concrete entity class under `Idno/` and `IdnoPlugins/` declares its alias with one attribute line
directly above the class: `#[\Idno\Storage\Attribute\AsEntity('status')]`, with
`collection: 'reader'` or `collection: 'site'` where its static `$retrieve_collection` is not
`entities` (`Tests/Kernel/EntityAliasesTest` fails for a concrete entity class with no alias, a
duplicate alias or a collection that differs from the static). `Idno\Common\Entity` itself, abstract,
is `entity` (the default subtype); no other abstract class has one. To give a new entity class an
alias: add the attribute, choosing the snake-case form of the class name for a core class
(`notification`) or `<plugin>_<class>` for a plugin's. Aliases are stored permanently: never
change one. When a class is renamed or moved, keep its alias and list its former class names in
`legacy: [...]`, so a query by class still finds rows stored under them (`TypeMap::typesFor()` is the
alias, the class name and those names). The attribute is NOT inherited: a subclass is stored under
its own alias or, with none, under its class name. `AttributeTypeMap` loads each class once per
instance and never throws: a string that is no class, a class that cannot be loaded or one with no
attribute keeps its name.

`Idno\Storage\JsonRecordStore` (built with new: the connection, a `Dialect`, `RecordDefaults`, a
`TypeMap`, the `SchemaCatalog` of the same connection, the quote normaliser and the two search
settings) keeps collection `c` in the table `idno_<c>` (the name reduced to `[a-zA-Z0-9_]` and
lower-cased) when the catalog lists that table and `c` is not in `JsonRecordStore::SYSTEM_TABLES`
(`settings`, `sessions`, `migrations`, `upgrade_progress`, `upgrade_rejects`); any other name is no
collection, and no statement is sent for it. The catalog is read once: code that creates a table
afterwards calls `SchemaCatalog::forget()`.

- `save()` is ONE upsert on `id` (`Dialect::upsertById()`), preceded by one check: a document nested
  deeper (in objects and arrays, the document itself the first) than the server's JSON functions
  accept (`Dialect::jsonDepthLimit()`: MySQL 8 100, MariaDB 10.6 31, observed; none on SQLite, whose
  1000 is beyond `json_encode()`'s 512) is refused data, false, before anything is written: a table
  without generated columns would store it, under any `sql_mode`, and every query on a document
  field of the collection would then fail with error 3157/4040 (pinned by
  `JsonMySqlRecordStoreTest::testADocumentTooDeepForTheServerIsRefusedAndQueriesStillWork`). `data` is `json_encode($record)` with
  the old store's flags (none), so a document copied byte for byte from a 1.x `contents` reads the same;
  `type` is `TypeMap::typeFor()` of `entity_subtype` (default `Idno\Common\Entity`), `created` the
  timestamp (`RecordIndex::created()`), `search` `RecordIndex::searchText()`. A record is read back
  as `json_decode($data, true)`, never rebuilt from the columns. On MySQL/MariaDB every update of
  the upsert is guarded by `id = values(id)`, because `on duplicate key update` would otherwise update
  the row of another record that holds the uuid; when the statement affects no row, the store checks
  that the record is there (an identical re-save) or returns false (another record's uuid). Do not
  give the connection `PDO::MYSQL_ATTR_FOUND_ROWS`: it changes what "no row" means.
- Queries: `uuid`, `_id` (column `id`), `entity_subtype` (column `type`, each operand expanded
  through `TypeMap::typesFor()`, except a name with a leading backslash, compared as it is, which
  matches nothing, as before; `=` and `$not` become `in` and `not in`), `owner`,
  `publish_status` and `created` are compared with their columns; a `created` operand is read by
  `MySqlSemantics::epoch()` in the PHP default time zone of the query, and one with a fraction is
  never sent (MySQL and MariaDB compare an integer column with a fractional operand inexactly): it
  equals no record, `$lt` 10.5 is `<= 10`, `$gt` 10.5 is `> 10`. Any
  other field is compared with its query values (`RecordIndex::queryValues()`), computed in SQL from
  `data` by `Dialect::fieldCondition()`; the field's JSON path (`$.` and the name as `json_encode()`
  writes it, so that it matches the document's keys byte for byte on MariaDB) is a bound parameter.
  MySQL/MariaDB: a semi-join of the table with `JSON_TABLE` (not `EXISTS`: MySQL 8.0 evaluates a
  `JSON_TABLE` in an `EXISTS` subquery without its outer row), text from `LONGTEXT CHARACTER SET
  utf8mb4` columns (MariaDB's `JSON_UNQUOTE()` returns utf8mb3), compared `collate
  utf8mb4_unicode_520_ci` (`MySqlDialect::COLLATION`, the collation of the table's text columns). SQLite: `json_each()` and `->` (built-ins only), `collate nocase`, and
  `upper(...) collate binary` for `$lt`/`$gt`. Order: `created` desc, `id` desc.
- Search: the old store's rules on the `search` column (MySQL/MariaDB: the full-text index with the site's
  `boolean_search` and `bypass_fulltext_search`; SQLite: `like` for terms of up to 5 bytes, `instr()`
  for each word of a longer one, built-ins only, ASCII case only).
- Errors: the `RecordStore` interface's contract (`Tests/Unit/Storage/JsonRecordStoreFailureTest`
  pins the throwing side, the schema catalog's read included).
- Differences from the old store: listed for `db()` callers under the `db()` bullet ("The tables of
  the schema migrations make these differences too"); the contract pins each as the one behaviour of
  every binding, except where the bindings still differ, through a hook of `RecordStoreContract`:
  `expectNonAsciiCaseToBeIgnored` (false on SQLite), `expectAccentsToBeIgnored` and
  `expectTrailingSpacesToBeIgnored` (false in memory and on SQLite), `expectExpansionsToBeEqual`
  (false on SQLite), `expectTextToSortFoldedToUpperCase` (true in memory and on SQLite),
  `expectCreatedToBeComparedInTheTimeZoneOfItsSave`
  (true in memory, which keeps `created` as the text formatted at save time; false for this store),
  `expectFloatsInExponentFormToMatchTheirJsonText` (false on MySQL, true on MariaDB, SQLite and in
  memory), `expectLongSearchTermToMatchSubstring` and `expectBooleanModeSearch` (no full-text index
  in memory or on SQLite). A field with a declared index (#[IndexedField], below) matches scalar
  values only (no hook: pinned by
  `IndexedFieldsContract::testAListValueIsNoLongerMatchedOnceTheFieldIsIndexed`).

#### The file store

`Idno\Storage\Files\FileStore` keeps uploaded files on a Flysystem filesystem (`league/flysystem`;
the S3 adapter is `league/flysystem-async-aws-s3`; `league/flysystem-memory` is a dev dependency for
tests). It is built with new (the `Files/` directory is excluded from the container scan), with a
`FilesystemOperator` and the base directory name or a closure that returns it, asked at each call.

- Layout, unchanged since 1.x: a file with id `<id>` is `<directory>/<id[0]>/<id[1]>/<id[2]>/<id[3]>/<id>.file`
  (the bytes) with `<same>/<id>.data` beside it (the metadata, `json_encode($metadata)`, no flags).
  `FileStore::path($id)` is the `.file` path. A file exists when its `.file` does; a missing or
  undecodable `.data` is metadata `[]`; any other failure to read it is thrown, never read as `[]`.
- An id is exactly 32 hex digits (a local file) or exactly 24 (a GridFS file), nothing after them (`/^[a-f0-9]{24}(?:[a-f0-9]{8})?\z/i`: a trailing newline is not an id). It is validated before any path is built
  from it: `find()`, `has()`, `updateMetadata()` and `delete()` treat anything else as no file,
  `storeAs()` and `path()` throw `\InvalidArgumentException`, `readStream()` throws `FileStoreException`.
  `store()` makes a new id, `bin2hex(random_bytes(16))`. Files are streamed in and out, never read whole.
- `updateMetadata()` merges with `array_merge`, returns false when the file is not there and true,
  writing nothing, when the merge changes nothing. Failures of the filesystem are thrown as
  `FileStoreException` (previous: Flysystem's).
- `FilesystemFactory::fromConfig(BootConfig::all())`: an `uploadpath` of `s3://<bucket>/<prefix>/` is the S3
  adapter (`FilesystemFactory::s3Options()` maps `aws_region`, `aws_key`, `aws_secret` to the client's options; no credentials means the SDK's default chain), any
  other non-empty path the local adapter rooted there (files 0644, directories 0755; the root is
  created when something is first written, not when the adapter is built, so a root that cannot be
  made fails that write and never building the service), none throws a `\LogicException`. Tests give it an `S3Client` on a Symfony `MockHttpClient`, never the network.
- Tests: `Tests/Unit/Storage/Files/`.

The container's services and the legacy filesystem:

- `League\Flysystem\FilesystemOperator` and `Idno\Storage\Files\FileStore` are public container
  services built at runtime, when first fetched, by `Idno\Legacy\LegacyFileStoreFactory`
  (`FilesystemFactory::fromConfig()` of the `BootConfig`; a `FileStore` whose base directory is a
  closure returning `Idno::site()->config()->getFileBaseDirName()`, asked at each call, so the
  `file_path_host` setting and the `file/path/host` event apply). Like the storage services they need
  the legacy application; with no `uploadpath` fetching them throws a `\LogicException`.
- `Idno::site()->filesystem()` is `Idno\Legacy\FlysystemFileSystem` (and its files
  `Idno\Legacy\FlysystemFile`), built by `LegacyServiceFactory::filesystem()` from closures that fetch
  the `FileStore` and the logger at first use (the filesystem is built before the logger, the session
  and the language exist; keep it so). An empty or `local` `filesystem` setting with an `uploadpath`
  gives it, whether the path is a directory or `s3://<bucket>/<prefix>/`; any other value is a class
  named through `componentFactory()`, falling back to it. With no `filesystem` setting a database backend class
  that supplies its own (`db()->getFilesystem()`) keeps it. No `uploadpath` and nothing else: null. The `filesystem`
  setting is deprecated: S3 needs no plugin, just an `s3://` upload path. The legacy API never threw:
  the shim logs what the store can throw (a `FileStoreException`, Flysystem's `FilesystemException`, the
  factory's `\LogicException`) with a fixed message and returns false (`findOne()` false; a failed
  `storeFile()` or `storeContent()` also adds the two session messages the old local filesystem
  added): no failure of the store becomes the 500 page, whose message can hold a server path.
  `FlysystemFile::$internal_filename` is the upload root joined with `FileStore::path()` on a local
  directory and `''` on S3. `Idno\Files\LocalFileSystem`, `LocalFile` and `CDNStorable` are gone;
  `Idno\Files\FileSystem` and `File` remain the bases.
- The file page (`Idno\Pages\File\View`) positions a stream for a range request with
  `View::seekTo()`, which reads and discards up to the offset when the stream cannot seek (S3), and
  reads the range with `View::readRange()`, which stops at the end of the stream or on an empty or
  failed read (a stream shorter than the stored size never makes the page wait).
  `FilesystemCache`, the Purifier cache and the Twig cache never use an `s3://` upload path as a
  directory: they use the temporary directory instead.
- Deliberate differences from the old local filesystem: deleting a file removes its `.data` file too;
  an SQL site with an `uploadpath` and no `filesystem` setting stores files locally (it had no
  filesystem); `file_path_host` and the `file/path/host` event work for new uploads (the old code made
  the directories under the site's host and wrote under the configured name); a file id that is not 24
  or 32 hex digits is not found, whatever is on disk.
- Tests: `Tests/Kernel/LegacyFileSystemTest` (a temporary upload directory of its own),
  `Tests/Characterisation/FileServingTest` (the file page over HTTP, range quirks pinned),
  `Tests/Core/FilesystemTest`.

#### Importing a MongoDB site

`php bin/console idno:import:mongo` (`Idno\Console\ImportMongoCommand`) runs `Idno\Upgrade\Mongo\MongoImporter`
from a MongoDB database (`MongoSource`) into a NEW, EMPTY SQL database, and copies the GridFS files
into a `FileStore`. It reads MongoDB with the `mongodb` extension ALONE (`ExtMongoSource` on
`MongoDB\Driver\Manager`): nothing of it may use the `mongodb/mongodb` library (`MongoDB\Client`,
`MongoDB\Collection`, `MongoDB\GridFS`), which a site moving off MongoDB need not have (the library is not a dependency of Idno at all). A
site configured for MongoDB never boots the legacy application (the schema gate holds it closed:
`SchemaStatus::MongoRetired`), and this command is how it leaves MongoDB: it runs with the gate closed,
without the line the console prints for other commands, as `idno:upgrade` does. It runs whether or
not the legacy application booted. Everything in `Idno/Upgrade/Mongo/` is built with new
(excluded from the container scan).

- The importer, in order: (1) the target must hold no table at all (`SchemaCatalog::tables()` is
  `[]`), else a `\RuntimeException` and nothing written; the source's collections are listed before
  anything is written (an unreachable MongoDB stops it there); (2) every migration but the newest
  (`SchemaMigrator::migrate(exceptLast: true)`), then the core declared indexes
  (`IndexedFields::reconcileDirectory()` of `Idno/`); (3) `entities`, `reader`, `site`, each in `_id`
  order, in transactions of 200: keys unescaped exactly as the old backend's `unsanitizeFields()` read them
  (`MongoDocument::unescapeKeys()`: sequential replacements, `%25` → `%` first, then `%24` → `$`, then
  `%2E` → `.`, so `a%252Eb` reads `a.b`; a key that changes moves after the others, as
  `unsanitizeFields()` left it; two keys becoming one is a reject), a non-empty `_id` and
  uuid required, saved through a `JsonRecordStore` with `ImportRecordDefaults` (fills in nothing:
  asking it for an id or a uuid is a `\LogicException`; `siteid` null), `AttributeTypeMap` and the
  site's quote normaliser (`Idno\Core\Language::uncurlQuotes()`, called on an instance made without
  its constructor, which needs the legacy application; the method uses nothing of the instance), so
  the search text is what the site's store writes; then read back and compared (`===`) with the
  document as the store writes it (`siteid` null and `owner` '' when empty): a difference is a
  `\RuntimeException`. A reject (`idno_upgrade_rejects`: `collection`, `legacy_uuid` (at most 255
  characters), `legacy_id` (36), `reason` (`RowWriter::reason()`), `contents` = the document's
  canonical Extended JSON): no plain form (`a value of type <class> cannot be imported`), no `_id`, no
  uuid, no JSON form, an `_id` or a uuid another imported record holds under the database's collation
  (found before the save, so a record is never replaced; of two, the smaller `_id` is kept), a record
  `RecordIndex` cannot index or the store refuses (too deep for the server's JSON functions, an
  `access` over 255 characters, ...). Each reject is logged (warning) and reported; (4) `config`: at most
  1000 documents; the one the backend loaded, as MongoDB answers the backend's own query
  (`MongoSource::loadedConfigurationId()`: `ExtMongoSource` runs `getAnyRecord('config')`'s
  `find({}, sort created -1, limit 1)`), becomes `idno_settings` (written as
  `SettingsRecordStore::rowsOf()` lays it out, no defaults; read back through a `SettingsRecordStore`);
  every other one is the reject `superseded configuration`; when that one cannot be the settings (no
  plain form, `rowsOf()` refuses it) the import STOPS saying why, never falling back to another; (5) files in `_id` order: `FileStore::storeAs()`
  under the file's own id, streamed through `ChunkStream` (one piece in memory), then checked: the bytes
  read equal the recorded length, the stored size, the md5 when GridFS recorded one, the SHA-256 of
  the stored bytes against the bytes read, the metadata (a file that fails is deleted from the store
  before the import stops); a file there already with the same length,
  metadata and bytes (md5, or SHA-256 against the source) is skipped; one with the same length and
  other bytes and no md5 stops the import (its source stream is spent); (6) counts: every collection's
  rows and rejects in the target must equal what was imported and rejected, and what was read must
  equal the source's count when the collection was begun (a site still writing stops it); (7) LAST the
  newest migration. Holding it back makes the target not "current" for the importer's own completion
  check and for `idno:upgrade --status`; it does NOT keep a site closed: a site whose config.ini named
  an unfinished or failed target would run that migration from its upgrade page and open on partial
  data. The protection is that config.ini names MongoDB until the command has exited 0; after a failure
  that touched the target (`MongoImporter::touchedTarget()`) the command prints on stderr, after the
  error, `ImportMongoCommand::INCOMPLETE` (the target is incomplete, must not be used, drop it and start
  again on a new, empty database). The import never cleans up a failed run: whoever runs it drops the
  target by hand (the database, or the SQLite file with its `-wal` and `-shm` files). Other collections are returned as `notImported` (names only).
- `ExtMongoSource::count()` (and `fileCount()`, `fs.files`) is exact: an `aggregate` with `$count`,
  which reads the documents, not the `count` command without a query, which answers from collection
  metadata that is wrong after an unclean shutdown of the server (the importer's check that "a site is
  still writing" would then stop every run). The source server must be MongoDB 3.6 or later (what the
  `mongodb` extension talks to); an older server's data is dumped and restored into a newer `mongod`
  first.
- `ExtMongoSource` reads documents and files a page at a time (constructor `$pageSize`, default 100:
  `_id` greater than the last read, sorted, limited, each page read whole), never through one
  long-lived cursor (it would idle on the server past its cursor timeout while a page is imported);
  documents whose `_id` is not an ObjectId come first, paged by skip. No message tells the user to
  change MongoDB (their only copy): a broken GridFS file (`ExtMongoSource::problem()`) says what is
  wrong, that nothing in MongoDB was changed, and to back it up (`mongodump`) before removing or
  repairing the file.
  `ObjectId` → its 24 hex digits, `UTCDateTime` → epoch seconds (int, rounded down),
  `Int64` → int; any other BSON object makes the document a reject (`document` null, `reason`). A
  file's metadata is `MongoDocument::fileMetadata()` (what `MongoDBFileSystem::findOne()` put in
  `$file->metadata`: keys unescaped, the `metadata` sub-document but `_id`, then every top-level field
  but `_id`/`metadata` over it, `filename` reduced to its base name; a `UTCDateTime` as its string);
  its stream yields the chunks in order of `n` (whatever order they are stored in), one fetched at a
  time (`batchSize` 1); a missing or duplicated chunk throws from the read (a missing last chunk shows
  as short bytes). A file whose `_id` is not an ObjectId, or whose metadata holds another BSON
  type, stops the import (the site could never serve it).
- The command: refuses (exit 1, before connecting) without the extension, a target, a source (the
  site's `dbstring`/`dbname`/`dbuser`/`dbpass`/`dbauthsrc` when its database is MongoDB, else
  `--source-uri` and `--source-db`) or an upload path (`--upload-path`, else `uploadpath`). A target
  URL is `mysql://` (or `mariadb://`) with a host and a database name, or `sqlite:///relative` /
  `sqlite:////absolute` (read with `ConnectionParameters::fromUrl()`; user and password
  percent-decoded); anything else is refused. A user and no password asks for it, hidden, on an
  interactive terminal. The files' directory: `--file-host`; else the boot `file_path_host`; else the
  imported configuration's `file_path_host`; else the host of the boot `url`, else of the imported
  `url`, with `www.` removed as `Config::pathHost()` removes it; with none, a `\RuntimeException` at the
  first file (no file written). Passwords (the target's, and the source's from the configuration or
  the URI) are never printed: a failure to connect to the target is a fixed line and the exception's
  class; so is any failure to parse the source's URI or to connect to or read from MongoDB (any
  `MongoDB\Driver\Exception\Exception`: the driver quotes the whole URI in some messages), with the
  detail on the `upgrade` log only, passwords replaced; every other message is printed and logged
  with each password replaced. The source's passwords come from `ImportMongoCommand::uriPasswords()`,
  a pattern on the user-info part (`parse_url()` fails on malformed URIs), raw and percent-decoded. On success it
  prints the counts, the collections not imported, the `config.ini` lines (`database`, `dbname`,
  `dbhost`, `dbport` when given, `dbuser`, `dbpass = "<the password you gave>"`, `filesystem = "local"`,
  `uploadpath`, and `file_path_host` when `--file-host` was given) and `Now run php bin/console
  idno:upgrade`; exit 0. For an SQLite target it then prints `Installer::sqliteOwnershipNotes()`.
- Tests: `Tests/Unit/Upgrade/Mongo/` (`MongoDocumentTest`; `MongoImporterTest`, the importer's contract
  `Tests/Import/Contract/MongoImporterContract` on an SQLite file with an `ArrayMongoSource`, plus a 64
  MiB file imported under `memory_limit=32M` in a subprocess, `import_large_file.php`, on the local
  Flysystem adapter), `Tests/Unit/Storage/ConnectionFactoryTest`, `Tests/Import/MongoImporterMySqlTest`
  (the same contract on a private MySQL or MariaDB database; skips on SQLite) and
  `Tests/Import/MongoImportEndToEndTest`: the site captured from the 1.x MongoDB backend before it was
  removed (`Tests/Import/fixtures/mongo-site.ndjson`, every document as canonical Extended JSON, and
  `expected.php`, what the backend returned; they cannot be regenerated, because the backend and the
  script that captured them are gone: edit them by hand, or add documents with the driver in the
  test, as it does) loaded into a
  private MongoDB database, the command run in a subprocess configured for MongoDB, the records and
  files compared, the imported site served (`php -S`, after `idno:upgrade`); on top of the fixture it
  adds with the driver documents holding a Binary, a Decimal128 and a Regex (rejects), dates before and
  after 1970, an Int64, `{"0":..,"1":..}` and `{}`, files with chunks out of order and of length 0, and
  per case a file with a missing middle chunk, a duplicated chunk or a missing last chunk (each stops
  the command, leaves nothing of the file, and the target pending); it also checks pages of two and the
  configuration MongoDB's own query names. It needs the extension and
  `IDNO_TEST_MONGO_URL` (skips without the variable, fails with it and no extension).
  `composer test:mongo-import` (`Tests/rig/mongo-import.sh`) runs it in Docker: a `mongo:6` container
  with a throwaway password (no port published on the host: the containers share a network of their
  own) and a PHP 8.4 image with the extension (`Tests/rig/mongo-import.Dockerfile`) built around a COPY
  of the checkout (never the checkout itself, and without `.git`, `vendor/`, `.env` or the whole
  `configuration/` directory, so no local configuration or secret is baked into the kept image: the rig
  it starts writes the copy's own `configuration/config.ini`), and runs `Tests/Import` TWICE inside the
  PHP container (`Tests/rig/mongo-import-container.sh`): on the SQLite rig, then against a `mysql:8.0`
  container on the same network, set up as CI's MySQL job sets its database up (the container has no
  Docker, so the rig's MySQL mode cannot be used): `Tests/build/config_mysql.ini` as
  `configuration/config.ini` (user root, host the container's name), `idno:upgrade`, `php -S
  localhost:80` with `Tests/build/router.php`, bare PHPUnit (deprecations excluded); the test's target
  is then a private database of that server. Each run must RUN `MongoImportEndToEndTest`
  (`Tests/rig/mongo-import-check.php` reads PHPUnit's JUnit log and fails on a skip), so BSON-derived
  values reach MySQL's JSON functions too. It removes the
  containers, their volumes and the network whatever happens (also when interrupted), and the previous
  image when a build replaces it. The container (and the CI job `mongo-import`) have the `mongodb`
  extension and no `mongodb/mongodb` library: the import's subprocess, configured for MongoDB, boots
  the kernel with the gate closed and needs the extension only.

#### The settings store

The legacy application keeps the site's configuration as ONE document in the collection `config`
(`Idno\Core\Config` reads "the latest record of `config`" and saves "this record to `config`").
`Idno\Storage\SettingsRecordStore` (built with new: the store it decorates, the connection, a
`Dialect` and `RecordDefaults`; `COLLECTION` is `config`) serves that collection from `idno_settings`
and passes every other collection to the inner store unchanged. The container's `RecordStore` is one,
over a `JsonRecordStore`.

- Layout: one row per top-level key of the document. `name` is the key (case-sensitive, binary
  collation; at most 190 bytes), `value` the key's value as JSON text (`json_encode()` with no flags,
  as `JsonRecordStore` encodes a document, so a value reads back as the JSON store gives it: a whole
  float is written as `json_encode()` writes it, nested key order is kept) and `position` the key's
  index in the document: reads are `order by position`, so the keys come back in the order they
  were saved. An integer key of a document is a string key of the table and comes back as an int key
  (what `json_decode()` gives for an object). `SettingsRecordStore::rowsOf($document)` returns
  `key => JSON` in document order (the position is the index); it is pure and static, applies no
  defaults and throws `\InvalidArgumentException` for a document the table cannot hold: a later step
  writes settings rows with it while the application is not running.
- The collection holds at most one document (a deliberate difference from the old `config` table,
  which could hold several): saving a record with another `_id` replaces the document, and
  `count()` is 0 or 1. The collection name is reduced as the other stores reduce it (`Config` is
  `config`).
- `save()` fills in the defaults as the inner store does, then in ONE transaction deletes EVERY row
  (`delete from idno_settings`) and inserts one row per key. The delete comes first because it is a
  write that depends on no earlier read: it takes the write lock before anything is decided, so two
  saves are serialised and the table holds exactly one of the two documents (an earlier read of the
  existing keys let a concurrent save's new key survive on MySQL). A save that cannot get the lock in
  time (a lock wait timeout or deadlock on MySQL/MariaDB, SQLite's busy timeout) is refused data:
  false, the previous document intact. It returns the `_id`. It also returns false, leaving the
  previous document untouched, for a save the data makes impossible: a value that cannot be encoded
  as JSON, an empty key, a key over 190 bytes (counted in bytes, on every engine) or not UTF-8, or a
  key ending in a space (`utf8mb4_bin` pads with spaces, so on MySQL and MariaDB `a` and `a ` would
  be the same key: refused everywhere instead of merged on one engine), and for a refusal by the
  database (`Dialect::isRefusedData()`). A `created` that is not a timestamp throws
  `\InvalidArgumentException`. Any other failure is thrown after the transaction is rolled back,
  with nothing stored and the connection usable; a missing `idno_settings` is such a failure, not
  an unknown collection (pinned by `testAFailingStatementIsThrownAndRolledBack`, which fails the
  begin, the delete, each insert and the commit in turn, `testAMissingSettingsTableIsThrown`, and,
  with a second connection, the two-writer tests of `SettingsRecordStoreContract`).
- `findLatest()`, `findById()`, `findByUuid()`, `find()` and `count()` assemble the document from
  the rows (one `select`) and, for criteria, evaluate them on it with an `InMemoryRecordStore`
  (the reference implementation of the rules: nothing is matched twice). `find()` returns the
  document or nothing, honouring `limit` and `offset`. A stored document the in-memory evaluator
  cannot hold (a row whose JSON decodes to a value with no JSON text, such as `1e999`, which is INF)
  makes `find()` and `count()` throw `\UnexpectedValueException`; the plain finders return it. Search
  text is not normalised (no quote normaliser). `delete($id)` is atomic: in one transaction a
  conditional `delete ... where name = '_id' and value in (...)` removes the `_id` row only if it
  still holds the id (and holds its lock), then every other row; any other id, or a document that
  replaced the first a moment ago, is "no such record" (true) and nothing is removed. `deleteAll()`
  removes every row.

#### Declared indexed fields

A query on a document field scans the table (it is computed from `data`). An entity class can
declare a top-level field as indexed, with a repeatable attribute directly above the class, next to
its `#[AsEntity]`: `#[\Idno\Storage\Attribute\IndexedField('slug')]` (options: `unique: true`,
`length: 1..255`). Declare a field only because hot queries look it up; never for correctness.

- The name matches `[a-z0-9_]{1,48}` and is not a `RecordIndex::COLUMN_FIELDS` name (`uuid`, `_id`,
  `entity_subtype`, `owner`, `publish_status`, `created` have columns of their own); the attribute
  throws `\InvalidArgumentException` otherwise, and the dialects check it again where they build
  DDL or SQL (the JSON path in DDL cannot be a bound parameter, so it is written only from the
  validated name).
- What exists: on MySQL/MariaDB a `VIRTUAL` generated column `ix_<field>` of `idno_<collection>`
  (`LONGTEXT`, `utf8mb4_unicode_520_ci`) and an index `ix_<field>` on its first `length` characters
  (default 255; unique when declared, so unique on the prefix; comparisons are always on the whole
  value); on SQLite an index `idno_<collection>_ix_<field>` on an expression, `COLLATE NOCASE`, over
  the whole value (`length` is ignored). The indexed value is the field's query value when it holds
  a scalar (the same SQL definition the ad-hoc path uses: `MySqlDialect::text()`,
  `SqliteDialect::scalarText()`), NULL when it is absent or holds an array or object. On MariaDB a
  string is unquoted with `JSON_VALUE()` (its `JSON_UNQUOTE()` returns utf8mb3 and loses four-byte
  characters); on MySQL with `JSON_UNQUOTE()` (its `JSON_VALUE()` returns a `VARCHAR(512)`).
  `SchemaCatalog::indexedFields()` lists them.
- Queries: `JsonRecordStore` compares a field the catalog lists as indexed for the collection with
  its indexed value (`Dialect::indexedFieldValue()`, a plain comparison through
  `Dialect::textComparison()`: MySQL's `t.ix_<field>`, never the `JSON_TABLE` semi-join; on SQLite
  the indexed expression, which SQLite matches to the index for `=` and `in`). So the field matches
  scalar values only; every other result is the same before and after the index exists, for every
  operator (pinned by `Tests/Storage/Contract/IndexedFieldsContract`, run on SQLite in the unit tier,
  `Tests/Unit/Storage/IndexedFieldsSqliteTest`, and on MySQL and MariaDB,
  `Tests/Storage/IndexedFieldsMySqlTest`, which also EXPLAINs the hot queries on the core
  declarations). The store and `IndexedFields` must share the `SchemaCatalog` (the container's is
  one shared service), or the store sees a new index only after its own catalog is forgotten.
- `Idno\Storage\IndexedFields` (a public container service: the connection, the container's
  `Dialect`, built by `DialectFactory` from the boot configuration, and the `SchemaCatalog`; fetching
  it on a site whose database is not SQL throws a `\LogicException`): `reconcile(...$classes)` applies
  the declarations of each class AND its parent classes to the collection of the class's OWN
  `#[AsEntity]` (a class without one creates nothing by itself; `Idno\Common\Entity`'s declarations
  reach `entities`, `reader` and `site` through its subclasses), skips a collection whose table does
  not exist (nothing created, nothing reported), creates what is missing (idempotent; an interrupted
  creation is completed; a column or index a concurrent request made first, `Dialect::isAlreadyDefined()`,
  is no failure), calls `SchemaCatalog::forget()`, and returns the `collection.field` names it
  created. An index is never dropped or altered: a removed declaration leaves its index (and with it
  the scalar-only matching of that field, until the database is recreated; the rig's databases must
  be recreated, `composer test:down` then `test:up...`, after a declaration is removed), and a
  declaration whose options differ from the existing index changes nothing and is listed by
  `conflicts()` (of the last call). So a RELEASED declaration can never be withdrawn by deleting the
  attribute: that needs a migration that drops the column and its index. Every declaration is read and validated before any DDL.
  `reconcileDirectory($dir)` reconciles what `EntityClassFinder::in($dir)` finds: PHP files with a
  line that starts (after indentation) with `#[AsEntity` or `#[IndexedField` (imported or fully
  qualified; a mention in a comment or docblock does not count), the class name derived
  from the path (PSR-4 under the project, or under the nearest `Idno`/`IdnoPlugins` directory outside
  it), loaded through the autoloader (never `require`), confirmed by reflection; a file that fails to
  load is skipped.
- When it runs. CORE declarations (classes under `Idno/`): created by the upgrade (the upgrade page's
  steps and `idno:upgrade`) before any copy, on the still empty tables, held by the marker migration
  (a failure keeps the site closed); a new core declaration ships with a new, empty migration.
  PLUGIN declarations, bundled plugins included: created when the plugin is enabled in the admin panel
  (`Idno\Pages\Admin\Plugins`; skipped on a non-SQL site; a failure is logged and shown as an error
  message, and the plugin is enabled anyway), and by `php bin/console idno:upgrade` for every enabled
  plugin, after the schema is current and any copy has finished (a failure is a warning: the plugin is
  skipped). The upgrade page never creates them: a site upgraded only through the browser has no
  plugin indexes until one of those happens: slower plugin queries, and the same results as long as
  every record of the collection holds a single value in each declared field, which is what a
  declaration promises; a record holding a list or object in a declared field is matched by queries
  on that field only until the index exists (so two upgraded copies of the same data can answer
  differently where that promise is broken). A plugin's
  failure never closes the site; a plugin that is there but not enabled is never reconciled by the
  upgrade. On a
  database without the tables (a 1.x site not yet upgraded) enabling a plugin creates nothing (pinned
  by `Tests/Kernel/PluginIndexedFieldsTest`, on private databases).
- Core declarations (pinned by `Tests/Kernel/EntityAliasesTest`, and by `UpgradeCommandTest::CORE_INDEXES`): `Idno\Common\Entity`:
  `slug`, `shorturl`, `url`, `canonical` (not `access`: the baseline schema indexes it itself, see
  "The schema (migrations)"); `User`: `handle`, `email` (not `admin`: `AccessGroup`, in
  the same collection, stores `admin`, `read` and `write` as lists of user uuids, and
  `User::getXAccessGroupIDs('admin')` looks groups up by them; pinned by
  `Tests/Data/AccessGroupPermissionLookupTest`); `Site`: `domain`;
  `Invitation`: `email`, `code`; `AsynchronousQueuedEvent`: `queue`; `UnfurledUrl`: `source_url`;
  `Reader\Feed`, `Reader\Subscription`, `Reader\FeedItem`: `feed_url`. A new declaration on a core
  class ships with a new, empty migration, so that sites reconcile it on upgrade.
- Bundled plugin declarations (plugin declarations, created as above, not by the upgrade page):
  `StaticPages\StaticPage`: `category`; `ActivityPub\Entities\ActivityPubFollower`: `actor_uri`,
  `user_uuid`. None of the core or bundled declarations is unique (nothing enforces uniqueness today;
  a unique index would make saves fail on existing sites).
- A warning for anyone declaring a field: a declaration applies to the whole collection table, not
  to one class's entities. A field with a declared index matches scalar values only, so declaring a
  field that other code stores lists or objects in changes what queries on that field match, for
  everyone. Before declaring one, grep where the field is assigned (`->field =`, `['field']`,
  `'field' =>`) in `Idno/` and `IdnoPlugins/`, for EVERY class of the collection.
- Plugin authors: `docs/developers/plugins/indexed-fields.md`.

## Content Plugins

| Plugin | Content type | ActivityPub type | Routes |
|---|---|---|---|
| **Status** | Short notes & replies | `Note` | `/status/edit`, `/reply/edit` |
| **Text** | Long-form articles | `Article` | `/entry/edit` |
| **Photo** | Photos with captions | `Note` + attachment | `/photo/edit` |
| **Media** | Audio/video uploads | `Note` + attachment | `/media/edit` |
| **Event** | Calendar events & RSVPs | `Event` | `/event/edit`, `/rsvp/edit` |
| **Checkin** | Location check-ins | `Note` + location | `/checkin/edit` |
| **Like** | Bookmarks / likes | `Note` | `/like/edit` |
| **StaticPages** | CMS-style static pages | N/A (not federated) | `/staticpages/edit`, `/pages/{slug}` |

Each content plugin has an `Entry.php` entity class that defines
`getActivityStreamsObjectType()` and content-specific behavior.

## Feature Plugins

| Plugin | Purpose |
|---|---|
| **ActivityPub** | Fediverse federation (see below) |
| **IndiePub** | IndieAuth + Micropub server |
| **Bridgy** | POSSE syndication via Bridgy |
| **Webhooks** | Outgoing webhooks on content events |
| **Styles** | Custom CSS admin interface |

## ActivityPub Plugin

`IdnoPlugins/ActivityPub/` — Full W3C ActivityPub server implementation.

### Key files

| File | Purpose |
|---|---|
| `Main.php` | Route registration, event hooks for save/update/delete → federation |
| `ActivityBuilder.php` | Builds outgoing activities (Create, Update, Delete, Accept) and objects (Note, Article, Event) |
| `ActivityHandler.php` | Processes incoming inbox activities (Follow, Undo, Delete, QuoteRequest) |
| `Delivery.php` | Signs and POSTs activities to remote inboxes; fan-out to followers via queue |
| `HTTPSignature.php` | HTTP Signature signing (draft-cavage) and verification |
| `RemoteActor.php` | Fetches and parses remote actor profiles, keys |
| `Pages/Actor.php` | Actor profile endpoint (Person object) |
| `Pages/Inbox.php` | Per-user inbox (POST handling with CSRF bypass) |
| `Pages/SharedInbox.php` | Shared inbox for multi-user delivery |
| `Pages/Outbox.php` | Paginated outbox (OrderedCollection of Create activities) |
| `Pages/Followers.php` | Followers collection |
| `Pages/Following.php` | Following collection |
| `Pages/QuoteStamp.php` | QuoteAuthorization stamp verification endpoint (FEP-044f) |
| `Pages/NodeInfo.php` | NodeInfo 2.0 metadata |
| `Entities/ActivityPubFollower.php` | Follower record entity |

### Routes

```
/actor/{handle}               Person profile
/actor/{handle}/inbox         Per-user inbox
/actor/{handle}/outbox        Outbox (paginated OrderedCollection)
/actor/{handle}/followers     Followers collection
/actor/{handle}/following     Following collection
/inbox                        Shared inbox
/activitypub/quote-stamp      Quote authorization stamp (FEP-044f)
/.well-known/nodeinfo          NodeInfo discovery
/nodeinfo/2.0                 NodeInfo document
/admin/activitypub            Admin panel
```

### Federation flow

1. **Outgoing**: When an entity is saved/updated/deleted, `Main.php` hooks build
   the activity via `ActivityBuilder` and call `Delivery::deliverToFollowers()`
   which enqueues signed POSTs to each follower's inbox.
2. **Incoming**: `ActivityHandler::handle()` verifies HTTP signatures, routes by
   activity type, and processes Follow (auto-accept), Undo, Delete, QuoteRequest.
3. **Quote posts (FEP-044f)**: All outgoing notes include
   `interactionPolicy.canQuote.automaticApproval` set to `as:Public`. Incoming
   QuoteRequests are auto-accepted with an HMAC-signed stamp URL. The stamp
   endpoint serves `QuoteAuthorization` objects for third-party verification.
4. **Context**: Activities use the GoToSocial namespace (`gts:`) for interaction
   policies. The extended `CONTEXT_WITH_QUOTES` constant in `ActivityBuilder`
   defines all required JSON-LD terms.

### Patterns to follow

- Activities are plain PHP arrays, not ORM objects — `ActivityBuilder` returns arrays
- Use `Delivery::deliverToFollowers()` for fan-out (it enqueues via the async queue)
- Use `ActivityBuilder::wrapActivity()` to wrap objects in activity envelopes
- New inbox activity types: add a `case` to the switch in `ActivityHandler::handle()`
  and a corresponding `private static function handleXxx()` method
- The `ActivityBuilder::CONTEXT_WITH_QUOTES` constant is the canonical `@context`
  for all outgoing activities

## IndieWeb Standards

| Standard | Implementation |
|---|---|
| **Webmention** | `Idno\Core\Webmention` — sends and receives webmentions; endpoint at `/webmention/endpoint` |
| **Micropub** | `IdnoPlugins\IndiePub` — full Micropub server at `/micropub/endpoint` |
| **IndieAuth** | `IdnoPlugins\IndiePub` — authorization at `/indieauth/auth`, token at `/indieauth/token` |
| **Webfinger** | `Idno\Core\Webfinger` — `/.well-known/webfinger` endpoint |
| **Microformats2** | Templates emit `h-entry`, `h-card`, `h-event` markup; `mf2/mf2` parser included |
| **PubSubHubbub** | `Idno\Core\PubSubHubbub` — real-time feed notification |
| **POSSE** | `Idno\Core\Syndication` — syndicate to external services; Bridgy plugin for backfeed |

## Code Style

- PHP 8.4+ and Symfony 8 (`^8.0`) required; uses PSR-4 autoloading (`Idno\`, `IdnoPlugins\`, `Themes\`, `ConsolePlugins\`)
- Namespace hierarchy matches directory structure exactly
- `vendor/bin/phpcs -n` must pass (`phpcs.xml`; CI runs it)
- Entity properties are dynamic (magic `__get`/`__set` via `$this->attributes` array)
- Plugins use braced namespace blocks: `namespace IdnoPlugins\Foo { class Main extends \Idno\Common\Plugin { } }`
- Legacy core uses indented namespace blocks (historical style); edits to legacy files keep
  that file's style and stay minimal

### New code conventions

New code (the kernel, `Idno\Config`, `Idno\Http`, `Idno\Console`, controllers, commands
and services), unlike the legacy code. New tests follow the first three points too, but may
reach into the legacy application (`Idno::site()`) where that is what they test.

- `declare(strict_types=1);` and a file-scoped namespace (`namespace Idno\Foo;`, no braces)
- typed properties, parameters and return types
- classes `final` by default
- dependencies by constructor injection (autowired); no `Idno::site()`, `Idno::container()`
  or other service lookups
- no superglobals (`$_GET`, `$_POST`, `$_SERVER`, ...): take input from the `Request`. Only
  the boundary that builds the kernel and the request reads them (`Kernel::fromGlobals()`,
  `FrontController::requestFromGlobals()`)
- no dependency on `Idno\Legacy\` (see "Legacy pages and routes")
- a new top-level directory that must not be served (code, configuration, data), or a new
  script meant to be run from the command line, needs
  deny rules in the root `.htaccess`, every sample config in `warmup/webserver-configs/`,
  `docker/caddy/Caddyfile`, `Tests/rig/router.php` and `Tests/build/router.php` (and a
  `Require all denied` `.htaccess` in the directory, as `var/`, `config/`, `bin/` and
  `Tests/` have), plus a SAPI guard at the top of each command-line script: under any SAPI
  but `cli`/`phpdbg`, `http_response_code(404)` and `exit` before loading anything (see
  `bin/console`). Scripts for PHP's built-in web server (routers) allow `cli-server` only.
  `Tests/Kernel/SapiGuardTest` and `Tests/Characterisation/DeniedPathsTest` pin these.
- New code logs by type-hinting `Psr\Log\LoggerInterface` in its constructor. Autowiring gives
  the site's logger (`Idno\Core\Logging`), which honours the configured log level and the line
  format. Never inject or fetch `monolog.logger.idno` (or any `monolog.logger.*` service)
  directly, and never call `error_log()`: both bypass the log level.
- The one exception: code in `Idno\Upgrade\` runs while the legacy application is NOT booted, so
  the site's logger does not exist there. It must not take `Psr\Log\LoggerInterface` by autowiring
  (that is the legacy logger); it logs its failures, and nothing else, on the Monolog channel
  `upgrade` (declared in `config/packages/monolog.php`, written by the `symfony` handler at warning
  and above), wired by name in `config/services.php`
  (`->arg('$logger', service('monolog.logger.upgrade'))`). Its argument is named `$logger`, not
  after the channel.
- `Psr\Log\LoggerInterface $idno` / `$idnoLogger` (and any other channel-named argument, such as
  `$request` or `$consoleLogger`) hand out the raw Monolog channel and bypass the log level and
  line format: do not use channel-named logger arguments.
- Temporary restriction: that logger is backed by the legacy application, so it exists only once
  legacy has booted. A service that takes `Psr\Log\LoggerInterface` cannot be constructed in a
  `bootLegacy: false` kernel, nor by anything Symfony instantiates during kernel boot (such as a
  `kernel.request` listener built at boot); `LegacyServiceFactory::logging()` throws a
  `\LogicException` saying so. In a unit test, pass a `Psr\Log\NullLogger` (or a test double) to the
  class's constructor directly. The restriction goes away when the level filter moves into Monolog
  in a later phase.
- Never put request data or other untrusted input into a log message without replacing control
  characters and bounding its length (see `Idno\Legacy\LegacyFallbackListener`).
- `\Idno\Core\Idno::site()->logging()` is the legacy accessor for the same object; do not use
  it in new code.
- Always pass `$overrideAccess = true` to `save()` on system-level entities (queue events, followers)
  to bypass `canEdit()` checks when no user session exists

## Configuration

Configuration has two layers.

- **Boot config** (`Idno\Config\BootConfig`, read by `Idno\Config\LegacyConfigLoader`):
  `config.ini`, `config.json` and environment variables. Immutable, read-only at
  runtime. `Idno\Core\Config` takes it through its constructor
  (`new Config(?BootConfig $bootConfig = null)`; null builds one from the project
  directory, `$_SERVER` and `$_ENV`). In the Symfony container `BootConfig` is built
  by a factory at runtime, so no value is ever compiled into the cached container.
- **Site settings** (`Idno\Config\SiteSettings`, implemented by
  `Idno\Legacy\LegacySiteSettings` over `Idno\Core\Config`): admin-editable settings
  stored in the database. `isOverridden($key)` is true when the boot config has that
  key. A value pinned in a file or environment variable overrides the stored one on
  every load, so changing it in the admin UI has no effect (the admin UI can still save
  it; the saved value is simply overridden). New code should use
  `SiteSettings::set()`, which refuses overridden keys with a `\LogicException`.

Boot config precedence, highest first (pinned by `Tests/Kernel/LegacyConfigLoaderTest.php`):

1. `IDNO_*` environment variables from `$_SERVER` (key = lower-cased name without the
   prefix, e.g. `IDNO_DBNAME` -> `dbname`; `IDNO_DOMAIN` -> `domain`)
2. Special variables from `$_ENV`: `IDNO_DATABASE_URL`, `CLOUDCUBE_URL`,
   `IDNO_AWS_S3_*`, `IDNO_UPLOAD_PATH` (each with a `KNOWN_` fallback; `IDNO_` wins).
   Within this group `IDNO_UPLOAD_PATH` beats the S3-derived `uploadpath`.
3. `configuration/config.json`
4. root `config.json`
5. root `config.ini`
6. `configuration/config.ini`
7. `KNOWN_*` variables from `$_SERVER`: fallback only, filling keys that no other
   source set

`path` and `host` can never be set; `uploadpath` always ends in a single `/`. The
former per-host `configuration/{host}.ini` file is no longer read (silently: the upgrade
notes in `docs/install/upgrade.md` tell sites to move its settings into `config.ini`), and
the CLI installer no longer writes one.

- Primary config: `configuration/config.ini` (INI format, gitignored)
- Environment variables come from the web server or the process environment. A `.env` file
  in the project root is loaded (with `vlucas/phpdotenv`) only by `Tests/_bootstrap.php`, i.e.
  under PHPUnit; the web front controller and the console do not read it.
- The Symfony kernel's `framework.secret` comes from `APP_SECRET` (a harmless default is used
  when it is unset; nothing uses it yet). It is deliberately not an `IDNO_*` variable: those
  are copied into the legacy config, which is saved to the database.
- Key settings: `database`, `dbname`, `dbhost`, `dbuser`, `dbpass`, `event_queue`,
  `filesystem`, `uploadpath`, `site_secret`
- `event_queue = 'AsynchronousQueue'` enables the async pipeline (required for ActivityPub)
- Sensitive files: `.env`, `config.ini`, `configuration/` — never commit these

## Security

- Entity access control via `canEdit()` / `canRead()` / access groups
- Unpublished entities: an entity whose publish status is set and is not `published`
  (a draft, for example) is visible only to its owner and to site admins, whatever its
  access setting. `Entity::isHiddenByPublishStatus()` holds the rule; `canRead()` applies
  it, and `getObjects()` on every database (through `EntityRepository::find()` on SQL sites) leaves such entities out of lookups
  (unless `setIgnoreAccess(true)` is in effect), so they are not found, exactly like
  entities the user has no access to; `EntityRepository::find()` does the same. An empty
  publish status counts as published. Counts (`countObjects()`, `EntityRepository::count()`) do
  NOT apply this rule: drafts the user cannot see are counted.
- HTTP Signatures (draft-cavage-http-signatures) for all ActivityPub federation
- CSRF tokens on all form POSTs to legacy pages (inbox endpoints bypass via `post()` override).
  New-style controllers have no authentication or CSRF protection yet (see "Adding a route");
  the upgrade step `POST /_upgrade/step` changes state without either, under the conditions
  stated there (no input selects what it does, nothing while the schema gate is open, bounded and
  serialised work, `no-store`, a same-origin path redirect, encoded output)
- Command-line scripts, `var/`, `config/`, `bin/`, `Tests/`, `docs/` and `warmup/CLI/` are
  denied over HTTP, and the scripts refuse to run under a web SAPI (see "New code conventions")
- HTML sanitization via HTMLPurifier
- SSRF protection: the plain client (`Webservice::send()` and its wrappers, the `idno.http_client`
  service) is for the site's own URLs and for services the site's ADMIN configured (the PuSH hub, a
  webhook, an API endpoint). Every other URL goes through `Webservice::safeSend()` or
  `Webservice::safeFileGetContents()` (new code: the injected `$remoteClient`), which refuse a host
  that resolves to a loopback, private, link-local or reserved address on the first request and on
  every redirect hop. That covers a URL a remote party supplied (a webmention's source, the endpoints
  a webmention target advertises, ActivityPub actor and key documents and follower inboxes, Micropub
  media URLs, the hubs and feeds a followed site advertises), a URL taken from content a remote
  server returned, and a URL a logged-in MEMBER typed (a feed to subscribe to, a shared or bookmarked
  URL, a Bridgy page): on a multi-user site a member is not the admin, and a page can redirect
  anywhere. `isSafeUrl($url)` alone does not cover redirects: it checks the first URL only. Calls to
  `\Idno\Core\UrlValidator::isSafeUrl()` stay before those fetches, to refuse early with a log line of
  their own; `Idno\Entities\UnfurledUrl::unfurl()` also keeps its own `gethostbyname()` check of the
  first URL (it refuses a host that resolves to a private or reserved address before any request
  is made; it does not see redirects, which the remote client now does). The fetches are
  `Idno\Pages\Webmentions\Endpoint::fetchSource()`, `Idno\Core\MentionClient`,
  `Idno\Core\Webmention` (`getIconFromURL()`, `addSyndicatedReplyTargets()`), `Idno\Core\Reader`,
  `Idno\Entities\Reader\Feed::retrieveItems()`, `Idno\Core\PubSubHubbub` (feed and topic fetches and
  the subscribe and unsubscribe requests to a hub a feed advertised; its publish ping to the hub of
  the site's own `hub` setting stays on the site client), `Idno\Pages\Entity\Share`, the following
  bookmarklet, `UnfurledUrl::unfurl()`, `IdnoPlugins\Like\Like::getTitleFromURL()`,
  `IdnoPlugins\Bridgy\Pages\Check`, `IdnoPlugins\ActivityPub\RemoteActor::fetchDocument()` (actor
  and key documents), `IdnoPlugins\ActivityPub\Delivery::deliver()` and
  `IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::fetchMedia()`. The webmention source, actor and
  media fetches are separate methods so that a test can call them on mock clients, because
  `isSafeUrl()` refuses the test rig's own address before they are reached
  (`Tests/Kernel/RemoteFetchCallSitesTest`; the flows the rig can drive are in
  `Tests/Characterisation/RemoteFetchTest`). Deliberate difference: a member can no longer
  subscribe to, unfurl, share or bookmark a URL on the site's own private network or on localhost.
  Not yet moved: `IdnoPlugins/StandardSiteSync/ATProtoClient.php` (raw curl) and the import of an
  attachment URL from an admin's export (`File::getFileDataFromAttachment()`, admin supplied).
- File access control: `Entity::save()` records the entity's UUID on each of its stored
  files (`attached_entity` in file metadata, via `FileSystem::updateMetadata()`), and
  `Idno\Pages\File\View` enforces the entity's `canRead()`. Entities that own extra
  files (e.g. Photo thumbnails) override `getAttachedFileIDs()`. Custom filesystems
  must implement `updateMetadata()` for this to apply.
- Redirect targets taken from user input must go through
  `\Idno\Pages\Session\Login::sanitizeRedirectUrl()` (same-origin only)
- Error page: an exception thrown while a page is being served goes to
  `Page::exception()`, which draws `templates/default/pages/500.tpl.php`. The page shows
  the exception's message to every visitor, and the whole exception (message and stack
  trace) when `debug` is set in the configuration. Both are passed through
  `htmlspecialchars()`, because a message can contain text taken from the request. So an
  exception message is plain text: HTML in it is displayed, not rendered. The message is
  still shown to visitors, so don't put anything in one that they shouldn't see.
  `Tests/Core/ErrorPageTest.php` checks this.
- Content Security Policy headers via template system (and, on the upgrade page, a strict
  per-response nonce policy: `UpgradePage::contentSecurityPolicy()`)
- Deleting a site's data from the web: only `/admin/legacytables`, a legacy page (admin gatekeeper,
  CSRF token, and the field `confirm` exactly `delete`), and only the `legacy_` tables of a completed
  upgrade; never the rejects. Its template escapes every value read from the database. The console
  equivalent needs `--drop-legacy --yes`
- Fatal-error page: the shutdown function `Idno\Legacy\FatalErrorPage` registers (`index.php` and
  `Idno/start.php` register it first, before the autoloader; `LegacyBootstrap` again, a no-op)
  answers a request that died of an uncaught error with a 500 page drawn by
  `statics/error-page.php`. The page tells visitors only that there was a problem. The message,
  file, line, stack trace and page address are written to PHP's error log. They are shown on the
  page only when `debug` is set in the configuration, and then passed through `htmlspecialchars()`,
  because they can contain text taken from the request. An error before the legacy application
  exists (while the kernel boots, so before the configuration is loaded) is treated as not in debug
  mode. `statics/error-page.php` prints `$body` as HTML, so escape anything put into it that is not
  fixed text. `Tests/Core/FatalErrorPageTest.php` checks this by running
  `Tests/Core/fixtures/fatal_error.php` in its own PHP process, and
  `Tests/Kernel/FatalErrorPageTest.php` the error during the kernel's boot.
- **Never commit** `.env`, `config.ini`, or anything in `configuration/`

## Git Conventions

- `IdnoPlugins/` is gitignored with explicit `!` exceptions for each tracked plugin
- `ConsolePlugins/` follows the same pattern
- `vendor/` is gitignored — use `composer install` to restore
- New plugin files must be `git add -f` due to the gitignore pattern
- `*.json` is gitignored (except `composer.json` which was tracked before the rule)

## Indieweb compatibility

All content is published as HTML, with Microformats2 markup. Any new content types 
that are publicly accessible should  include appropriate microformats classes: 
h-entry, h-card, h-event as appropriate.

## Agents code

Idno allows code that has been generated using AI models. When new code is written, 
it should be tested using the existing test suite. New tests MUST always be written
for new code, and existing tests MUST always pass. When there are architectural changes, 
the existing tests MUST be updated, and appropriate edits MUST be made to AGENTS.md.
