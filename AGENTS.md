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
composer test:up        # start the rig on SQLite: needs nothing but PHP (the default)
composer test:up:mysql  # start the rig on MySQL 8 in Docker (127.0.0.1:33306)
IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql  # the same, on MariaDB 10.6 (any MySQL or MariaDB image)
composer test           # run the full suite (core + plugins + unit tier); needs the rig, either mode
composer test:down      # stop the rig, whichever mode, and delete its database

# The dependency-free unit tier (Tests/Unit/): needs no rig, no database server, no configuration
composer test:unit

# Run one directory or file (a plugin's tests, the kernel tests, ...)
composer test -- IdnoPlugins/ActivityPub/Tests/
composer test -- Tests/Kernel

# Coding standard and PHP syntax check
vendor/bin/phpcs -n
php -l path/to/File.php

# The command line (Symfony's commands and the legacy console plugins), against the test rig
composer console -- list
```

- `composer test` is `IDNO_DOMAIN=127.0.0.1 IDNO_PORT=8089 php -d error_reporting=22527 vendor/bin/phpunit`:
  the rig's address, and PHP deprecations excluded (on PHP 8.5 legacy code emits them, and the rig's
  `pedantic_mode` turns them into exceptions). Do not run bare `vendor/bin/phpunit` locally. CI
  (`.github/workflows/tests.yml`) does run bare `vendor/bin/phpunit`, because its php.ini already
  excludes deprecations.
- Likewise run the console as `composer console -- <command>` against the rig; on a site without
  `pedantic_mode` it is `php bin/console <command>` (`idno.php` and `known.php` are aliases). See
  "Console commands" below.
- PHPUnit config: `phpunit.xml` (bootstrap: `Tests/_bootstrap.php`) for `composer test`;
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
  the storage contract bound to the rig's MySQL (`Tests/Storage/DbalMySqlRecordStoreTest`: `DbalRecordStore`
  with `MySqlDialect` on the `database_connection` service, which it also tests; the contract itself,
  `Tests/Storage/Contract/RecordStoreContract`, is dependency-free and also runs against the
  in-memory store and against `DbalRecordStore` on in-memory SQLite in `Tests/Unit/Storage`; its
  expectations are the MySQL store's, and the hooks a binding overrides are only where the
  databases really differ: `expectLongSearchTermToMatchSubstring`, `expectBooleanModeSearch`,
  `expectFourByteCharactersToBeRejected`, `expectTextComparisonToIgnoreCase`,
  `expectTextToSortFoldedToUpperCase`, or where the JSON store differs from today's (see "The JSON
  record store" under "Database"); the contract also runs against `JsonRecordStore` on in-memory
  SQLite in the unit tier (`Tests/Unit/Storage/JsonSqliteRecordStoreTest`) and on a private MySQL or
  MariaDB database of the rig's server (`Tests/Storage/JsonMySqlRecordStoreTest`, which skips on
  SQLite); the
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
  `Tests/Kernel/RigScriptsTest`):
  - **SQLite** (`composer test:up`, the default): no Docker, nothing but PHP. The database file
    (`site.sqlite`, with its `-wal`/`-shm` files) and the upload directory (`uploads/`) are in
    `<sys_get_temp_dir()>/idno-test-rig-<first 12 hex digits of the SHA-256 of the checkout's real
    path>/`, so two checkouts (git worktrees) never share a database or uploads. `up.sh` creates it and
    loads `warmup/schemas/sqlite/sqlite.sql` through a set-up connection (`Tests/rig/sqlite_schema.php`;
    a database that already has tables is left as it is). No step of this mode runs `docker`.
  - **MySQL** (`composer test:up:mysql`): MySQL 8 in Docker on 127.0.0.1:33306, database
    `known_unittest`, schema `warmup/schemas/mysql/mysql.sql` (as before); the upload directory is
    in the same per-checkout temporary directory. Use it for anything that touches the SQL dialect,
    search (MySQL's full-text index), the schema upgrader (`LegacySchemaUpgrader`) or MySQL's
    refusals (field names over 64 characters, 4-byte characters compared with a `utf8mb3` column,
    error codes): the tests bound to those skip on SQLite
    and say why. Run the whole suite on MySQL before merging such a change (CI runs SQLite, MySQL
    and MongoDB).
    `IDNO_RIG_MYSQL_IMAGE` selects the Docker image (default `mysql:8.0`; e.g. `mariadb:10.6`, whose
    image honours the `MYSQL_*` variables; the healthcheck and the schema load use `mysqladmin`/`mysql`
    or `mariadb-admin`/`mariadb`, whichever the image has). The image the database was started from
    is recorded in the per-checkout temporary directory (`mysql-image`; none recorded means
    `mysql:8.0`): `test:up:mysql` with another image stops the running database first (`down.sh`),
    and the container is started with `--renew-anon-volumes`, so whenever compose recreates it (a new
    image, or any change to the service in `docker-compose.yml`) it starts on an empty data directory
    with today's schema loaded, and the previous test data is gone; a re-run that needs no new
    container keeps its data. `down.sh` removes the container's data volume
    (`docker compose down -v`). On MariaDB 10.6 the existing suite fails where it pins MySQL 8's
    behaviour: the session `sql_mode` (`testTheConnectionMatchesTheLegacyBackendsConnection`,
    `testTheSessionMatchesTheLegacyConnection`) and the `created` operands MySQL rejects, which
    MariaDB accepts (`testCreatedQueriedWithAnIntegerTimestampFails`,
    `testCreatedRejectsTheLiteralsMysqlRejects`).
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
  - On SQLite, these skip, each with its reason: the storage contract against `DbalRecordStore` on
    the rig's MySQL (`DbalMySqlRecordStoreTest`; the contract runs on SQLite in the unit tier,
    `DbalSqliteRecordStoreTest`), and the `RepositoryDataConciergeTest` cases that need MySQL
    (`requireMysql()`: the old backend's MySQL connection and session-row formats, MySQL's refusals,
    full-text settings, the character sets of the schema and its upgrade to utf8mb4, `database`
    names that mean MySQL). `EntityRepositoryTest` runs on both. Nothing
    else skips (the unreachable-database tests run on both: a closed port on MySQL, a database file
    in a missing directory on SQLite); the characterisation suite runs in full on both.
- Three test suites: `Idno Basic Unit Tests` (`Tests/`), `Characterisation` (`Tests/Characterisation/`), `Plugins`
- CI (`.github/workflows/tests.yml`, on PHP 8.4 and 8.5 each): `phpcs`; the unit tier as its own job
  (`composer test:unit`, no services); the SQLite job, which uses the rig exactly as a developer does
  (`composer test:up`, then `composer test`; no service containers, no Docker); and the MySQL and
  MongoDB jobs, which do not use the rig: they copy `Tests/build/config_mysql.ini` (or
  `config_default.ini` for MongoDB) to `configuration/config.ini` and serve the site at
  `http://localhost/` with `php -S localhost:80` and `Tests/build/router.php`, on a fresh database,
  and run bare `vendor/bin/phpunit`. Tests must not assume a
  particular site URL (use the site's configured URL, e.g. `$this->site()->baseUrl()`), plugin list
  or stored content beyond what both the rig's and CI's configurations provide; derive expectations
  from the configuration or the filesystem. Both load the same plugins: keep `alwaysplugins[]` in
  `Tests/rig/config.ini`, `Tests/rig/config.sqlite.ini` and `Tests/build/config_*.ini` in step
  (`Tests/Kernel/RigScriptsTest` checks the rig's two and the two CI copies, `config_mysql.ini` and
  `config_default.ini`; `config_pgsql.ini` and `.travis.yml` are stale and unused). Every test that
  touches the database runs on MySQL and SQLite and either supports MongoDB or skips on it, saying
  why (the storage-bound classes key their skips on `ConnectionParameters::driverFamily()`).

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
  Http/                        FrontController (what index.php runs)
  Console/                     Idno\Console\Application, the CLI application (see Entry points)
  Config/                      BootConfig, LegacyConfigLoader, SiteSettings (modern style, Symfony-wired)
  Legacy/                      Shims between the kernel and the legacy application (see below)
  start.php                    Boots the legacy application for code that includes it (tests, scripts)
  Common/                      Legacy base classes (Entity, Page, Plugin, ContentType, Theme)
  Core/                        Legacy singletons & services (Idno, Config, Session, PageHandler, Webmention, ...)
  Data/                        The legacy MongoDB backend, Mongo.php (a MongoDB site's db()); on an SQL site
                               db() is Idno\Legacy\RepositoryDataConcierge (the old SQL backend that was here
                               has been removed, see the db() bullet)
  Storage/                     The storage interface (modern style): the RecordStore interface; the query
                               model (Criteria and Criterion/*, value objects parsed from the legacy search
                               arrays); RecordIndex (the metadata rows, search text and columns an SQL store
                               derives from a record, exactly as the old backend's MySQL::saveRecord() did,
                               and the query values of the JSON store); RecordDefaults
                               (what a store fills in: _id, uuid, siteid); InMemoryRecordStore, the reference
                               implementation the storage contract also holds to MySQL's behaviour;
                               MySqlSemantics (the rules MySQL applies by itself, in PHP, shared by the
                               in-memory store and the SQLite dialect: `created` literals, upper-case text
                               folding, search); DbalRecordStore, the RecordStore on a Doctrine DBAL connection
                               over the legacy schema, with what is engine-specific in Platform/ (Dialect,
                               MySqlDialect, SqliteDialect, and SqliteConnectionSetup, the DBAL middleware
                               that sets up every SQLite connection);
                               ConnectionParameters (legacy database settings -> DBAL parameters) and
                               BootConfigConnectionFactory (gives the DBAL connection those parameters at runtime);
                               EntityRepository (entities on a RecordStore, with the legacy access rules),
                               EntityQuery (its query) and AccessContext (who is asking; FixedAccessContext
                               for tests); SchemaMigrator (runs the schema migrations in Migrations/)
                               and SchemaCatalog (the tables and indexed fields that exist);
                               JsonRecordStore, the RecordStore on the tables the migrations create (not
                               used by the site yet), with TypeMap (a class's value in the `type` column),
                               AttributeTypeMap (the aliases the entity classes declare with
                               Attribute/AsEntity) and IdentityTypeMap (no aliases); RecordIndex::queryValues() is what a
                               query on a document field compares with, in that store and in memory;
                               IndexedFields (creates the indexes entity classes declare with
                               Attribute/IndexedField), EntityClassFinder (the classes of a directory that
                               carry those attributes) and DialectFactory (the container's Dialect)
  Entities/                    Core entity types (User, BaseObject, File, AccessGroup, etc.)
  Pages/                       Built-in legacy page handlers (Account, Admin, Entity, Feed, etc.)
config/                        Kernel configuration: bundles.php, services.php, routes.php,
                               packages/ (framework, monolog, doctrine), routes/test/ (test-only routes),
                               services_test.php (test-only services)
var/                           The compiled container (var/cache/); must be writable by the web server
                               (or set IDNO_CACHE_DIR). config/, bin/, var/ and Tests/ each hold a
                               deny-all .htaccess; none of them may be served (see Code Style)
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
Tests/                         Unit (Tests/Unit, dependency-free), kernel (Tests/Kernel) and black-box characterisation tests;
                               Tests/Fixtures holds test-only controllers, a command and a service;
                               Tests/rig/ is the local test rig, Tests/build/ the CI configs and router
```

### Entry points

These entry points build the kernel, with `Idno\Kernel::fromGlobals()`: environment from
`APP_ENV` (default `prod`), debug from `APP_DEBUG` (`1`/`true`/`on`/`yes` are true;
anything else, or unset, is false), both read from `$_SERVER`.

- **`index.php`** (HTTP): every web request. See the request lifecycle below.
- **`Idno/start.php`** (boot only): boots the kernel and so the legacy application, and
  returns. Used by `Tests/_bootstrap.php` and by third-party scripts that need
  `Idno::site()`. It does not handle a request. On a site with no configuration it sends
  the same redirect to `./warmup/` as `index.php` and exits.
- **`bin/console`** (CLI): first refuses to run under any SAPI but `cli` or `phpdbg` (a web
  server that executes it gets a 404 with an empty body). It then defines `IDNO_CONSOLE` (before the kernel boots, because it makes
  `LegacyBootstrap` skip the web-only boot steps), boots the kernel, and runs
  `Idno\Console\Application`, a subclass of FrameworkBundle's console application. On a
  site with no configuration it prints one line to stderr and exits 1; on any other
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

### Request lifecycle

1. `index.php` checks the PHP version, registers the fatal-error page
   (`Idno\Legacy\FatalErrorPage`), loads the autoloader,
   then runs `Idno\Http\FrontController`.
2. `FrontController::requestFromGlobals()` builds the Symfony `Request` (it never parses
   the body, so `php://input` stays readable for legacy code), and
   `FrontController::handle()` calls `Kernel::handle()`.
3. The kernel boots: the container is built (or loaded from cache) and the legacy
   application is booted by `Idno\Legacy\LegacyBootstrap` (configuration, `Idno::site()`,
   plugins, the legacy session — `session_start()` happens here, before routing). A site
   with no configuration throws `NotInstalledException`, which `FrontController` turns
   into the redirect to `./warmup/`.
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

- Put a controller class with a `#[Symfony\Component\Routing\Attribute\Route]`
  attribute in a directory that `config/routes.php` imports attribute routes from
  (today only `Idno/Legacy/`; add a new directory, e.g. `Idno/Http/Controller/`, to
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
  in `prod`. Console code may take a `Psr\Log\LoggerInterface`: the console always boots the
  legacy application.
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
catch-all described above. Do not add new ones; add a controller instead.

`Idno\Legacy\` is where the shims between the kernel and the legacy application live
(`LegacyBootstrap`, `LegacyServiceFactory`, `LegacySiteSettings`, `LegacyController`,
`LegacyDispatcher`, `LegacyResponse`, `LegacyFallbackListener`, `FatalErrorPage`,
`VerbatimLogFormatter`, `NotInstalledException`, and for storage `LegacyRecordStoreFactory`,
`LegacyRecordDefaults`, `LegacyAccessContext`, and the `db()` service of SQL sites,
`RepositoryDataConcierge`, with `LegacySchemaUpgrader` and `LegacySqlMaintenance`). They
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
  `Kernel::boot()` boots the legacy application through `Idno\Legacy\LegacyBootstrap`
  (pass `bootLegacy: false` to the constructor to boot the kernel alone, as some tests do).
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
  `Console`, `Legacy`, `Storage`) and registers the classes as autowired, autoconfigured
  services, except the files it excludes (classes that are not services, or that are wired
  by hand). Value objects and static helpers in a scanned directory are not services and
  must be added to the `$excludes` map in `config/services.php` (today in `Idno/Storage/`:
  `ConnectionParameters.php`, `Criteria.php`, `Criterion/`, `EntityClassFinder.php`, `EntityQuery.php`,
  `Attribute/` (attribute classes), `FixedAccessContext.php`, `FixedRecordDefaults.php`, `IdentityTypeMap.php`, `Migrations/` (the schema migrations),
  `MySqlSemantics.php`, `Platform/`, `RecordDefaults.php` and `RecordIndex.php`; in `Idno/Legacy/`, `RepositoryDataConcierge.php`,
  `LegacySchemaUpgrader.php` and `LegacySqlMaintenance.php`, which `LegacyServiceFactory::db()`
  builds), or the container will try to autowire
  them. `RecordStore` implementations (`InMemoryRecordStore.php`, `DbalRecordStore.php`,
  `JsonRecordStore.php`) are excluded too: the container's `RecordStore` is built by a factory (see
  the storage services below). The container's `Idno\Storage\TypeMap` is `AttributeTypeMap` (an alias in `config/services.php`). `BootConfigConnectionFactory.php` is
  excluded from the scan and wired by hand. In `Idno/Kernel/`, the compiler pass
  `DoctrineTrimPass.php` is excluded (the kernel adds it in `build()`).
- The database connection: DoctrineBundle's `database_connection` service (`doctrine.dbal.default_connection`,
  autowired as `Doctrine\DBAL\Connection`). `config/packages/doctrine.php` configures no credentials:
  `Idno\Storage\BootConfigConnectionFactory` decorates the bundle's connection factory and, when
  the service is first fetched, replaces the parameters with `ConnectionParameters::fromLegacyConfig()`
  of the `BootConfig` (plus the `RDS_*` environment variables for `beanstalk-mysql`), so no
  configuration value is compiled into the container. It reproduces the legacy connection
  exactly: DSN charset `utf8mb4` (the search text and indexed values are utf8mb4 since schema
  2026111401; see "MySQL schema" under "Database"), `Pdo\Mysql::ATTR_LOCAL_INFILE => 1`,
  `ERRMODE_EXCEPTION`, emulated prepares, the same session `sql_mode` and time zone. On a site whose
  `database` is `sqlite` (`dbname` is the database file's path, or `:memory:`), the factory also
  adds `Idno\Storage\Platform\SqliteConnectionSetup` to the connection's middlewares; a MySQL
  connection is built exactly as before. The service is
  lazy: fetching it does not connect (the first query does; on an SQL site the `db()` service
  fetches it and connects it during `Idno::init()`, see the `db()` bullet below). What the
  `database` setting means is decided by `ConnectionParameters::driverFamily()`, which the
  connection factory and `LegacyRecordStoreFactory` use for every value:
  `mysql`/`beanstalk-mysql` → `mysql`, `sqlite` → `sqlite`, `mongo`/`mongodb` → `mongo`; any other
  value is `other` when it names a loadable backend class the way the legacy
  `Idno::componentFactory()` reads it (a full class name, or a class in `Idno\Data\`, extending
  `Idno\Core\DataConcierge`; `ConnectionParameters::backendClass()`), and otherwise `mysql`, because
  `componentFactory()` fell back to the old MySQL backend for a name it could not load.
  `LegacyServiceFactory::db()` decides three names itself, in its own `switch`, before it asks
  `driverFamily()`: `mongo`/`mongodb` (case-insensitive, trimmed) build `Idno\Data\Mongo`, and
  `beanstalk-mysql` copies the `RDS_*` server variables into the legacy config and builds the adapter
  on MySQL; every other value goes to `driverFamily()` (`mysql`/`sqlite`: the adapter; `other`: the
  class, through `componentFactory()`). Both agree on those three names (`mongo`, and `mysql` for
  `beanstalk-mysql`, whose `RDS_*` mapping `ConnectionParameters::fromLegacyConfig()` repeats for the
  connection). Keep them in step if you change either. On a MongoDB or
  `other` site the container compiles and the kernel boots as before, but fetching the
  service throws a `\LogicException`: nothing may fetch the connection while the kernel boots (no
  `kernel.request` listener, no service built at boot), and code that uses it must only run on an
  SQL site. Query logging and profiling are off (`config/packages/doctrine.php`). DoctrineBundle
  brings only the connection: `Idno\Kernel\DoctrineTrimPass` (a compiler pass added in
  `Kernel::build()`) removes every console command the bundle registers (no `doctrine:*` or
  `dbal:*` command exists; `dbal:run-sql` and `doctrine:database:drop` would act on the site's
  database) and its idle-connection `kernel.request` listener. `Tests/Kernel/DoctrineTrimTest`
  pins both in the test and prod containers. Do not add them back.
- SQL against the legacy tables (`DbalRecordStore`): bind EVERY value as a string, as the legacy
  backend does (true is '1', false '', numbers their text, null NULL); the contract pins behaviour
  that depends on it (`$lt` on a document field compares text, `created` operands that are not
  datetime literals make MySQL fail the query). Never interpolate a value; identifiers are
  collection names reduced to `[a-zA-Z0-9_]` and quoted by the `Dialect`. A collection exists
  when its three tables do (`<name>`, `<name>_metadata`, `<name>_search`, listed once and again
  when a name is not found, without regard to case when the server's `lower_case_table_names`
  is not 0); any other name gives false, null, `[]` or 0 without querying it. A SELECT the
  database rejects for its operand matches nothing (`Dialect::isRejectedOperand()`; on MySQL,
  error 1525 for a `created` operand that is not a datetime, and error 1064 only for a statement
  holding a boolean-mode full-text search, whose parser rejects terms such as `abc@def.com`);
  every other driver error (lost connection, broken SQL) propagates. A write returns false when
  the database refuses the record's data (`Dialect::isRefusedData()`, an allowlist: constraint
  violations, deadlock and lock-wait, and on MySQL 1292, 1366, 1406), after `save()` has rolled
  its transaction back; every other error propagates. So `save()` returns the id only when its
  transaction committed, false when the data was refused, and throws otherwise. The `RecordStore`
  interface states this error contract for every implementation (what returns false, `[]`, 0 or
  null; everything else is thrown, from every method); `Tests/Unit/Storage/DbalRecordStoreFailureTest`
  pins the throwing side (a failure on any statement of `save()` is thrown, rolled back, stores
  nothing and leaves the connection usable).
- SQLite (`Idno\Storage\Platform\SqliteDialect`, schema `warmup/schemas/sqlite/sqlite.sql`, SQLite
  3.38 or later with the JSON functions, through `pdo_sqlite`; the connection setup refuses an older
  library, or one built without `json_valid()`, with a `\LogicException` naming the version
  required and the one found): the same
  store, held to the same contract with the in-memory store's hook values. **In this release SQLite
  is for tests and development only** (it is to become a supported production database in a later release): no
  site is installed on it (the installer does not create SQLite sites; the test rig's SQLite mode is
  the one SQLite site, see "Build & Test"), and an SQLite site's PHP sessions are not locked (see the `db()` bullet). Every SQLite connection the store uses MUST have `SqliteConnectionSetup` among its
  DBAL middlewares (the `database_connection` service of an SQLite site has it; a connection you
  build yourself needs `(new Configuration())->setMiddlewares([new SqliteConnectionSetup()])`, as
  `Tests/Unit/Storage/SqliteTestDatabase` does; the middleware refuses any connection that is not
  `Pdo\Sqlite`): on each new physical connection it sets `busy_timeout = 5000`,
  `journal_mode = WAL` and `foreign_keys = ON` and registers the `idno_ci` collation (text folded
  to upper case, `MySqlSemantics::compare()`) and the `idno_search()` function. The dialect is
  built for the store's connection, `new SqliteDialect($connection)`: it reads each table's indexes
  there once to name the upsert's conflict target. Do not repeat MySQL's rules in SQL or PHP:
  `MySqlSemantics` is their one copy.
  - Limits of the database file (the `idno_ci` collation; also in the schema's header): outside a
    set-up connection (e.g. the `sqlite3` command line) every SELECT, INSERT, UPDATE or DELETE on
    the collection tables and `versions`, `PRAGMA integrity_check`/`quick_check` and `VACUUM` fail
    with "no such collation sequence: idno_ci" (only `session` is usable), and a `.dump` cannot be
    loaded into a plain `sqlite3`. A backup that works: a copy of the file with its `-wal`/`-shm`
    files while no writer is active, or `sqlite3`'s `.backup`. Checks, VACUUM, REINDEX and restores
    only through a set-up connection. The collation's order comes from PHP's mbstring case tables,
    which PHP releases change: after a PHP upgrade, run `PRAGMA integrity_check` and
    `REINDEX idno_ci` through a set-up connection.
  - WAL needs shared memory between the processes using the file: never put the database on a
    network filesystem (NFS, SMB).
  - Differences from MySQL: search never uses a full-text index (a term over 5 bytes
    matches any of its words as a case-insensitive substring, whatever `boolean_search` and
    `bypass_fulltext_search` say; no boolean operators, no parse errors); four-byte characters are
    stored; a `created` operand MySQL would reject is caught by `Dialect::columnValue()` before the
    query is built (the query matches nothing, as on MySQL); lengths are not enforced (a field name
    over 64 characters is stored, MySQL refuses it); table (collection) names ignore case; case
    folding is full Unicode (`mb_strtoupper`), so it equates strings MySQL's `utf8mb3_general_ci`
    (the identifier columns) keeps apart (`'ß'` = `'SS'`, `'ﬁ'` = `'FI'`, `'ǅ'` = `'Ǆ'`): uuids
    `straße` and `STRASSE` clash on SQLite and not on MySQL; text is ordered folded to upper case,
    where MySQL orders the indexed values by `utf8mb4_unicode_520_ci` (punctuation such as `_`
    sorts before the letters on MySQL and after them on SQLite: the contract hook
    `expectTextToSortFoldedToUpperCase`); in `site` and `config`, whose `_id` is not unique, the foreign keys
    of `_search`/`_metadata` are triggers (SQLite cannot reference a non-unique column) that cascade
    as InnoDB does. The upsert names its conflict target (`_id` where a unique index is on `_id`
    alone: `entities`, `reader`, the `_search` tables; else the primary key, `uuid`: `site`,
    `config`), where MySQL's `on duplicate key update` takes whichever key conflicts. The outcome
    differs in one case: in `entities`/`reader`, an existing record re-saved with ANOTHER existing
    record's uuid is refused on SQLite (false, both rows intact), while MySQL overwrites the other
    record's row with this document and returns the id. Re-saves, a new record with an existing
    uuid (false on both), an existing record with a new uuid, and every case in `site`/`config` are
    the same on both (pinned in `DbalSqliteRecordStoreTest`).
  - A write that cannot get the write lock within the busy timeout ("database is locked") is
    refused data: `save()` returns false (two processes writing at once both succeed: each waits for
    the other's lock, `SqliteConcurrencyTest::testConcurrentWritersDoNotFail`). The installer does not
    create the SQLite schema for a site; the test rig loads it (`Tests/rig/sqlite_schema.php`).
- The storage services, public, all built at runtime when first fetched: `Idno\Storage\RecordStore`
  (a `DbalRecordStore` built by `Idno\Legacy\LegacyRecordStoreFactory`: on the `database_connection`
  service, `MySqlDialect` for a MySQL site and `SqliteDialect` on the same connection for an SQLite
  site, `Idno\Legacy\LegacyRecordDefaults` (what the old backend's `saveRecord()` filled in), the legacy quote
  normaliser, and the site's `boolean_search` / `bypass_fulltext_search`, read from the legacy config
  each time a search condition is built, as the legacy backend did (`DbalRecordStore` takes each as a
  bool or a closure); on a MongoDB or `other` site it throws a `\LogicException` without touching the
  connection),
  `Idno\Storage\AccessContext` (`Idno\Legacy\LegacyAccessContext`: the legacy session's user,
  admin status and read groups, and the `db()` service's ignore-access flag, read at each call;
  with no session object it is anonymous with read groups `['PUBLIC']`) and
  `Idno\Storage\EntityRepository` (autowired from those two and the logger). Like the logger, they
  need the legacy application: do not fetch them in a `bootLegacy: false` kernel or while the kernel
  boots (nothing does; the container compiles and the kernel boots on every database, MongoDB
  included). On an SQL site `Idno::site()->db()`, and so every legacy `Entity::get*()` finder, uses
  them (next bullet).
- The `db()` service (`Idno::site()->db()`, `Idno\Core\DataConcierge`), built by
  `LegacyServiceFactory::db()`: on a MongoDB site `Idno\Data\Mongo`, exactly as before; on a site whose
  `database` names a loadable backend class, that class (`componentFactory()`, as before); on a MySQL
  (`mysql`, `beanstalk-mysql`, or a name that is no loadable class) or SQLite site
  `Idno\Legacy\RepositoryDataConcierge`, the adapter that serves the public API of the old SQL backend
  (since removed: same method names, parameters, defaults, return and failure values, as
  listed in `RepositoryDataConciergeTest::OLD_BACKEND_METHODS`, except `build_where_from_array()`,
  which nothing outside that backend called) from the
  storage layer: records through the `RecordStore` service, `getObjects()`/`countObjects()`/`getObject()`
  through the `EntityRepository` service; `rowToEntity()`, `saveObject()` and `getAnyObject()` are
  `DataConcierge`'s. It holds no SQL: the schema upgrade and `getVersions()` are in
  `Idno\Legacy\LegacySchemaUpgrader` (the old backend's `checkAndUpgradeSchema()` moved unchanged; a
  no-op on SQLite), `optimize()`, `exportRecords()` and the connection check of `reconnectIfNeeded()` in
  `Idno\Legacy\LegacySqlMaintenance`, all on the shared connection. `getClient()` is the native PDO
  of the `database_connection` service (asked of the connection on each call, never a kept copy: a
  `Pdo\Mysql` on MySQL, a `Pdo\Sqlite` on SQLite), and `handleSession()` installs the same
  `PdoSessionHandler` with the same options on that PDO (advisory locks on MySQL; NONE on SQLite,
  which PdoSessionHandler refuses advisory locks on: parallel requests of one session race, so SQLite
  sessions are unsafe beyond tests and development, like SQLite itself in this release), so a request
  opens ONE database connection and session rows written before the switch are read as before.
  `handleSession()` returns null, as the old backend's did, so `Idno\Core\Session` goes on to its
  `sessions_storage` step even when `sessions_database` (on by default) installed the handler.
  The old SQL backend classes (`Idno\Data\MySQL`, `Idno\Data\AbstractSQL`) have been removed.
  A site whose `sessions_storage` setting names `Idno\Data\MySQL` (in any case, with or without a
  leading backslash, as PHP matched the class name; it used to build a second connection whose
  handler replaced the adapter's) keeps its database sessions: `Idno\Core\Session` calls the
  adapter's `handleSession()` instead, so the same `session` table and handler are used on the
  shared connection, with `sessions_database` on or off (so that users stay logged in across the
  upgrade; pinned in
  `RepositoryDataConciergeTest::testASessionsStorageSettingNamingTheOldBackendKeepsDatabaseSessions`).
  On a MongoDB site that setting now does nothing: such a site (sessions in a MySQL database next
  to MongoDB) falls back to file sessions, so its users are logged out once at the upgrade
  (`docs/install/upgrade.md` says so). Any other `sessions_storage` value behaves as before.
  `database` values naming the old class (`Idno\Data\MySQL`) or any other name that is no class
  mean MySQL, as they always did. The facts to keep:
  - Construction order: `Idno::init()` fetches `db()` early, before the logger, the session and the
    language exist. The adapter's constructor connects (as `MySQL::init()` did, at the same point
    of boot) and runs the schema upgrade; the record store, the repository and the logger are
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
      save the database refuses (a field name over 64 characters, a 4-byte character in a field on a
      metadata table still in utf8mb3, ...) returns false and stores nothing (an update leaves the previous
      version). The old backend returned the id of a save it had rolled back when a metadata row
      failed, so `Entity::save()` reported success and fired `saved`/`updated` (and federation) for a
      record that was not stored; now it returns false and fires neither;
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
  - A search array `Criteria::fromLegacyArray()` rejects (an array operand, `'$not' => ['$in' =>
    []]`, a non-array `$in`, ...) matches nothing (`getRecords()` false, `getObjects()` `[]`, the counts
    false) and is logged as ONE warning naming the method and the query's shape: keys and operator
    names only, never values, control characters replaced, at most 200 bytes
    (`RepositoryDataConcierge::shape()`).
  - The record store lists the database's tables once per process (`show tables` and
    `select @@lower_case_table_names`): two statements per web request more than the old backend
    sent, on the same connection.
  New code never calls `db()`: inject `EntityRepository` (entities) or `RecordStore` (records).
- Querying entities in new code: inject `Idno\Storage\EntityRepository` and query with an
  `EntityQuery` (class names, a `Criteria`, limit, offset, collection, read groups); never call
  `Idno::site()->db()` and never write SQL against the table names. `find()`, `findOneByUuid()`
  and `hydrate()` build legacy entities, so they need the legacy application (without it, hydration
  fails, is logged, and `find()` returns `[]`); only `count()` and the criteria it builds can be
  unit-tested without it, with `Idno\Storage\FixedAccessContext` (constructor-set user, admin flag,
  read groups, ignore-access; not a service) on an `InMemoryRecordStore`
  (`Tests/Unit/Storage/EntityRepositoryCriteriaTest`). The rules it applies are today's
  `getObjects()` / `countObjects()` / `getObject()` (pinned by `EntityQueryContract` against both),
  with the two deliberate differences marked below:
  - types match the stored class name exactly (no leading backslash, no subclasses); a leading `!`
    excludes; with one inclusion, exclusions are dropped;
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
  standard line format with channel and level. An ordinary legacy request logs nothing
  through it; an exception Symfony handles (before routing, or from a new-style controller)
  is one `request.CRITICAL` line (`request.ERROR` for an HTTP exception below 500), and a
  console command that fails is one `console.CRITICAL` line (on stderr when PHP's
  `error_log` setting is unset). A test that makes Symfony handle an exception should point
  `error_log` at a temporary file (`ini_set()`) to keep it out of the test output, as
  `Tests/Kernel/KernelExceptionTest` does. See Code Style.

### Key design patterns

- **Entity system**: All content inherits from `Idno\Common\Entity` (→ `BaseObject`).
  Entities are stored as documents (MongoDB) or serialized blobs (MySQL). Key
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

- **MongoDB** (`Idno\Data\Mongo`) — original backend, stores entities as native documents
- **MySQL** — stores entities as JSON blobs, through `Idno\Storage\DbalRecordStore` on the DBAL
  connection; `db()` is `Idno\Legacy\RepositoryDataConcierge` over it (see the `db()` bullet under
  "Kernel and services"). The hand-written SQL backend that preceded it has been removed;
  `docs/install/upgrade.md` tells plugin authors what replaces it and what behaves differently.
- **SQLite** — the same store with `SqliteDialect` (schema: `warmup/schemas/sqlite/sqlite.sql`);
  `database = sqlite` (with `dbname` the file's path) gives `db()` the same adapter. For tests and
  development in this release (the default test rig runs on it; the installer does not create the
  schema for a site); it is to become a supported production database in a later release. See the SQLite
  bullet under "Kernel and services" for the limits of the database file.
- Configured via `config.ini` (`database` / `dbname` / `dbhost` / etc.)

#### The new schema (migrations; not used by the site yet)

The site still runs on the schema below (`warmup/schemas/`). Its successor is created by schema
migrations, and nothing reads or writes it yet: no site or rig database has its tables unless a
test made them.

- `doctrine/migrations` is used as a library only (no bundle, no console command, no configuration
  file): `Idno\Storage\SchemaMigrator` (a container service; it takes only the DBAL `Connection` and
  queries nothing when constructed; nothing fetches it while the kernel boots) builds a
  `DependencyFactory` on that connection with the migrations namespace `Idno\Storage\Migrations`,
  the directory `Idno/Storage/Migrations/` and the metadata table `idno_migrations` (created by
  `migrate()`). `pending()` lists the versions not yet executed (`'20261115000000'`, the numeric
  part of the class name; asking creates nothing), `migrate()` executes them (a no-op when none is
  pending), `latest()` is the newest executed version, `'0'` when none. It writes no output.
- Migration classes (`Idno/Storage/Migrations/Version<YYYYMMDDhhmmss>.php`) are not services
  (excluded in `config/services.php`). Each holds literal SQL per platform (MySQL/MariaDB and
  SQLite; any other platform aborts) and never changes once committed: a schema change is a new
  migration. On MySQL and MariaDB a migration is not transactional (DDL commits implicitly), so a
  run can be interrupted after some statements with no version recorded: every statement must be
  safe to repeat (`CREATE TABLE IF NOT EXISTS`, and on SQLite `CREATE INDEX IF NOT EXISTS`), so that
  the next run completes the schema (pinned by `testAnInterruptedBaselineCanBeRunAgain` on SQLite
  and MySQL).
- The baseline, `Version20261115000000` (irreversible), creates: `idno_entities`, `idno_reader`,
  `idno_site` (one row per document: `id`, `uuid` (unique), `type`, `owner`, `created` (epoch
  seconds, `BIGINT`), `publish_status`, `data` (the document's JSON text, `LONGTEXT` `utf8mb4_bin`),
  `search` (full-text indexed on MySQL/MariaDB); keys `type_created`, `owner_created`, `created`);
  `idno_settings` (`name` and `value` `utf8mb4_bin`, `position` INT: one row per settings key, in
  order); `idno_sessions` (the `session` table's columns, `session_id` `utf8mb4_bin`);
  `idno_upgrade_progress` and `idno_upgrade_rejects` (the upgrade's bookkeeping). On MySQL/MariaDB
  every table is InnoDB, `utf8mb4`, and every text column names its collation:
  `utf8mb4_general_ci` except the `utf8mb4_bin` ones above. The one exception is `idno_migrations`,
  which doctrine/migrations creates itself with the database's default charset and collation; it
  holds only migration class names and timings. On SQLite the same tables, with
  `COLLATE NOCASE` on `id`, `uuid`, `type`, `owner` and `publish_status`, `INTEGER` for `created`,
  index names prefixed with the table's (`idno_entities_type_created`), no full-text index, and no
  custom collation or function, so the file opens with standard SQLite tools.
- `Idno\Storage\SchemaCatalog` (a container service on the `Connection`; queries nothing when
  constructed): `tables()` (lower case, sorted; SQLite's internal tables left out), `hasTable()`
  (ignores case), `indexedFields($collection)` (the fields with an index on `idno_<collection>`:
  on MySQL/MariaDB a generated column `ix_<field>`, on SQLite an index `idno_<collection>_ix_<field>`;
  field names `[a-z0-9_]{1,48}`, returned without the prefix). It reads everything with ONE
  statement at the first question (`information_schema.columns` of the current database;
  `sqlite_master`) and keeps it until `forget()`: call that after DDL.
- `Idno\Storage\JsonRecordStore` is the `RecordStore` on these tables (see "The JSON record store"
  below). Nothing in the site builds it yet: the container's `RecordStore` is still `DbalRecordStore`.
- Tests: `Tests\Storage\PrivateDatabase` makes migrated databases of a test's own.
  `sqliteMemory()` (an in-memory database with `SqliteConnectionSetup`) needs nothing but PHP and
  works in the unit tier; `mysql($purpose)` creates `<site dbname>_<purpose>` on the rig's MySQL or
  MariaDB server (dropping a leftover first; the site's dbname must match `^[A-Za-z0-9_]+$`) and
  returns a new connection to it (never the site's), dropping it again if the migrations fail, and
  skipping the test on an SQLite or MongoDB site; `drop()` removes either (call it in `tearDown()`;
  it refuses to drop the site's own database). `Tests/Unit/Storage/SchemaMigratorTest` (SQLite) and
  `Tests/Storage/SchemaMigratorMySqlTest` (MySQL and MariaDB) pin the baseline and the catalog.

#### The JSON record store (not used by the site yet)

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

- `save()` is ONE upsert on `id` (`Dialect::upsertById()`): `data` is `json_encode($record)` with
  today's flags (none), so a document copied byte for byte from a 1.x `contents` reads the same;
  `type` is `TypeMap::typeFor()` of `entity_subtype` (default `Idno\Common\Entity`), `created` the
  timestamp (`RecordIndex::created()`), `search` `RecordIndex::searchText()`. A record is read back
  as `json_decode($data, true)`, never rebuilt from the columns. On MySQL/MariaDB every update of
  the upsert is guarded by `id = values(id)`, because `on duplicate key update` would otherwise update
  the row of another record that holds the uuid; when the statement affects no row, the store checks
  that the record is there (an identical re-save) or returns false (another record's uuid). Do not
  give the connection `PDO::MYSQL_ATTR_FOUND_ROWS`: it changes what "no row" means.
- Queries: `uuid`, `_id` (column `id`), `entity_subtype` (column `type`, each operand expanded
  through `TypeMap::typesFor()`; `=` and `$not` become `in` and `not in`), `owner`,
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
  utf8mb4_general_ci`. SQLite: `json_each()` and `->` (built-ins only), `collate nocase`, and
  `upper(...) collate binary` for `$lt`/`$gt`. Order: `created` desc, `id` desc.
- Search: today's rules on the `search` column (MySQL/MariaDB: the full-text index with the site's
  `boolean_search` and `bypass_fulltext_search`; SQLite: `like` for terms of up to 5 bytes, `instr()`
  for each word of a longer one, built-ins only, ASCII case only).
- Errors: the `RecordStore` interface's contract (`Tests/Unit/Storage/JsonRecordStoreFailureTest`
  pins the throwing side, the schema catalog's read included).
- Differences from today's store, each a contract hook (in `RecordStoreContract`; the in-memory
  store has the JSON store's value of every one, as on MariaDB, except the `created` time zone: it
  keeps `created` as the text formatted at save time):
  records with an equal `created` are ordered by `id` descending (`expectEqualCreatedToBeOrderedById`);
  values are compared whole, never cut at 255 bytes (`expectValuesToBeCutAt255Bytes`); an element of
  a list or object that is itself an array or object is not queryable, where today it is queried as
  its JSON encoding (`expectNestedArraysToMatchTheirJson`; a field holding a list or an object is
  queryable by its scalar values, never its keys, on every store, as today);
  four-byte characters and field names over 64 characters are stored (`expectFourByteCharactersToBeRejected`,
  `expectLongFieldNamesToBeRefused`); re-saving a record with another record's uuid is refused, both
  records intact (`expectUuidClashOnResaveToOverwrite`); on SQLite, text ignores ASCII case only
  (`expectNonAsciiCaseToBeIgnored`); a field is found by its exact name (`expectFieldNamesToIgnoreCase`);
  `created` is a timestamp, so a query's datetime literal is read in the time zone of the query, not
  compared with the text formatted at save time (`expectCreatedToBeComparedInTheTimeZoneOfItsSave`);
  a field with a declared index (#[IndexedField], below) matches scalar values only: a record whose
  field holds a list or an object is not found by a query on it once the index exists (no hook:
  pinned by `IndexedFieldsContract::testAListValueIsNoLongerMatchedOnceTheFieldIsIndexed`);
  a float is queried as the text `json_encode()` wrote (`0.30000000000000004`, not PHP's 14-digit
  `0.3`; `expectFloatsToMatchTheirJsonText`), and a float OPERAND of a document-field condition is
  converted the same way (`RecordIndex::floatText()`, shared by the stored values and the operands
  in the JSON and in-memory stores), so a float always finds itself, as it does today; only a string
  operand such as `'0.3'` tells the rules apart. The exception: MySQL (not MariaDB) prints a number
  in exponent form its own way (`9.999999999999999e24` for `1.0e+25`, `0.00001` for `1.0e-5`), so
  there such a float is found neither by its text nor by the same float, only by MySQL's text
  (`expectFloatsInExponentFormToMatchTheirJsonText`, the one hook whose value differs between MySQL
  and MariaDB).

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
  (`LONGTEXT`, `utf8mb4_general_ci`) and an index `ix_<field>` on its first `length` characters
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
  created. An index is never dropped or altered: a removed declaration leaves its index, and a
  declaration whose options differ from the existing index changes nothing and is listed by
  `conflicts()` (of the last call). Every declaration is read and validated before any DDL.
  `reconcileDirectory($dir)` reconciles what `EntityClassFinder::in($dir)` finds: PHP files with a
  line that starts (after indentation) with `#[AsEntity` or `#[IndexedField` (imported or fully
  qualified; a mention in a comment or docblock does not count), the class name derived
  from the path (PSR-4 under the project, or under the nearest `Idno`/`IdnoPlugins` directory outside
  it), loaded through the autoloader (never `require`), confirmed by reflection; a file that fails to
  load is skipped.
- When it runs: `Idno\Pages\Admin\Plugins` reconciles a plugin's directory when the plugin is enabled
  (skipped on a non-SQL site; a failure is logged and shown as an error message, and the plugin is
  enabled anyway); the upgrade will reconcile the core and enabled plugins. Until the site uses the
  new tables, the site's database has no `idno_*` tables, so enabling a plugin creates nothing.
- Core declarations (pinned by `Tests/Kernel/EntityAliasesTest`): `Idno\Common\Entity`: `access`,
  `slug`, `shorturl`, `url`, `canonical`; `User`: `handle`, `email`, `admin`; `Site`: `domain`;
  `Invitation`: `email`, `code`; `AsynchronousQueuedEvent`: `queue`; `UnfurledUrl`: `source_url`;
  `Reader\Feed`, `Reader\Subscription`, `Reader\FeedItem`: `feed_url`; `StaticPage`: `category`;
  `ActivityPubFollower`: `actor_uri`, `user_uuid`. None is unique (nothing enforces uniqueness today;
  a unique index would make saves fail on existing sites). A new declaration on an existing
  first-party class ships with an empty migration, so that sites reconcile on upgrade.
- Plugin authors: `docs/developers/plugins/indexed-fields.md`.

#### MySQL schema

- Each collection (`site`, `config`, `entities`, `reader`) is three tables: the main
  table with the JSON document in `contents`, `<collection>_search` with the text for
  full-text search, and `<collection>_metadata` with one row per field (cut to 255
  bytes) for queries.
- `warmup/schemas/mysql/mysql.sql` creates a new installation. The dated files beside it
  are upgrades: `Idno\Legacy\LegacySchemaUpgrader::upgrade()` runs on every connection (when
  the `db()` adapter is built) and applies, in order, each file listed in
  `LegacySchemaUpgrader::UPGRADES` that is newer than the `schema` row of the `versions` table.
  To change the schema, add a dated file that ends by recording its date in `versions`, add
  the date to `UPGRADES`, make the same change in `mysql.sql` (including its version line),
  and set the version row of `warmup/schemas/sqlite/sqlite.sql` to the same date.
  `RepositoryDataConciergeTest` checks that an upgraded database and a new one agree.
- An upgrade that fails is logged and tried again from the top on the next connection,
  and MySQL can't roll back an `ALTER TABLE`, so every statement in an upgrade file
  must be safe to apply to a table it has already changed.
- Character sets: the connection is `utf8mb4`. The columns that hold text written by
  people, `<collection>_search.search` and `<collection>_metadata.value`, are `utf8mb4`
  with the `utf8mb4_unicode_520_ci` collation (`general_ci` and `unicode_ci` treat all
  emoji as equal). Every other column is `utf8`, which MySQL reads as `utf8mb3`, and
  holds ASCII only: identifiers, URLs, class and field names. Don't put free text in
  them: a 4-byte character can't be stored there, and comparing one to such a column
  is an SQL error (1267). `DbalRecordStore` lets that error propagate (it is not one of
  `MySqlDialect::isRejectedOperand()`'s); the `db()` adapter logs it and returns its failure
  value, as the old backend did (pinned in `RepositoryDataConciergeTest`). In-memory and on
  SQLite such a query simply finds nothing.
- Index keys must fit 767 bytes, the limit of the InnoDB row formats that older
  installations still have: at most 191 characters of a `utf8mb4` column, 255 of a
  `utf8` one.

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
  New-style controllers have no authentication or CSRF protection yet (see "Adding a route")
- Command-line scripts, `var/`, `config/`, `bin/`, `Tests/`, `docs/` and `warmup/CLI/` are
  denied over HTTP, and the scripts refuse to run under a web SAPI (see "New code conventions")
- HTML sanitization via HTMLPurifier
- SSRF protection: call `\Idno\Core\UrlValidator::isSafeUrl($url)` before any server-side
  fetch of a URL supplied by a remote party (webmention sources, ActivityPub actors,
  Micropub media URLs). It rejects non-HTTP schemes and hosts that resolve to
  loopback, private, link-local or reserved addresses.
- File access control: `Entity::save()` records the entity's UUID on each of its stored
  files (`attached_entity` in file metadata, via `FileSystem::updateMetadata()`), and
  `Idno\Pages\File\View` enforces the entity's `canRead()`. Entities that own extra
  files (e.g. Photo thumbnails) override `getAttachedFileIDs()`. Custom filesystems
  must implement `updateMetadata()` for this to apply.
- Redirect targets taken from user input must go through
  `\Idno\Pages\Session\Login::sanitizeRedirectUrl()` (same-origin only)
- Content Security Policy headers via template system
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
