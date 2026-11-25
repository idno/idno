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

# The mail delivery test (Tests/Mail/MailpitDeliveryTest) against real Mailpit servers, in Docker (see "Mail")
composer test:mail

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
  Rough times: the unit tier about ten seconds; the whole suite (6,508 tests) about 14 minutes on SQLite, 32 minutes on MySQL 8.0 and 29 minutes on
  MariaDB 10.6 (measured on one development machine with other work running beside it; the figures move with the machine's load).
- Likewise run the console as `composer console -- <command>` against the rig; on a site without
  `pedantic_mode` it is `php bin/console <command>` (`idno.php` and `known.php` are aliases). See
  "Console commands" below.
- PHPUnit config: `phpunit.xml` (bootstrap: `Tests/_bootstrap.php`; `phpunit.xml` sets `memory_limit` to 512M, because the
  whole suite runs in one process: the peak is 213 MB on SQLite (388 skipped), 221 MB on MySQL 8.0 (22 skipped) and 221 MB on MariaDB 10.6 (23 skipped), of 6,508 tests, well under the guard's 380 MB; the largest classes each leave about 20 MB or
  less behind: a second kernel's container, the export archive tests), and it peaked at 455 MB when every entity a test
  built was kept alive for the rest of the run by the event listener `Entity::registerEventHooks()` added for each object
  (see below)) for `composer test`. The extension `Tests\Support\MemoryGuard` and its listener
  `Tests\Support\MemoryGuardListener` (both listed in `phpunit.xml`, so not in the unit tier; the listener forwards the suite
  events of each class, which bracket `setUpBeforeClass()` and `tearDownAfterClass()`, where a test hook runs after
  `setUpBeforeClass()`) record, for each test class, the memory in use when its suite starts and, after a garbage collection,
  when it ends (what the class left behind, what it allocated in `setUpBeforeClass()` included; a class whose tests run in
  more than one stretch, in a random order, has its figures added up), and FAIL the run (a message on standard error naming the five classes that left the most
  behind; exit status 2 when a test errored, as PHPUnit's own, else 1) when the peak passes `MemoryGuard::LIMIT_MB` (380;
  `IDNO_TEST_MEMORY_LIMIT_MB=<n>` changes it, 0 turns the failure off, and a value that is not a whole number stops the run
  at once with a message). The same `phpunit.xml` runs in CI's MySQL and MariaDB jobs; `IDNO_TEST_MEMORY_REPORT` gives the peak of a run on either, and a peak above about 300 MB is a cause to fix or a limit to raise. To rank a whole run: `IDNO_TEST_MEMORY_REPORT=/tmp/memory.json composer test` writes every class's figures
  (`peak` and, per class, `tests`, `start`, `kept` in bytes). When the guard fires, look for a test case property, a static, a
  listener, a kernel or a `Process` that outlives its test (release it in `tearDown()`), not for a higher limit; a class that
  keeps more than a few megabytes is worth a look. `Tests/Unit/Support/MemoryGuardTest` pins the verdict, the limit's parsing, the exit status and the class bounds.
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
    the rig's own `php -S` and that server's worker processes (the process in `Tests/rig/.server.pid` must be `php ... -S 127.0.0.1:<port>
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
    but `PagePerformanceTest`, which skips without `IDNO_TEST_PERFORMANCE` (the unreachable-database tests run on both: a closed port on MySQL, a database file in a
    missing directory on SQLite); the characterisation suite runs in full on both.
- A test that needs a site of its own extends `Tests\Support\PrivateSiteTestCase` (abstract): each test starts
  `php -S` (four workers, a free port, never 8089) on a private database (a private database of the rig's MySQL or
  MariaDB server, or an SQLite file in a temporary directory; the schema from `idno:upgrade`), with its own
  `IDNO_URL`, `IDNO_TEST_HTTP_ALLOW` naming itself and an upload directory (`IDNO_UPLOADPATH`, removed in
  `tearDown()`, with an assertion that the rig's shared upload directory gained no file), and stops it and drops the
  database in `tearDown()`. The site is SYNCHRONOUS. It gives `site()`, `baseUrl()`, `console()`, `runInSite()`,
  `registerUser()` (the site's first administrator, made as the installation's last step makes it, then logged in over HTTP; a member on a site that has one)/`publishStatus()` (over HTTP), `sessionCookie()`, `formToken()`, `serverLog()`, and a subclass
  adds to the site's environment by overriding `siteEnvironment()` (`IDNO_THEME`, the mail switches) and to its PHP settings by overriding `siteIni()` (a test that counts session files
  makes `session.save_path` its own `sessionDirectory()`: the system's shared one is written by every process of the machine, and on MySQL and MariaDB the sessions are the rows of `idno_sessions` in the site's own database, which `siteConnection()` reads; `StatelessAuthenticationTest` proves its property on each); `IDNO_TEST_SERVER_NO_PATH_INFO=1` and
  `IDNO_TEST_SUBDIRECTORY=<name>` in the environment make the server behave as Apache or nginx do for the legacy router's path, and serve a site under `/<name>/`). `startExtraServer(array $environment, array $ini = []): string`
  starts a second `php -S` (a free port, stopped in `tearDown()`) on the same database and upload directory, with other
  environment variables or PHP settings (the production environment, opcache), and returns its base URL.
  A class whose tests only READ the site shares one site (`protected const SHARED_SITE = true`): the first test starts it, `seedSharedSite()` writes what
  every test reads (once, after the site has answered a request), and the site, its extra servers (kept by environment and ini) and its database stay until
  `tearDownAfterClass()`. After every test the fingerprint (`siteFingerprint()`: the rows of `idno_entities`, `idno_reader`, `idno_site`, the settings and
  the names and sizes of the uploaded files; never sessions or the queue) is compared with the one taken after seeding: a test that changed the site fails
  ("changed the shared site") and the next test gets a rebuilt site; the site's cache pool is emptied; every test must ask for the same environment
  (`Tests/Characterisation/SharedSiteTest`). A test that changes the site (a user, a post, a setting, a plugin) stays in a class of its own that is not shared,
  or puts the change back exactly; `RenderedSiteTestCase::seedSite()` returns what it seeded the first time on a shared site.
- A test that needs a site with the asynchronous event queue (the rig's site is synchronous) extends
  `Tests\Queue\AsyncSiteTestCase`, a `PrivateSiteTestCase` that sets `IDNO_EVENT_QUEUE=AsynchronousQueue` (through
  `additionalEnvironment()`; `siteEnvironment()` stays the test class's) and adds `runWorkerOnce()`
  (`bin/console service-event-queue --once`, same environment), `sink()`, `queuedEvents()` and
  `failedEvents()` (the messages waiting in, and failed from, the `idno_messages` table), `markTakenAgo()`,
  `addFollower()` and `storeQueuedEvent()`. `Tests/Queue/QueuedDeliveryTest` and `QueuedDeliveryBehindTlsTest` pin what queued events
  deliver (the signed ActivityPub delivery, webmentions, who an event runs as, failing events, the
  worker's output), `QueueWorkCommandTest` the worker command and `QueueSettingSiteTest` (and its
  synchronous twin) what the `event_queue` setting means to the code that asks; they do not run in the
  unit tier and skip on a MongoDB site.
- Import and export are tested in `Tests/Portability/` (the importers on two hand-written fixtures, the feed
  and archive exports, the jobs with doubles for their state and mail, a 64 MiB file exported under a memory
  limit of 32M in a subprocess, `QueuedPortabilityTest` on an asynchronous site), `Tests/Unit/Portability/`
  (`SafeXml`, `SettingsRedactor`, `ImportedMedia`, `ImportedImages`), `Tests/Kernel/PortabilityServicesTest`
  and, over HTTP, `Tests/Characterisation/AdminPortabilityPagesTest` (who reaches the pages, the downloads,
  who can fetch an archive, the work after the answer) and `AdminImportWithoutPedanticModeTest`.
- Acceptance tests (`Tests/Acceptance/`, what a site owner does, end to end, with the pieces working TOGETHER;
  each walkthrough is one ordered scenario on one site that carries its state from step to step, driven over
  HTTP with forms and their signed tokens through `Tests\Acceptance\Browser`, the worker as a subprocess, mail
  read from a `ScriptedSmtpServer`). `PhaseWalkthroughTest`, on an asynchronous site whose server demands a login:
  the mail settings saved on `/admin/email` (a wrong password fails, the corrected one works, and a fresh process
  of the site means the saved port), an invitation and its link, a password reset, French and English pages, the
  full archive (queued, worked, mailed, downloaded with `Cache-Control: private, no-store` by the administrator
  only, opened and checked for the member's posts with their access and publish status, an uploaded file's bytes,
  the record dump and no secret), a WordPress import (nothing announced to a follower, the draft not public) and
  the HSTS state of a host seen by a second process and expired (three command-line processes: an outbound HTTPS
  request cannot be made in a test, and nothing a request does reaches the pool, so that a web process and the
  worker share it is NOT covered). `UpgradedSiteWalkthroughTest` (MySQL and MariaDB; skips on SQLite): a 1.x
  database whose stored settings have `smtp_host` with no port, an empty `smtp_secure` and `cache`, upgraded over
  HTTP; the reset mail is delivered with those settings (a fresh process of the site builds the transport for
  port 25, in the clear, through `TestMailTransportFactory::built()`), the `cache` setting is warned about once per
  process that uses the cache, and French is shown. `RoundTripTest`: a WXR exported from one site is imported
  into a second, empty one (`restartSite()` stops the first), and its docblock lists what survives and what does
  not. A defect a walkthrough finds in production code is a test of the wanted behaviour that calls
  `markTestIncomplete()` when it sees the defect, so it takes over by itself when the defect is fixed. Run it
  with `composer test -- Tests/Acceptance` on each database; the manual checks that no test replaces are in
  `docs/developers/release-checklist.md`.
- Pages that controllers serve are pinned at four levels. Unit tier: `Tests/Unit/Http/Page/` (the attribute, the context, the renderer with doubles, the two listeners, `AddedHeaders`, `PageRedirect`),
  `Tests/Unit/Http/Controller/` (each service on doubles of its interfaces, `HomepageControllerTest` and `DiscoveryControllersTest` for the routes and attributes), `Tests/Unit/Http/PageRouteConventionTest` and
  `PageShellsTest` (the shells that exist: `Idno\Pages\Homepage` alone). Kernel: `ControllerPageTest`, `ControllerPageEquivalenceTest` and `ControllerPageMembersTest` (the current page against a real legacy page),
  `LegacyPageLifecycleTest`, `LegacyPageDrawerTest`, `LegacyHeaderReconcilerTest`, `LegacyRouteOverrideTest`, `PageHandlerChoiceTest`, `PageRoutesTest`, `SitePageRoutesTest`, `SitePageClaimsTest` (the route table against
  the recorded core order), `PageShellTest`, `PageRedirectKernelTest` and `PageListenersWithoutLegacyTest` (no legacy application: nothing builds). Over HTTP, each a page held to what a legacy page did
  (`SitePageLifecycleEquivalenceTest`, `SitePageDrawingTest` on the two fixtures `LegacyDrawPage` and `SitePageFixtureController`), the real pages line by line (`PortedPagesTest`, `PageContractTest`,
  `CachingHeadersTest`, `PageHeadersTest`, `PrivateSiteAccessTest`, `WebmentionToADeletedPageTest`, `RouteOverrideTest`) and the home page's replacement (`StaticPagesHomepageTest`, `StaticPagesHomepageMatrixTest`).
  The fixtures: `Tests\Fixtures\SitePageFixtureController` (a controller site page), `LegacyPlainPage`, `LegacyDrawPage`, `LegacyAccountDrawPage` and `LegacyAdminDrawPage` (real legacy pages to compare with),
  `ReplacedPage` and `ClaimingPage` (a kept shell and a plugin's page), `LateRoutes`, `ThirdPartyHomepage` and `TestEventHooks` (switched by `IDNO_TEST_CLAIM_ROUTES`, `IDNO_TEST_HOMEPAGE_LISTENERS`,
  `IDNO_TEST_RECORD_PAGES`, `IDNO_TEST_DRAW_HEADERS`).
- Recorded answers (`Tests\Support\RecordedAnswers`, a trait): the status, headers and body of a page, normalised and kept as a text file in `Tests/Characterisation/fixtures/answers/<group>/<case>.txt`, so that a controller that replaces a legacy page is held to what the legacy page answered. A test calls `assertRecordedAnswer($group, $case, $response)` and `assertRecordedAnswersComplete()` from `tearDownAfterClass()`; a case named in the class's `CHANGED` constant (case => reason) is neither compared nor written, and the test asserts it itself. A file is `status <n>`, each header by lower-case name (sorted, values in the order received; `date`, `set-cookie`, `x-idno-build-fingerprint`, `connection`, `keep-alive`, `host` and a foreign `x-powered-by` left out; an `expires` is `<past>` or `<future: N days from the request>`), a blank line and the body through `PageNormaliser`. The rule for a recording: seed every entity, file, annotation and setting it shows with fixed `_id`, uuid, slug, `created` and `updated`; capture on the commit before the change (`IDNO_TEST_CAPTURE_GOLDENS=1`: the run writes and then FAILS naming the files; refused when `CI` is set); capture twice and require the second to equal the first (`IDNO_TEST_CAPTURE_GOLDENS=verify` compares two captures in one run and writes only when they match); commit the recordings in a commit of their own.
- Authentication, sessions and form tokens are pinned over HTTP in `Tests/Characterisation`: `AuthMatrixTest` (seven pages by six kinds of caller on the rig's site, and `PrivateSiteAccessTest`, the home page, the stream and the login page by five kinds of caller on a private site of its own, `walled_garden` set through `IDNO_WALLED_GARDEN`: anonymous, member and administrator by session, member and administrator by API signature, and an administrator's session carrying a member's signature, which acts as the member and leaves the carried session intact), `LoginFlowTest` (the login form, the two failure messages, `fwd`, `_t=json`, logout and its unsanitised redirect to the Referer, a blocked address, the events of authentication through `Tests\Fixtures\EventRecorder`), `CsrfTest` (the session's one token: the time and action a form names are ignored, a token lasts the session, it changes with the session id at login and does not survive logout, a form from before the upgrade fails once; the token endpoint), `SessionCookieTest` (name, flags, `SameSite=Lax`, no P3P, a session with the `secure` flag still logs in) and `SessionDataAcrossLoginTest` (registration on a site that has an administrator, which never makes one, a site with no user, which lets nobody register, password reset and change, the IndieAuth approval, and that a signed request leaves no login behind). The helpers they share are the trait `Tests\Support\LogsIn` (used by `PrivateSiteTestCase`, `Tests\Acceptance\Browser` and the characterisation tests that log in): a test never keeps its own copy of "log in over HTTP", "read the session cookie" or "read a form's token", and reads the session cookie's name from the configuration. `Tests\Fixtures\EventRecorder` (test environment only, wired in `config/services_test.php` as the configurator of the legacy event dispatcher) appends the authentication events to the file `IDNO_TEST_RECORD_EVENTS` names; inert otherwise. Each assertion is what the site does today, defects included (a logout message that is never shown, an unsanitised logout redirect); a change of one is made on purpose, in the test that pins it. The public pages are pinned the same way: `PortedPagesTest` (the home page in each form, the streams, feeds, search, tags and the small public endpoints, on the rig's site), `PageContractTest` (a private site that records `page/ready`, `page/head` and `page/get` through `Tests\Fixtures\PageEventRecorder`, installed by `TestEventHooks` when `IDNO_TEST_RECORD_PAGES` names a file, and answers a walled garden for each page) and `StaticPagesHomepageTest` (a static page as the home page).
- Template rendering is pinned by `Tests/Kernel/TemplateContractTest` (the cascade as `DefaultTemplate` keeps it, on
  the fixture bases of `Tests/Fixtures/Templates/`, one subprocess a case because `Bonita\Main`'s paths are
  process-static: `Tests/Kernel/template_contract.php`), by `Tests/Templating/TemplateTypeRequestTest` (which `?_t=`
  types a request may choose, and that `?_t[]=` asks for no type and is no server error) and by golden pages,
  `Tests/Templating/fixtures/pages/` (`twenty26/`: Idno 2026, the look of every site, for a visitor; `logged-in/`: the
  administrator; and `non-html/`: RSS, JSON Feed, JSON, JSONP, the
  ActivityPub actor, KML, the JSON of each content type's permalink, its Markdown form (`markdown-<name>.md`, by the `.md` address), the Markdown of the listing pages, the login page and two error pages (`?_t=markdown`) and the password reset mail's two parts), made by
  `RenderedPagesTest` (`IDNO_THEME=Twenty26`: the name the look had as a theme, resolved to the built-in look) and
  `RenderedPagesNoThemeTest` (no theme: the variable unset; the same goldens) on private
  synchronous sites (`RenderedSiteTestCase`: fixed handles, entity ids, user uuids, slugs, `created` and `updated`, a
  fixed public key, one post of each bundled content type and enough public notes for a second page; the page's own
  ids are the only variable things). A page is compared after `Tests\Templating\PageNormaliser`, which replaces only
  the site's address, the form token and its time (only a value of the token's shape), ids made by `uniqid()`,
  `md5(rand())` and `rand()` (numbered by first appearance), the base64 address in an annotation's delete link, the
  built assets' modification-time cache-buster, the password reset code and the file of a draw case in a debug error page, and, for an HTML page only, makes white space
  that touches a tag one line break; the contents of `<script>`, `<style>`, `<pre>` and `<textarea>` are untouched (an unclosed one: everything after
  its opening tag), a comment is one token whatever it holds (what stands before its first tag kept, the rest normalised as markup). It does NOT fold
  the spellings of a character (`&amp;` and `&`, `&#039;`, `&#39;`, `&apos;` and `'`, `&quot;` and `"` are different pages): a golden records how a template escapes, so a change
  in escaping shows. every replacement is in
  `Tests/Unit/Templating/PageNormaliserTest` with a near-miss it must not touch, and a new one needs a reason there. The
  JSON goldens of the form pages (`json-account-settings-logged-in`, `json-status-edit-logged-in`) pin `formFields` and the absence of `csrf` (the form token is never printed)
  (the second replaces the time of the note that does not exist yet, in that page's test, not in the normaliser), and every mail
  of `templates/email*` has a golden (`non-html/mail-<name>.html` and `.txt`, drawn in the site by
  `RenderedSiteTestCase::drawMails()` from `MAIL_CASES`: fixed variables, the shell's link made with no request path, so a mail
  drawn by the web path, the password reset, differs only there).
  A golden is written, not compared, when `IDNO_TEST_CAPTURE_GOLDENS=1`: that run always FAILS, naming the files it wrote
  (so a capture is never a green run), is refused when `CI` is set, and also fails if two looks render a shared golden
  differently. Goldens are captured from the code they pin, are the same on SQLite, MySQL and MariaDB, and a change to one
  is made on purpose, in a commit that says which page and why. `*.json` is gitignored, so JSON output is saved as
  `.txt`. `Tests/Templating/fixtures/fallback-trace.php` has `traced`: for each type that is not a web page, the `classic`
  files it draws, derived from a real traced run; and `classic`: every file under `templates/classic/` of core and the
  bundled plugins, computed from the file system and asserted (`Tests/Unit/Templating/ClassicTemplatesTest`: each has a `default`
  template of the same name beside it (a Twig file: `CoreTemplatesAreTwigTest` forbids a `.tpl.php` there), only `.tpl.php` files are in `classic`, and the recorded list is the file system's). With
  `IDNO_TEST_TEMPLATE_TRACE=<file>` in the site's environment every template draw appends `type<TAB>name<TAB>file`
  (file relative to the project, or `-`) to the file, through `Idno\Templating\TemplateRenderer::$trace`, a static `?\Closure` hook that
  only `Tests\Fixtures\TemplateTrace` (a `kernel.request` listener registered in `config/services_test.php`) sets, and
  only when the variable names a file; nothing is called or written otherwise. `Tests/Kernel/BuiltInLookTest` is the built-in look
  on private sites: no theme, a stored `Twenty26` (resolved, never saved or announced) and a retired theme (saved as no theme and
  announced once), a pinned theme, `?_t=modern` (the default type), `?_t=classic` and traversal types (refused, in any letter case: on a file
  system that ignores case `CLASSIC` is the directory), a third-party plugin's `templates/modern/` file (still wins; one deprecation
  line a request) and a theme in `Themes/` (its `default/` override and `[extensions]`), a hostile webmention author, the drafts page
  and that no template refers to `Themes/Twenty26`. The Twig templates of core and the bundled plugins are proven by the golden pages and by the tests that pin
  what a golden does not draw; the `.tpl.php` files they replaced are gone, and nothing compares a Twig file with another rendering.
  `Tests/Unit/Templating/CoreTemplatesAreTwigTest` holds the end state: no `.tpl.php` is left under a `default`, `email` or `email-text`
  directory of core or a bundled plugin, and a locator over core and every bundled plugin resolves a Twig file for every name that has one there.
  The draw cases are goldens too: `TemplateDrawCasesTest` writes the normalised output of each labelled case (every `draw*Cases()` group, the mails that
  have no golden page, and `DRAWS`) to `Tests/Templating/fixtures/draws/<group>-<case label>.html` (`.txt` for the plain text of a mail; never `.json`, which git
  ignores) and compares it on every run, with `IDNO_TEST_CAPTURE_GOLDENS=1` writing them by the page goldens' rules (`RenderedSiteTestCase::drawGolden()`). A case that
  is not the same twice is made so in the case (a fixed `_id` for an entity it creates, a fixed stored time), never skipped. `Tests/Templating/JsonKeysTest` requests
  `?_t=json` of every page of the visitor, member and administrator lists, pins the sorted top-level keys of each answer in `Tests/Templating/fixtures/json-keys.php`
  (a page that is not JSON by its status), and fails for a key at any depth that `SettingsRedactor` calls a secret, (a `csrf` key is one: the form token is never printed) except the few names listed in
  `SECRET_NAMED_KEYS_ALLOWED` with why. `Tests/Templating/CanarySweepTest` creates each bundled content type and an annotation of each kind with `"><x-canary a='1`
  in every string a member or a remote party can set, draws the streams, permalinks and the owner's edit pages, and fails for an `x-canary` element (parsed with
  `Dom\HTMLDocument`) or an attribute the canary started.
  `Tests/Templating/TemplateDrawCasesTest` is a smoke test of what no golden page draws: it visits `EXTRA_PAGES` (admin, account,
  the welcome page, error and empty pages, as visitor, administrator, member and new user) and the golden pages' own visits (without comparing
  them), then draws in the site the mails, the entity, form, account, admin and plugin cases with hostile values, empty states and
  plugin states, and `DRAWS` (a template no page reaches); every page must answer below 500, every case must draw without an
  error, the `DRAWS` must differ from one another, and every Twig file of core and the bundled plugins must have been drawn at least once
  (read from the template trace, `Tests\Fixtures\TemplateTrace`, which its `runInSite()` installs in each script too): a new Twig file
  that no page or case reaches fails it. It extends `RenderedSiteTestCase` with `protected bool $compareGoldens = false`. The deliberate
  differences of the Twig look from the look it replaced, and the hostile values every template must escape, are pinned by assertions of
  their own, mostly `Tests/Templating/TemplateEscapingTest`, `FormControlsTest`, `UserSearchActionLinksTest`,
  `Tests/Kernel/BuiltInLookTest` and `Tests/Characterisation/MailFlowsTest`.
  `raw-allowlist.php` lists every `|raw` of a core or bundled Twig file with its reason (`RawUsageTest`: also `autoescape false`, `filter raw`, `apply raw`, `|raw()` and `source()`).
  `Tests/Templating/PagePerformanceTest` (skipped unless `IDNO_TEST_PERFORMANCE` is `record` or `compare`) is a tool for a change that may
  make pages slower, not a gate: it serves the fixture site from a server of the test (`PrivateSiteTestCase::startExtraServer()`: test
  environment, debug off, opcache on, checked through `/_test/server`; an outbound request fails at once), warms each page (`/` and a
  note's permalink) with 10 requests and times it in 5 blocks of 40 sequential requests; the number for a page is the smallest block median.
  `record` writes the two numbers to `<sys_get_temp_dir()>/idno-perf-baseline-<12 hex digits of the SHA-256 of the checkout's real path>.json`;
  `compare` asserts each is at most 1.20 times the baseline's and prints both numbers and ratios before the first is asserted. A baseline is a
  number from one moment of one machine (it varied 13% on a repeat): record it on the commit before the change, in the same session, compare
  after, and repeat a failing comparison before believing it. The two-server comparison with the old engine that the port was judged by
  (the home page about 1.18 and a note's permalink about 1.14 at the end of the port, medians of five runs) is gone with the old templates.
  Twig templates in the cascade are pinned by
  `Tests/Kernel/CascadeTest` and `TwigEngineTest` (the fixture bases' Twig files, one subprocess a case:
  `Tests/Kernel/template_twig.php`, run through `RunsTwigCases`), `TwigTrimTest` and, in the unit tier, `CascadeTwigLoaderTest`,
  `IdnoTwigExtensionTest`, `TemplateLocatorTest` and `EngineCostTest` (what drawing a template must not cost, counted: a path of the file system is asked about once, the
  loader resolves a name once, a compiled template is drawn without Twig building its extensions); see "Templates".
- A test never compares two separately generated timestamps for equality (a time the code under test made against `time()` or `date()` in the test, or two times made at two moments): it asserts against a stored value, an injected clock, or a range, and never sleeps to make two moments differ.
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
  Kernel/                      Cache directory and code fingerprint for the compiled container; DoctrineTrimPass,
                               MessengerTrimPass, TwigTrimPass
  Http/                        FrontController (what index.php runs); Client/ (HttpClientFactory and
                               BoundedBody: the site's HTTP clients, see "Kernel and services"); Page/ (SitePage, PageContext,
                               PageContextResolver, PageRedirect, PageError, PageLifecycle, PageRequestListener,
                               PageExceptionListener, PageRenderer, PageDrawer, DrawnPage, AddedHeaders: what a controller
                               that draws a site page carries, the lifecycle run around it, the answers to what it throws and
                               how it draws, see "Legacy pages and routes"); Controller/ (the controllers of site pages:
                               HomepageController, with Home/HomePages, the one implementation of the home page, the feeds, the search
                               and the tag pages, and HomeStream, the interface of what it lists, see "Querying entities in new code";
                               SiteFilesController, WebfingerController, MentionSearchController and FormTokenController, with
                               Discovery/ holding the services of the small public endpoints and the interfaces of what they ask the site)
  Console/                     Idno\Console\Application, the CLI application (see Entry points), and the
                               commands: UpgradeCommand, ImportMongoCommand, QueueWorkCommand, InstallCommand,
                               AdminCreateCommand, SetupLinkCommand
  Config/                      BootConfig, LegacyConfigLoader, SiteSettings (modern style, Symfony-wired)
  Legacy/                      Shims between the kernel and the legacy application (see below), among them
                               MessengerEventQueue (the site's queue), LegacyEventTrigger / LegacyUserContext and
                               the shims of the site pages controllers serve: ControllerPage (the current page of such a request),
                               BarePage, ControllerRoute (the placeholder in the legacy route table), LegacyRouteOverride,
                               LegacyPageLifecycle, LegacyPageDrawer, LegacyHeaderReconciler (with HeaderQueue and PhpHeaders:
                               the headers of a site page), the adapters of what the controllers' services ask the site
                               (LegacyHomeStream, LegacySiteIcons, LegacyWebfingerDirectory, LegacyMentionCandidates) and
                               PageShell (the base of the deprecated shells)
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
                               CollectionDump (a collection's table as SQL, a few rows at a time, for the
                               export archive);
                               SettingsRecordStore, a RecordStore decorator that serves the collection
                               `config` (the site's configuration, ONE document) from the key/value table
                               `idno_settings` and every other collection from the store it decorates
                               (the container's RecordStore is one, over a JsonRecordStore);
                               Files/ holds the file store: FileStore (uploads on a Flysystem
                               filesystem, in the 1.x layout), StoredFile, FileStoreException and
                               FilesystemFactory (the filesystem from the boot configuration)
  Queue/                       The queue of events on Symfony Messenger (modern style): the message
                               (QueuedEvent), its handler, the worker middleware (WorkerHygiene), the two
                               small interfaces they depend on (EventTrigger, UserContext), the decorator of
                               the transport that rides out a busy database (ResilientTransport), the
                               operator's view of killed workers' messages (AbandonedMessages) and the move
                               of the previous queue's records (LegacyQueueDrain), what the worker needs
                               between messages (EntityCache, StopWhenQueueIsEmpty, KeepFailedMessage) and the
                               check of the site's address (WorkerSiteUrl). See "The queue of
                               events"
  Cache/                       CachePoolFactory, the site's one cache pool (a static helper; see "The cache")
  Caching/                     PersistentCache, the old cache API (`Idno::site()->cache()`) over that pool,
                               for third-party plugins (legacy style)
  Mail/                        Mail on Symfony Mailer (modern style): MailerFactory (the mailer, from the stored
                               settings), MailTransportDsn (the settings as a DSN), SmtpTransportFactory, CramMd5Authenticator and InvalidMailSetting. See "Mail"
  Portability/                 Import and export (modern style): ArchiveExporter (the administrator's
                               archive), FeedExporter (RSS and WXR), WordPressImporter and BloggerImporter,
                               ImportedMedia and ImportedImages (the pictures of an import), SafeXml,
                               SettingsRedactor, ImportMode, PortabilityJobs (the jobs and their state), and the
                               five small interfaces its Idno\Legacy adapters implement (SiteFacts, FeedShell,
                               ExportState, ResultNotifier, PostWriter). See "Import and export"
  Templating/                  Template lookup and rendering (modern style): TemplateCascade, TemplateLocator,
                               TemplateRenderer, SlotRegistry, the two engines (PhpTemplateEngine, TwigTemplateEngine with CascadeTwigLoader and
                               IdnoTwigExtension) and their helpers. See "Templates"
  Security/                    Passwords (the password service on symfony/password-hasher) and what lets Symfony Security know who is
                               making a request (modern style): SessionUser, CurrentUserSource, CurrentUserAuthenticator,
                               StatelessAuthentication, ApiSignatureVerifier, DeniedResponder, ForbiddenPage and LoginThrottle. See "Security architecture"
  Install/                     The installation (modern style): the web installer of a site with no configuration
                               (InstallGateListener, WebInstaller, InstallPage), the step that creates the first
                               administrator (SetupGateListener, SetupToken, FirstAdministrator), and the pure pieces
                               the console commands share (ServerChecks, SiteAddress, DatabaseChoice, ConfigFile,
                               InstallPaths). See "Installation"
  Markdown/                    A post's Markdown form (modern style, NOT a scanned directory: nothing in it is a service):
                               MarkdownRequest (whether a request asks for it), FrontMatter (the YAML block) and
                               HtmlToMarkdown (the conversion). See "Markdown output"
  Translation/                 Translation without the gettext extension (modern style, NOT a scanned directory:
                               nothing in it is a service): CatalogueLoader (`.po`/`.mo` files into an array),
                               LocaleNegotiator (the locale of a request or console run). See "Translation"
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
  Pages/                       Built-in legacy page handlers (Account, Admin, Entity, etc.), and the deprecated shell Homepage; the pages that
                               moved to controllers (Http/Controller/) are not here
config/                        Kernel configuration: bundles.php, services.php, routes.php,
                               packages/ (framework, monolog, doctrine, twig), routes/test/ (test-only routes),
                               services_test.php (test-only services)
var/                           The compiled container (var/cache/); must be writable by the web server
                               (or set IDNO_CACHE_DIR). config/, bin/, var/ and Tests/ each hold a
                               deny-all .htaccess; none of them may be served (see Code Style)
data/                          Where an SQLite site's database file lives (data/idno.sqlite): only its
                               deny-all .htaccess and readme.txt are tracked; never served (see Code Style)
templates/                     Templates (Twig for web pages and mail, `.tpl.php` for the other types), one directory per template type: `default` is Idno 2026, the built-in
                               look (also the fallback of every type); `classic` the entity templates of the look it replaced, which
                               feeds, JSON, Markdown, ActivityPub, KML and mail still draw, with a copy of each `default` file they reach (never selectable); `rss`, `json`, ...
css/, js/                      The shipped style sheets and scripts: Idno 2026's (`css/idno-2026.min.css`, `js/idno-2026.min.js`, built by Vite from
                               `frontend/`) and the service worker (`js/service-worker.min.js`, built by Grunt from `js/src/ServiceWorker.js`).
                               `Tests/Unit/Templating/StaticAssetsTest` (which also fails for a template that calls a global of a script nothing loads: `Template`, `ImageTools`, `EXIF`, `Modernizr`) fails for a shipped file that
                               nothing names (its `KEPT` list needs a reason for each exception) and for a template that names a file that is not there
frontend/                      The sources of Idno 2026's style sheet and script (Vite, Tailwind CSS, Tiptap, Alpine) and their npm
                               files; built into css/idno-2026.min.css and js/idno-2026.min.js (committed, `npm --prefix frontend run
                               build`); denied over HTTP like config/ (see Code Style)
IdnoPlugins/                   First-party content & feature plugins
ConsolePlugins/                CLI service plugins (BuildLanguage, Example, Export, GhostExport; third-party console plugins load from here too)
Themes/                        Third-party themes only (a directory of template overrides with a theme.ini): Idno 2026 is
                               built in, not a theme. The `theme` setting `Twenty26` (Themes::BUILT_IN) and a retired name
                               (Themes::RETIRED) are resolved to no theme at load (Themes::resolve()); a retired name is
                               saved as no theme, and the admin told, when an admin next loads a page
                               (Themes::announceRetiredTheme()); `Twenty26` is never saved or announced; a theme pinned in
                               config.ini or the environment is never saved or announced, only resolved. Plugins and themes
                               are discovered only in IdnoPlugins/ and Themes/ under the project root
                               (no per-host directories)
Tests/                         Unit (Tests/Unit, dependency-free), kernel (Tests/Kernel), Support (Tests/Support: PrivateSiteTestCase),
                               Templating (Tests/Templating: the golden pages), storage (Tests/Storage,
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
  `Idno::site()`. It does not handle a request. On a site with no configuration it prints one
  line on stderr (`InstallGateListener::NOT_INSTALLED`), sets status 503 and exits 1. While the schema gate is closed
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
  site with no configuration only `idno:install` (and `list`, `help`) runs: every other command
  prints `Idno is not installed yet: run php bin/console idno:install` on stderr
  (`Application::NOT_INSTALLED`) and exits 1, before anything connects
  (pinned by `Tests/Kernel/UpgradeCommandNotInstalledTest`); on any other
  exception while booting it prints `Idno could not start: <exception class>: <message>`
  to stderr and exits 1 (it does not carry on). `idno.php` and
  `known.php` are aliases that repeat the SAPI check and require `bin/console`; use either.
  See "Console commands" below.

### Installation

A site is INSTALLED when it has a configuration: `BootConfig::isDefault()` is false, which
`LegacyConfigLoader` decides: a `config.ini` or `config.json` was read (in `configuration/` or the
project root), or the merged configuration names a `database` (a container configured by `IDNO_*`
variables alone, or `IDNO_DATABASE_URL`, is installed and never offered the installer). On a site that
is not installed `Idno\Kernel::boot()` stops after the container: the schema gate is not asked, nothing
connects, the legacy application is not booted, and `Kernel::installed()` is false. The installation
is `Idno\Install\` (modern style, a scanned directory); it replaced the standalone `warmup/` scripts,
`Idno\Core\Installer` and the onboarding pages (`/begin/*`, `shell/simple`), which are gone. Only
`warmup/webserver-configs/` remains (the sample server configurations and `config.ini.template`).

There are three ways in, with one result (a configuration file, the tables, ONE administrator):

- **The web installer.** `Idno\Install\InstallGateListener` (`kernel.request`, priority 310) acts only
  in a kernel that boots the legacy application and is not installed. It answers `/` (and `/index.php`;
  GET, HEAD, POST) with `WebInstaller::handle()`, a GET or HEAD for a page anywhere else with a 302 to
  the base path's `/` (never a relative redirect: the old `./warmup/` looped), and anything else with a
  503 `text/plain` `Idno is not set up yet.`. The installer has NO address of its own, so it works on a
  server whose URL rewriting does not, and "inaccessible once installed" is exact: with a configuration
  the listener does nothing, for every address and method, and `/` is the site's home page. A site
  whose configuration FILE exists but cannot be read or is empty (`InstallPaths::existingConfigFiles()`)
  is not offered the installer: every request gets a 503 that says the file cannot be read, with the fix.
  `WebInstaller` keeps nothing on the server between its steps (what a step accepted travels forward in
  hidden fields) and writes nothing before the last one:
  1. "Checking your server" (`ServerChecks::run()`, pure, from facts the caller gives): PHP 8.4, the
     extensions (`ServerChecks::EXTENSIONS`, and one of `DATABASE_EXTENSIONS`), `configuration/` and
     `Uploads/` writable (fail), `data/` writable (fail, or a warning that disables the SQLite choice
     when `pdo_mysql` is there), the kernel's cache in a temporary directory (warn), a base path (fail:
     Idno is installed at the top of a domain), HTTPS (fail; a warning on `localhost`, `127.0.0.1`,
     `[::1]`, `*.localhost`, `*.test`: `SiteAddress::isLocal()`). HTTPS is the connection or
     `X-Forwarded-Proto: https` (`WebInstaller::secure()`, the rule `Page::isSSL()` uses). Every failure
     is a `CheckResult` with what it means, the fix and, where there is one, the command. URL rewriting
     is checked by the page's script, which fetches `js/canary.js` (the rewrite rules of `.htaccess`,
     `warmup/webserver-configs/htaccess.dist`, `htaccess-2.4.dist`, `nginx.conf`,
     `docker/caddy/Caddyfile` and both routers answer it with `js/idno-2026.min.js`; no rule leaves a
     404; `Tests/Unit/Install/RewriteCheckTargetTest` pins one existing target in every configuration
     and `DeniedPathsTest::testTheInstallersRewriteCheckIsAnsweredWithTheLooksScript` the answer) and
     posts the result with the form (`rewrite` = `fail` stops the step); without JavaScript it is not
     checked. The same script asks for `Uploads/readme.txt` (`UploadFolder::PROBE`): an answer that holds
     the file's marker (`UploadFolder::PROBE_MARKER`; a missing file is answered by Idno's own page, which
     does not) means the web server hands out uploaded files without the file page's access rules, and
     `uploads` = `served` stops the step with the rule to add for each server.
  2. "Your site": a name and a DOMAIN (`SiteAddress`: read from the Host header, shown, corrected by
     the person; a host name or IP with an optional port and nothing else). The address written is
     `https://<domain>/` (`http://` only for a local name). This is how an installer-made site gets its
     `url` without trusting the Host header: the person confirms it on screen before it is written.
  3. "Where should Idno keep your site?": SQLite (preselected, "a file on this server") or MySQL or
     MariaDB (name, user, password, server; the server may be `host:port`, written as `dbhost` and
     `dbport`). The database is asked LAST so that its password is posted once and never printed.
     `save()` then, in this order: every check again; `DatabaseChoice::type()` (MongoDB refused with
     the message that names `idno:import:mongo`); `ConfigFile::assertWritable()` on what would be
     written, BEFORE anything is created; `DatabaseChoice::params()` (MySQL: the server must be a host name or an address and the
     database's name hold no `;`, `=` or space, since both become parts of the driver's connection
     string; it connects through `DatabaseChoice::connection()`, the site's connection for those
     settings EXCEPT that `LOCAL_INFILE` is off and connecting gives up after `CONNECT_SECONDS` (5): the
     server is whatever a visitor of an uninstalled site typed, and a hostile one could otherwise ask
     the driver for any file the web server can read. Never give an installer
     `ConnectionFactory::forConfig()` for a candidate database. A failure is a
     `DatabaseConnectionFailed` carrying one of `connectionProblem()`'s fixed sentences, never the driver's text: the
     command line shows that sentence, and the web installer, which anyone may reach, shows ONE sentence for every
     failure to connect (`WebInstaller::CONNECTION_FAILED`: nothing on the page tells an unreachable host from a refused
     password or a missing database, so the installer is no probe of the server's network) and logs the reason on
     `upgrade` (`InstallerSchemaTest::testTheWebInstallerSaysTheSameWhateverTheConnectionFailureIs`; the time an
     unreachable host takes, up to `CONNECT_SECONDS`, still differs); SQLite: creates the directory and an empty file last and opens it with
     `SqliteConnectionSetup`); `DatabaseChoice::inspect()` (a `SchemaState` on the candidate settings:
     a database the upgrade would refuse is refused here with the upgrade's own `PublicMessage`; one
     that already holds an Idno site is never overwritten (the upgrade takes over) and is ADOPTED only
     with the form's `use_existing=1` AND the code of `Idno\Install\InstallCode`. The same code is asked for
     ANY web install, a new site on a database of the visitor's choosing included, when the server shows an
     earlier site: `Idno\Install\EarlierSite::signs()`, the one rule, read from the folders before anything
     is created and never from the request: an SQLite file that is not empty anywhere under `data/`, any file
     under the upload folder but its shipped `readme.txt` and `.htaccess`, any file in `configuration/` but the
     shipped `.htaccess` and the code itself (the shipped names compared ignoring letter case, and a stray `.DS_Store` or
     `.gitkeep` never a sign) (`EarlierSiteTest`, and over HTTP for each sign with and without
     the code, and a MySQL server of the visitor's that does not avoid it:
     `WebInstallFlowTest::testASignOfAnEarlierSiteMakesAnyInstallAskForTheCode`). A checkout as shipped asks
     for none. A site whose data is only remote (a MySQL server, an `s3://` upload path) leaves no sign on this server's disk, so the
     web installer cannot tell it from a new site: its lost configuration must be restored before the site is reachable again. Without the code the step writes nothing but that code, a random 32-hex value in `configuration/install-code`
     (`InstallCode::relativePath()`, mode 0600, an exclusive create, refused by every shipped server
     configuration), which the page asks for by its path from the project and the command to read it. The
     code is proof of access to the server's files: a site whose configuration file was lost is an
     uninstalled site to anyone, and adopting it writes its `url`, so without the code a stranger could
     point a site's reset links, ActivityPub ids and IndieAuth endpoints at their own host. It is compared
     with `hash_equals()` (spaces and letter case ignored), lasts `InstallCode::LIFETIME` (an hour, by the
     file's modification time), is not replaced while valid (a visitor reaching the page again does not
     invalidate the code being read at the server; an expired one is replaced ONCE: under an exclusive lock on
     the file, read again under it, so requests at the moment it expires keep the first replacement; a
     `configuration/` that is not there is created, 0755, with a `Require all denied` `.htaccess`), is removed once the
     configuration is written, and is never printed, logged, put in a cookie or in an address. On the
     adoption page the domain is a visible field beside the code (it was read from the request's Host in
     step 2), so the address written is the one the code's holder confirms. `InstallCode` and `SetupToken`
     are separate (other files, 32 against 64 hex characters, neither accepts the other's value). The
     command-line installer needs no code (`--use-existing`), and a NEW site on an empty database none); `ConfigFile::build()` and `writeOnce()` (an exclusive create of
     `configuration/config.ini`: false, nothing changed, when any configuration file exists or
     appears, so a double submit or a second tab changes nothing); `SetupToken::issue()` with the value
     of the browser's `idno_install` cookie as the token, and the `idno_setup` cookie carrying the same
     value; a 303 to `/`. A failure removes the SQLite file this request created, and a message never
     holds the server's own path (a file is named from the project). What cannot be shown (an exception
     while the database is read, a marker that cannot be written) is logged on the Monolog channel
     `upgrade`, wired by name as for `Idno\Upgrade` and for the same reason: the site's logger does not
     exist yet.
  The web installer takes NO path from a request (the SQLite database is always `data/idno.sqlite`,
  uploads always `Uploads/`: an uninstalled site is reachable by anyone), a field that is not a string
  is empty (`InstallForm`), and a form is accepted only with the token of the `idno_install` cookie
  (`WebInstaller::fieldFor()`, a double-submit token; `SameSite=Strict`). Do not add a disk path the
  visitor chooses.
- **The setup step.** Whoever arrives first may install a NEW site on a server as shipped (no code to paste; where
  the server shows an earlier site, or to adopt a database that holds one, `InstallCode`, above), but only the browser that did can create the administrator. `SetupToken` writes the marker
  `configuration/setup-pending` (JSON: the SHA-256 of a random token, and the site's name; never the
  token) and the browser holds the token in the cookie `idno_setup` (HttpOnly, `SameSite=Lax`, Secure
  on HTTPS, thirty days). Between the configuration and the tables the upgrade page runs as for any
  site. Then `Idno\Install\SetupGateListener` (`kernel.request`, priority 290, after the upgrade gate's
  300) acts while the legacy application is booted AND the marker exists (one `stat` a request, no
  statement, once the marker is gone). It accepts the `idno_setup` cookie or, with the same value, the
  installer's `idno_install` cookie (the browser has only that one when the installer's last response
  never reached it: a double click on the button), and then gives it the setup cookie. If the site has an administrator already (an existing database
  was chosen) it removes the marker and lets the request through (a marker it cannot remove, written by `idno:setup:link` run as
  another user in a folder the web server cannot write, costs that lookup on every request: `SetupToken::clear()` says so and the
  listener logs one warning a process naming `rm configuration/setup-pending`; `Tests/Unit/Install/SetupGateListenerTest`); a browser whose cookie matches gets
  "Create your account" at `/_setup/` (any other page is redirected there), and its POST (name,
  username, email, a password of at least `AdministratorDetails::PASSWORD_LENGTH` (10) characters typed
  twice, the form's own token `SetupToken::fieldFor()`) creates the administrator through
  `Idno\Install\FirstAdministrator` (an interface; `Idno\Legacy\LegacyFirstAdministrator` is the
  adapter: it refuses when an administrator exists, gives the site its name, `from_email` and closed
  registration, and triggers `site/firstadmin`), removes the marker, signs the browser in and
  redirects to `/admin/welcome/` (`Idno\Pages\Admin\Welcome`, a legacy admin page: what to do next;
  every link on it is followed by a test); everyone else gets a 503 "This site is being set up" for
  every address and method. `GET /_setup/<token>` with the marker's token (the link `idno:setup:link`
  prints) works ONCE: it makes a new token, which only that browser gets, so the one in the link (and
  in any server log) is dead. The account form's work runs under an exclusive lock on the marker
  (`SetupToken::exclusively()`): two submissions make one administrator, and the second is sent to sign in. "Closed for ever" means: once the site has an administrator the
  marker is gone and `/_setup/` is an ordinary missing page; nothing else creates an administrator.
  `Idno\Pages\Account\Register` NEVER makes one: on a site with no user it refuses (a redirect to the
  home page), and the home page (`HomePages`, which `HomepageController`, the feeds, the tag pages and the deprecated
  `Idno\Pages\Homepage` share) on a site with no user answers 503 with the same "being set
  up" page (a hand-written configuration with no marker), so there is no window in which a stranger
  becomes the first administrator.
- **The command line**: `idno:install`, `idno:admin:create` and `idno:setup:link` (see "Console
  commands"), and a hand-written `config.ini` or `IDNO_*` environment followed by `idno:upgrade` and
  `idno:admin:create`.

The pages (`InstallPage`, static) are self-contained documents built in PHP as `UpgradePage` is: inline
style and script with a per-response nonce and a Content-Security-Policy, no image, font or external
request, light and dark, a label for every field, the error beside its field and in a `role="alert"`
summary, every value escaped, a password never printed; English only. Every response is `no-store`.

The safety rules, kept from the old installers and pinned by `Tests/Unit/Install/`: the name of an
SQLite database file must match `ConnectionParameters::SQLITE_FILE_NAME` (`DatabaseChoice::sqlitePath()`
refuses a quote, a control character, a `..` segment and any other name, touching nothing; the suffix
`.sqlite` is the one every shipped web-server configuration refuses to serve and PHP never executes,
and `ConnectionParameters::fromLegacyConfig()` applies the same rule to any configuration);
`ConfigFile::assertWritable()` refuses a value containing a quote, a control character or ending in a
backslash, naming the setting and never the value (values are written inside single quotes; the
property test is in `InstallerDatabaseTest`), and `build()` fills the template in ONE pass; a setting
with no value (`url`, `dbport`) has its line left out; the value written for SQLite is exactly `sqlite`
(`Sqlite3` in a configuration means MySQL); no installer loads SQL (the upgrade creates the tables) or
changes a file's mode or the root `.htaccess`. Both write the upload folder's own `.htaccess`
(`Require all denied`, `UploadFolder::protect()`, never over one that is there): the web installer always
(`Uploads/`), `idno:install` when the folder is inside the project (and it says the web server must refuse it). An SQLite site set up from the command line has files owned
by that user: `idno:install` and the import command print `DatabaseChoice::sqliteOwnershipNotes()`.
`ext-pdo_mysql` and `ext-pdo_sqlite` are Composer `suggest`s.

Tests: `Tests/Unit/Install/` (`InstallPiecesTest`, `InstallerDatabaseTest`, `WebInstallerTest`: the
installer driven with `Request` objects on a temporary root: each step, the last step's writes, a
second submission, a missing token, MongoDB, arrays for strings, hostile values, nothing left behind,
an existing database never removed and adopted only with the install code: wrong, expired, used, a double
submit, the code and the setup token kept apart; `UploadsDeniedTest`), `Tests/Install/WebInstallFlowTest` (over HTTP, a site of its own
through `UninstalledSiteTestCase`: every address of an unconfigured site, the whole flow to a
signed-in administrator, a stranger at each stage (every method, a method override, API and bearer
headers, the upgrade step), a stranger and the owner at a database that holds a site with no configuration (only the
code's holder adopts it, nothing else is written, the code file is never served), a double click on the last step and on the account form, the ready page's links, the step gone afterwards,
the lost cookie and the console's link, an unreadable configuration, a hand-written configuration and
`idno:admin:create`, `idno:install` unattended) and `Tests/Kernel/InstallerSchemaTest` (the MySQL side;
skips on SQLite). Those sites keep what an installation writes in a temporary directory: in the TEST
environment only, `config/services_test.php` builds `BootConfig` and `InstallPaths` through
`Tests\Fixtures\InstallRoot`, which uses the directory `IDNO_TEST_INSTALL_ROOT` names when it is set
(never in production: there the root is the project directory).

### Request lifecycle

1. `index.php` checks the PHP version, registers the fatal-error page
   (`Idno\Legacy\FatalErrorPage`), loads the autoloader,
   then runs `Idno\Http\FrontController`.
2. `FrontController::requestFromGlobals()` builds the Symfony `Request` (it never parses
   the body, so `php://input` stays readable for legacy code), and
   `FrontController::handle()` calls `Kernel::handle()`.
3. The kernel boots: the container is built (or loaded from cache); a site with no
   configuration is not booted any further (nothing connects; `Kernel::installed()` is false) and
   `Idno\Install\InstallGateListener` answers the request with the installer (see "Installation"); otherwise the schema gate is asked
   (`Idno\Upgrade\SchemaGate`, see "The schema gate and the upgrade"); and, when it is open, the
   legacy application is booted by `Idno\Legacy\LegacyBootstrap` (configuration, `Idno::site()`,
   plugins, the legacy session — `session_start()` happens here, before routing). When the gate
   is closed the legacy application is NOT booted (no `Idno::site()`, no session, no legacy
   error handler) and `Idno\Upgrade\UpgradeGateListener` (a `kernel.request` listener at priority
   300, before the router) answers every request with the upgrade page or a plain 503, except one
   the router will match to the step route (`UpgradePage::isStepRequest()`: POST as sent and as
   Symfony reads it, despite any `X-HTTP-Method-Override`, to exactly `/_upgrade/step`), which
   reaches `UpgradeController`; so no request reaches the router's legacy catch-all, and steps 4 to
   6 below do not happen for them (`Kernel::terminate()` still runs; the only Idno listener on it is `PageRequestListener::onTerminate()`, for the work a controller page asked to be done after its response).
   While the database is unreachable the legacy application boots as before (its database error
   page), except for an upgrade step, which answers 503 itself (see "The schema gate and the
   upgrade").
   `LegacyFallbackListener` leaves a request to Symfony while the legacy application is not
   booted.
4. Symfony's router matches the request. Attribute-routed controllers under `Idno\`
   (and, in the `test` environment only, `Tests\Fixtures\`) are tried first. Anything
   they do not match goes to the lowest-priority catch-all route `idno_legacy`
   (`/{path}`, any path, any HTTP method): `Idno\Legacy\LegacyController`. When the match is a site page's route,
   `Idno\Legacy\LegacyRouteOverride` (priority 31) hands the request back to the catch-all if the legacy router has a page of its own for the path
   that the controller does not replace (a plugin took it). A controller marked
   `#[SitePage]` is a page of the site: before it runs, `Idno\Http\Page\PageRequestListener` (`kernel.controller`, main
   requests only, and only when the kernel booted the legacy application) begins the page as `Page::init()` did, refuses
   an API request on an account page and a visitor of a private site, and enters it (see "Legacy pages and routes"). The controller
   answers with a Response it draws through `Idno\Http\Page\PageRenderer` (the page in its shell, with the headers of its template
   type and the ones its draw sent, the 404, 410, 403 and 500 pages, a forward), never `drawPage()`, which echoes and exits. The home page is the
   example: `Idno\Http\Controller\HomepageController` serves `/` and `/content/<types>` (GET, HEAD, OPTIONS), with `Idno\Pages\Homepage`
   a deprecated shell behind it (see "Legacy pages and routes: shells"), and the feeds, the search and the tag pages (below).
5. `LegacyController` returns an `Idno\Legacy\LegacyResponse`: a streamed response that
   sends no status line and no headers of its own. Symfony's response listeners still
   modify the object (they set a charset, and `X-Robots-Tag: noindex` in debug mode), but
   that has no effect, because `LegacyResponse` sends none of it. When `index.php` sends it, its callback runs
   `Idno\Legacy\LegacyDispatcher::dispatch()`, which is the old `index.php` routing:
   registers the Toro `404` hook, adds the `/` route under the site's subdirectory if
   any, and calls `PageHandler::serve()`. The legacy router (Toro) then runs the matching
   `Idno\Common\Page` class, which reads the superglobals and writes the response itself
   with `header()`, `http_response_code()`, `echo` and often `exit`. `LegacyController` gives the response the `Request` and Symfony's
   `RequestStack` (`new LegacyResponse($callback, $stack, $request)`, also for the response `LegacyFallbackListener` makes): `sendContent()`
   pushes the request on the stack before the callback and pops it in a `finally` (a page that `exit`s ends the process instead). Symfony takes the
   request off the stack when `Kernel::handle()` returns, which is before a legacy page runs, so without this the stack is empty while a
   template draws, and the Twig functions `current_url()`, `current_path()` and `referrer()` would answer ''. The page still reads the
   superglobals; the stack is for the templates (`Tests/Unit/Legacy/LegacyResponseTest`). The request helpers (`current_url()`, `current_path()`, `referrer()`)
   never ask the request for its host, scheme or full URI: a request Symfony rejected (a malformed Host) is served by the legacy router too, and `getHost()` would
   throw. `current_url()` is `TemplateHelpers::currentUrlFor($_SERVER['REQUEST_URI'] as the request has it)`: the configured `url`'s scheme, host and port plus the
   request URI, byte for byte what `Page::currentUrl()` builds (`Tests/Kernel/TwigRequestHelpersTest` over a real page, `IdnoTwigExtensionTest`).
6. If the page returns rather than exits, `index.php` calls `Kernel::terminate()`. Pages
   that `exit` skip it, so nothing essential may live in a `kernel.terminate` listener, with one exception: the work a page a controller
   serves registers with `AfterResponse::run()` runs there (`PageRequestListener::onTerminate()`), which is safe because a controller page never
   exits (a plugin listener that exits during the draw would skip it). The response says `Connection: close` and its `Content-Length` when such
   work is registered, so `php -S` and mod_php, which only flush, let the client go as the legacy path did; under FPM `Response::send()`
   calls `fastcgi_finish_request()`, which does it whatever the headers. A response that streams (a `StreamedResponse`, a file) carries no length.

Three kinds of request therefore take three paths through the kernel:

- A LEGACY PAGE (`Idno\Common\Page`, a plugin's, or a core page that is still a legacy class): router, the catch-all, `LegacyController`, a `LegacyResponse`; the page runs while the response is sent, writes
  with `header()`/`echo`/`exit`, and its exceptions are the legacy handler's (`Page::exception()`, the 500 page).
- A SITE PAGE (a controller with `#[SitePage]`: the home page, the feeds, the search, the tag pages, `robots.txt`, the manifest, the service worker, WebFinger, the mention search, the form token endpoint):
  1. Symfony's router matches the controller's route (`RouterListener`, priority 32); 2. `LegacyRouteOverride` (31) asks the legacy router which page it would choose for the path and hands the request to the
  catch-all when that is a plugin's page (then the legacy path above applies); 3. `PageRequestListener::onController()` (`kernel.controller`, priority 0) refuses an undeclared method (405), begins the page
  (`LegacyPageLifecycle`: `page/ready`, the template type), refuses an API request on an account page, answers OPTIONS, applies the walled garden and enters it (`page/head`, the form token for a state-changing
  method, `page/<method>`); 4. `onControllerArguments()` (priority -10100) wraps the controller so an `\Error` becomes a `PageError`; 5. the controller calls its page service, which draws through
  `PageRenderer` and returns a `Response`; 6. on `kernel.response`, `PageRequestListener::onResponse()` (16) adds the standard headers, the page's headers and a forward, and `LegacyHeaderReconciler` (8, then
  -20, -250 and -300) makes the headers the ones a legacy page sends; 7. on an exception, `PageRequestListener::onException()` (10) answers a `PageRedirect` and `PageExceptionListener` (2) the site's 404, 410, 403 and 500
  pages; 8. `Kernel::terminate()` runs `PageRequestListener::onTerminate()`: the work the page asked to be done after the response (`AfterResponse::run()`). Nothing in it `exit`s.
- A SHELL (a deprecated class under `Idno\Pages\` that extends `PageShell`, reached through the catch-all when the controller's route does not take the request, or a plugin's subclass of it): the legacy path
  with its handlers delegating to the same page service, then writing the response as a legacy page does, `exit`ing after every one.

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

A controller marked as a site page (`#[SitePage]`) is the exception to that last rule: what it throws is answered with the site's own
error pages, as a legacy page's was, by `Idno\Http\Page\PageExceptionListener` (see "Errors on a site page" below).

### Adding a route (the new way)

To replace the site's home page (the root) from a plugin, do not add a route or `hijackRoute()` the root: listen to the event `homepage/replace` (see "Replacing the home page: the event `homepage/replace`" under "Legacy pages and routes").

New HTTP endpoints are Symfony controllers, not `Idno\Common\Page` classes, with one
limit: **a controller that changes state for a logged-in browser must ask for both a user and the form token**:
`#[IsGranted('ROLE_USER')]` (any member) or `#[IsGranted('ROLE_ADMIN')]` (an administrator authenticated by the
browser session: never one authenticated statelessly) and `#[IsCsrfTokenValid(FormToken::ID, tokenKey: FormToken::FIELD)]`
(`Idno\Security\FormToken`: the session's form token, posted as `__bTk`, the one every form carries and
`Bonita\Forms::validateToken()` checks; see "Security"). `Tests/Fixtures/WhoAmIController` has the example
(`POST /_test/csrf-member`), pinned by `Tests/Kernel/CsrfAdapterTest`. A failed token check is an
`InvalidCsrfTokenException`, answered by `Idno\Security\DeniedResponder` (403) like every refusal of a controller that is not a site page; a token field that is
an array is a malformed request to Symfony (400), never a server error. Symfony Security knows who is making each request
(see "Security architecture" below), so a controller may also be read-only and ask for a user or a role, or take the user
with `Symfony\Bundle\SecurityBundle\Security`. A refusal is always a 403, never a login redirect: `DeniedResponder`'s for a plain controller,
and for a site page (`#[SitePage]`) the page's own 403, drawn as a legacy page's `deniedContent()` was, whatever refused it (see "Legacy pages and routes").
Legacy `Page` classes get `gatekeeper()`/`adminGatekeeper()` and the form token check on POST automatically; a controller
gets what its attributes ask for. A controller that accepts a request without a browser session (a signed request or a
bearer token) authenticates it itself (no firewall looks at `Authorization`) and has no form token to ask for: it carries
no `#[IsCsrfTokenValid]`.

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
  (today `Idno/Upgrade/`, `Idno/Legacy/` and `Idno/Http/Controller/`, where the controllers of site pages go: the directory is
  imported with a `FileExistenceResource`, skipped while it does not exist; `Idno/Http` is in
  `CodeFingerprint::SERVICE_DIRECTORIES`; a directory outside them is added to `config/routes.php` AND to
  `CodeFingerprint::SERVICE_DIRECTORIES` so the container and route cache are rebuilt when it changes).
- It always wins over the legacy catch-all, whatever the path: that route has priority
  `-1000`. So a new route also shadows any legacy page at the same path, but only for
  the HTTP methods it accepts: a request with another method still reaches the legacy
  router. For GET and HEAD, Symfony also answers the trailing-slash variant of the path
  (`/foo/` for `/foo`, and the reverse) with a 301 redirect to the route's own path. A route that is a SITE PAGE (a
  `#[SitePage]` controller, see "Legacy pages and routes") is the exception to both: it follows the route convention below, so that
  no spelling of an address is redirected, and the legacy router's own choice for the path still wins where a plugin took it. A page that
  moves to a controller keeps core's registration of its legacy pattern(s) (the placeholder, or its kept shell, which the attribute names with `shell: true`); "Legacy pages and routes" has the rule and the
  recorded table that guards it (`Tests/Kernel/SitePageClaimsTest`, `Tests/Kernel/fixtures/core-routes.php`), and `PageRoutesTest` holds every site page route to the convention. To remove a route on purpose, delete its key from `routes` in that fixture and list it in `omitted` with the reason, in the same change.
- It must return a `Symfony\Component\HttpFoundation\Response`, and must take its input
  from the `Request` (inject it as an argument), never from `$_GET`, `$_POST`,
  `$_SERVER` or other superglobals, and must not call `header()`, `echo` or `exit`.
- The `Request` is built without parsing the body (see `FrontController::requestFromGlobals()`),
  so `$request->request` is empty for form-encoded `PUT`, `PATCH` and `DELETE` requests
  (it is filled for `POST`). Read the raw body with `$request->getContent()` and parse it
  yourself (e.g. `parse_str()` or `json_decode()`).
- Do not assume a clean header slate: the legacy application boots before routing, and
  the headers it has already set (`Set-Cookie`, `Expires`, `Link`, a
  `Cache-Control`, `Pragma: no-cache`) are sent with your response, alongside the response's own headers
  (so there can be two `Cache-Control` headers). That is still true of every controller that is not a site page. For a
  site page (`#[SitePage]`) `Idno\Legacy\LegacyHeaderReconciler` makes the headers the ones a legacy page sends, line for line: the
  `Cache-Control`, `Pragma` and `Expires` of the session are PHP's and the response's own are taken off it, so one `Cache-Control` line
  goes out; a page that wants other caching calls `PageContext::setCaching()` (see "Kernel and services"); `Last-Modified` is the
  response's. Symfony's debug-mode `X-Robots-Tag: noindex` is taken off such a page's response, unless the page set a tag itself.
- Mark it `#[AsController]` (or register it as a service) when it needs constructor
  injection; services are autowired from the container.
- A controller that draws a page of the site marks the class or the method with `#[Idno\Http\Page\SitePage(name: <the legacy page class>, ...)]`:
  `PageRequestListener` then runs the page's lifecycle around it (the events plugins listen to, the template type, the walled garden, the
  refusal of an API request on an account page, the standard headers; see "Legacy pages and routes") and gives the controller a
  `PageContext` argument. The fixture is `Tests/Fixtures/SitePageFixtureController` (`/_test/site-page/{state,public,account}`); a test records the
  page events with `IDNO_TEST_RECORD_PAGES` (`Tests\Fixtures\PageEventRecorder`).
- A controller or a page service draws a site page through `Idno\Http\Page\PageRenderer` (injected; never `drawPage()`, which echoes and exits):
  `page($context, ['title' => ..., 'body' => $renderer->template('pages/x', [...])])` returns the Response, `notFound()`, `gone()`, `denied()`,
  `error()` and `redirect()` the pages and the forward a legacy page answered with (see "Legacy pages and routes"). A template drawn only through
  `->template('name', ...)` counts as drawn for `NonHtmlReachTest`.
- WRITING A SITE PAGE. The home page is the worked example (`Idno\Http\Controller\HomepageController` and `Home\HomePages`; the small endpoints are the simplest: `SiteFilesController` and
  `Discovery\SiteFiles`). A controller is `#[AsController]`, takes a service and does one call of it; the service holds the logic, takes what it needs from the site as INTERFACES
  (`HomeStream`, `SiteIcons`, `WebfingerDirectory`, `MentionCandidates`, aliased in `config/services.php` to their `Idno\Legacy\` implementation) and its legacy-only collaborators as service closures
  (`#[AutowireServiceClosure]`), so it is built and unit-tested without the legacy application. A method of it is `#[Route(...)]` plus `#[SitePage(...)]` and takes the request's
  `Idno\Http\Page\PageContext` (a controller argument typed so; `PageContextResolver`) and returns a `Response`:

      #[Route('/{lead}about{slash}', name: 'idno_about', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
      #[SitePage(name: 'Idno\Pages\About')]
      public function about(PageContext $page): Response
      {
          return $this->renderer->page($page, ['title' => 'About', 'body' => $this->renderer->template('pages/about', [])]);
      }

  That is a NEW page (the address never had a page): it is no shell (`shell` is false by default), registers nothing with the legacy router, and `name` is the label its body class and page
  events carry (`SitePageFixtureController` is the same). A page that MOVES out of a legacy class keeps what core registered for it, and its attribute says whether the class is kept:

      #[SitePage(name: 'Idno\Pages\Homepage', shell: true)]    // the class is kept as a shell, registered for its patterns
      #[SitePage(name: 'Idno\Pages\Feed')]                     // the class was deleted: the placeholder is registered for its patterns

  Core keeps registering each pattern, where and when it always did, for the kept shell's class or for the placeholder `Idno\Legacy\ControllerRoute` (the invariant under "Legacy pages and routes", which
  has the rule once). The attribute lists no patterns: `SitePageClaimsTest` derives what to check from the registrations (a key of `Tests/Kernel/fixtures/core-routes.php`, the recorded core table,
  that is gone or moved; a route whose sample path core's pattern matches and that the legacy router does not resolve to its own placeholder or shell).

  The arguments of `#[SitePage]` (`Idno\Http\Page\SitePage`): `name` (the page class a template, the body class and the page events' `page_class` know the page by: for a deleted class,
  its name), `shell` (true when the class `name` is kept as a deprecated shell and stays registered for the path; false when the class was deleted or never existed), `publicOnPrivateSite` (served to a visitor of a walled garden; the default refuses
  them), `accountPage` (under `/account/`: a signed API request is refused with the 403; it is a settings page too), `settingsPage` (under `/admin/`: holds stored secrets, so its Markdown form is a title and one line and a JSONP request is answered as HTML), `defaultType` (the template type when the request
  names none: `rss` for the feeds), `methods` (the methods the page serves, `['GET']` by default; HEAD is served when GET is listed and OPTIONS always; a method not listed is a 405, so a page that adds POST, PUT, DELETE or PATCH
  lists `GET` again: `['POST']` alone serves no GET) and `markdownPermalink` (the page is a post's permalink and answers its `.md` form). The route follows the convention (`{lead}`,
  `{slash}`, the methods the page declares plus HEAD and OPTIONS, `utf8: false` where a parameter may hold any byte: `PageRoutesTest`). A route under `/account` MUST set `accountPage` and one under
  `/admin` `settingsPage` (or `accountPage`): the flags default to off, so a forgotten one would leave the page open, and `SitePageRoutesTest` fails for the route that forgets it.
  FORM TOKEN: a site page that lists a state-changing method has the session's form token checked by its lifecycle before `page/<method>` fires (a signed API request needs none, as for a legacy
  page), so it carries NO `#[IsCsrfTokenValid]`; that attribute is for plain controllers. A MEMBER OR AN ADMINISTRATOR: the lifecycle has no gatekeeper of its own; a site page asks with `#[IsGranted('ROLE_USER')]`
  or `#[IsGranted('ROLE_ADMIN')]` on the method, as any controller does. The page is begun and entered first (`page/ready`, `page/head` and `page/get` have fired, as before a legacy
  page's gatekeeper ran), and the refusal is the page's own 403, drawn as every refusal of a site page is (below: `PageExceptionListener`). No core site page needs one;
  `SitePageFixtureController::drawGranted()` is the fixture and `WhoAmIController` shows the attributes on plain controllers.
  Draw through `PageRenderer` only (`page()`, `template()`, `notFound()`, `gone()`, `denied()`, `error()`, `redirect()`), never `drawPage()`, `header()`, `echo` or `exit`; set what the page is
  about on the context (`setOwner()`, `setPermalink()`, `addHeader()`); caching is asked ONLY with `PageContext::setCaching()` (a `Cache-Control`, `Pragma` or `Expires` on the `Response`, and Symfony's
  `#[Cache]`, are refused in debug mode and ignored otherwise). A plugin replaces the home page with the event `homepage/replace`, not a route. A legacy `Page` class still works for a third-party plugin.
  `PageContext::addHeader()` refuses the three caching names.
- Tests of the kernel's own handling of requests: `Tests/Kernel/FrontControllerTest` (the controllers' routes match in a kernel with no legacy application, a request Symfony rejects falls back to the
  legacy router with a warning, any other exception before routing is not served by it, the body is not consumed), `Tests/Kernel/KernelExceptionTest` (an exception before routing or from a controller
  that is not a site page is logged once, the catch-all logs nothing) and `Tests/Characterisation/KernelRoutingTest` (a controller's route wins over the catch-all, the header names of a legacy page and of a
  site page, every method, HEAD, OPTIONS and XHR reach the legacy router for a path that is not a controller's).
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
  a `Content-Length`), at most 20 MB; `/_test/gzip?bytes=N` (`Content-Encoding: gzip`, the gzip of N zero
  bytes, at most 50 MB decoded and a few dozen kilobytes on the wire); `/_test/sink/{name}` (appends the request, in `/_test/echo`'s
  shape, to a file of its own and answers 202) and `/_test/sink/{name}/log` (the recorded requests as
  a JSON list, then the file is emptied), with `name` matching `[a-z0-9-]{1,40}`; and
  `/_test/page/{name}` (an HTML page advertising `/_test/sink/{name}` as its webmention endpoint);
  `/_test/server` (JSON: `env`, `debug`, `opcache`: how the server that answers is set up; `ServerProbeController`); and
  `/_test/mail/log` (the mail the site has sent, recorded by `RecordingMailTransport`, as a JSON list
  oldest first, then emptied; see "Mail"). The
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
- **`idno:install`** (`Idno\Console\InstallCommand`; the only Idno command that runs on a site with no
  configuration): the web installer's checks and result from a terminal. Every setting is an option
  (`--url`, `--site-title`, `--database`, `--sqlite-path`, `--db-name`, `--db-user`, `--db-host`,
  `--upload-path`, `--admin-name`, `--admin-handle`, `--admin-email`) or a line of `--manifest=<ini>`
  (`site_title`, `url`, `database`, `sqlite_path`, `mysql_name`, `mysql_user`, `mysql_pass`,
  `mysql_host`, `upload_path`, `admin_name`, `admin_handle`, `admin_email`); what is missing is asked
  for unless `--no-interaction`. A password is NEVER an option: the database's comes from the manifest,
  `--db-password-file` or a hidden prompt, the administrator's from `--admin-password-file`,
  `--admin-password-stdin` or a hidden prompt typed twice. It refuses (exit 1, nothing changed) when a
  configuration file exists or the environment names a database (the site is installed), a server check fails, `--url` is missing or not `http(s)://host[:port]/`
  with nothing after it (`SiteAddress::fromUrl()`; an http address on a host that is not local gets a
  note), the database is MongoDB, a value cannot be written, the database cannot be used, or it already holds an Idno
  site and `--use-existing` was not given (a 1.x site would be upgraded, and its old version could no longer use it); it writes
  `configuration/config.ini` with `ConfigFile::writeOnce()`, then runs `idno:upgrade` and (unless
  `--no-admin`, or the database already held a site) `idno:admin:create --password-stdin` in processes
  of their own (this one booted with no configuration), and prints who owns the files. Unlike the web
  installer it takes a path for the SQLite file and for uploads and an http address: whoever runs it
  has the server already.
- **`idno:admin:create`** (`Idno\Console\AdminCreateCommand`): the site's first administrator, through
  `Idno\Install\FirstAdministrator` (`--handle`, `--email`, `--name`, `--site-title`,
  `--password-file`, `--password-stdin`, or prompts). It needs the tables (it says to run `idno:upgrade`
  while the gate is closed), refuses once the site has an administrator, and removes the setup marker.
- **`idno:setup:link`** (`Idno\Console\SetupLinkCommand`): makes a new setup token (the marker is
  rewritten: an earlier link and the installer's cookie stop working) and prints `<url>/_setup/<token>`,
  the link to the "Create your account" step, which works once; refuses once the site has an administrator.
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
state-changing browser request): the admin page `/admin/legacytables` is one, for that reason. So is
`/admin/welcome/` (`Idno\Pages\Admin\Welcome`, registered in `Idno\Core\Admin::registerPages()`): the page a new administrator lands on
after the installation, a GET that needs `adminGatekeeper()`. It is a legacy page, like every admin page: the admin pages are legacy pages,
and this one is drawn in their shell and menu with their gatekeeper.

**Shells.** A page whose logic moved to a service that a controller calls is kept for one major version as a deprecated SHELL: a class under `Idno\Pages\`
that extends `Idno\Legacy\PageShell`, is marked `@deprecated`, and has no logic of its own. Each ported page has ONE implementation, in the service the controller
calls; a shell declares only `getContent()`, `postContent()`, `putContent()`, `deleteContent()` and `webmentionContent()` (whichever it really implemented) and its
`SHELL` constant (its own class), and each handler is one `return $this->delegate(...)` (or `$this->delegateWebmention(...)`) of that service. So a third-party
page that extends a shell and a plugin's `hijackRoute()` or `addRoute()` of its route still
serve the page, through the same service, and nothing has to be kept in sync. The list of shells is `Tests/Unit/Http/PageShellsTest::SHELLS` (it scans `Idno/Pages/`
for classes that extend `PageShell`, and holds each to the rule); it is `Idno\Pages\Homepage`. Shells are removed in 3.0.
- `PageShell::delegate(\Closure $serve)` runs `$serve(PageContext): Response` on the request being served as a controller would. The context is built from the
  request on Symfony's `RequestStack` (which `LegacyResponse` pushes while legacy code runs; the process's request where there is none) and from what the shell
  holds: its `arguments`, the inputs it was given with `setInput()`, its owner, entity, permalink flag and status, and `SitePage(name: static::class)` (so the body class
  and the page the events name are the subclass's). A `ControllerPage` over the context is the current page while `$serve` runs, and the shell again after (what
  the page learned, its owner and status, is copied back). The `Response` is written as a legacy page writes one: the headers the page asked for are put on it, the
  header rule (`LegacyHeaderReconciler::reconcile()`) keeps the session's caching lines, `http_response_code()`, each header with `header()` (through `PhpHeaders`),
  and the body is echoed; the headers the page asked for are put on the response by `PageContext::applyTo()`, the one rule the controller's response listener
  (`PageRequestListener`) follows too. EVERY response the shell writes then ENDS THE REQUEST (`exit`), as `drawPage()` (which echoed and exited) and `forward()` did,
  whatever its status: code a subclass puts after `parent::getContent()` (a second page, say), Toro's `after_handler` and `after_request` hooks and `kernel.terminate`
  never run after a page served through a shell, as they never ran after the legacy page (`HomepageShellTest::testAPageEndsWhereTheLegacyPageEnded`, with a subclass
  that draws after the parent; `PageShellTest::testEveryResponseTheShellWritesEndsThePage`). What `$serve` throws is the legacy
  exit: a `PageRedirect` is `$this->forward()`, `AccessDeniedHttpException` `deniedContent()`, `NotFoundHttpException` `noContent()` and `GoneHttpException`
  `goneContent()`, run with the shell as the current page.
- Constructing or serving a SUBCLASS of a shell (not the shell itself) logs one line for each shell in a process (the flag is keyed by the shell, so a subclass of one shell does not hide a subclass of another), through the site's logger at warning level: `Deprecated: <class>
  extends <shell>, which is a deprecated shell ...`, naming `docs/developers/plugins/upgrading.md`. No bundled plugin extends a shell: the StaticPages plugin's static home page is the `homepage/replace` event's
  (below), so a site with one logs nothing (`StaticPagesHomepageTest::testASiteWithAStaticHomePageLogsNoDeprecation`).
- Tests: `Tests/Kernel/PageShellTest` (in subprocesses on a private site: what the shell hands the service and what it writes, the four exits, the deprecation
  line once a process), `Tests/Characterisation/HomepageShellTest` (a hijacked subclass and one on its own route over HTTP, the headers, a malformed Host, the
  shell's page byte for byte the controller's), `Tests\Fixtures\ThirdPartyHomepage` (`IDNO_TEST_CLAIM_ROUTES=homepage-hijack|homepage-route`).

**Feeds, search and tags.** The home page's stream under other addresses is `HomepageController` too, with no class of its own to keep: the three page classes that served the feeds, the search and the tags
are deleted (not shells: nothing ever extended them; `docs/developers/plugins/upgrading.md` has the table of every deleted class), and the legacy route table keeps a placeholder at each one's place. Each action is one call of
`HomePages` and its `#[SitePage(name: 'Idno\Pages\Homepage')]` gives the home page's body class and page events, so a feed or a tag page fires `page/ready`, `page/head` and `page/get` ONCE,
with `page_class` `Idno\Pages\Homepage` (one set of events and one set of `Link` headers for each request: `PageContractTest`). It is no shell (no shell is shadowed). The routes follow the convention (GET, HEAD, OPTIONS; `{lead}` and
`{slash}`; `Tests/Unit/Http/PageRouteConvention` exempts `idno_tag` from the trailing `{slash}` and from nothing else: the exemption requires no `{slash}` in the path, the path to end with its last parameter, and that parameter's requirement to take slashes; `PageRouteConventionTest` pins what it still fails on):
- `feed()`: `idno_feed_xml` (`/feed.xml`), `idno_feed` (`/feed`), `idno_rss_xml` (`/rss.xml`), `#[SitePage(defaultType: 'rss')]`: the stream drawn in the `rss` type unless the request names
  another (`?_t=json`; an `Accept` header does not choose, as before). It is not the root, so `homepage/replace` is not asked.
- `search()`: `idno_search` (`/search`): a 302 to `<url>?q=<urlencode(q)>` (`HomeStream::siteAddress()` is the configured `url`); a `q` that is not text (`q[]=x`) is an empty query (it was a
  500 page, `urlencode()` of an array).
- `tag()`: `idno_tag` (`/tag/<tag>`, requirement `[^\s]+` as the legacy pattern, `utf8: false`: a tag may be any bytes except white space, which a path cannot carry). The tag is read from the
  path AS SENT (`Request::getPathInfo()` is not decoded), one trailing slash is removed and the rest is `urldecode()`d ONCE, so `+` is a space and `%2B` a plus (`/tag/a%2Bb` is `#a+b`; it was
  `#a b`, decoded twice); the stream's query is `#<tag>` and wins over a `q` in the query string (it was the request's `q`), for every content type (`all`), and the page's own `q` (`PageContext::replaceInput()`, which wins over the request's field, where `setInput()` yields to it) is the tag, so a template that reads it shows the tag and never a stray `q`. A tag that is only slashes (`/tag//`) is the 404 page, a deliberate change: the legacy pattern `/tag/([^\s]+)\/?` matched it with the tag `/` and the page listed `#/`, and a tag `/` is not a tag.
`/tag/` and `/search/mentions.json` are not these routes. POST, PUT, PATCH and DELETE of these paths, and a request with a malformed `Host`, are the legacy router's: no page, its 404 page (a POST was
the invalid-token redirect home). A webmention to one of these addresses is `target_not_found` (it was `source_not_supported` or `target_not_supported`): the endpoint asks the legacy router for the target's
page and there is none (`WebmentionToADeletedPageTest`). A plugin that `addRoute()`s the identical pattern, `hijackRoute()`s one that matches, or holds an exact key for one of these paths serves it, as it did when core's page was a class (mode
`feeds` of `IDNO_TEST_CLAIM_ROUTES`); one registered after core's with another pattern does not take the path, as before. A plugin that EXTENDS one of the deleted classes stops the site booting until it is disabled
(`docs/install/upgrade.md`).

**The small public endpoints.** `robots.txt`, the web app manifest, the service worker script, WebFinger, the mention search and the form token endpoint are controllers (`SiteFilesController`, `WebfingerController`,
`MentionSearchController`, `FormTokenController`), and the seven page classes that served those addresses (and `/bookmarklet.js`) are DELETED (the table is in `docs/developers/plugins/upgrading.md`)
(their `addRoute()`s stay, held by the placeholder `Idno\Legacy\ControllerRoute` where core registered them, WebFinger's in `Idno\Core\Webfinger::registerpages()`; `/bookmarklet.js` has none). WebFinger is the one page whose registration core makes AFTER the plugins: core's `addRoute()` of its pattern replaces in place the handler of a plugin's identical registration (and of a `hijackRoute()` of it made at plugin time), so a plugin does NOT take WebFinger that way, as before; only an exact key, a different pattern registered before core's, or a `hijackRoute()` made after core's registration takes it. Each is one call of a service in `Idno\Http\Controller\Discovery\` (`SiteFiles`, `Webfinger`, `MentionSearch`; the form token's logic is in its controller),
whose legacy-backed facts are interfaces (`SiteIcons`, `WebfingerDirectory`, `MentionCandidates`) implemented in `Idno\Legacy\` (`LegacySiteIcons`, `LegacyWebfingerDirectory`, `LegacyMentionCandidates`), so each service is
built and unit-tested without the legacy application (`Tests/Unit/Http/Controller/Discovery/`, `Tests/Kernel/LegacyWebfingerDirectoryTest`). Each `#[SitePage]` carries the deleted class's name (the page events' `page_class`) and is no shell (core registers the placeholder for each pattern as it
registered the class: `/robots\.txt`, `/chrome/manifest\.json`, `/service-worker(\.min)?\.js`, `/\.well\-known/webfinger/?`, `/search/mentions\.json`, `/service/security/csrftoken/?`); `publicOnPrivateSite` is
true for the manifest, the service worker and WebFinger, as `addRoute(..., true)` said, and false for `robots.txt`, the mention search and the token endpoint (a visitor of a private site is refused). Deliberate changes, each pinned:
`/bookmarklet.js` is gone (404: it answered an HTML fragment as `text/html`; the `entity/bookmarklet` template stays for the tools page); `/service-worker.js` AND `/service-worker.min.js` serve the committed `js/service-worker.min.js`
as `application/javascript` (the unminified address read `js/service-worker.js`, a gitignored build output, and was a 500 page wherever it was not built; a release without the shipped file is the 404 page: `SiteFiles::serviceWorker()`);
`/search/mentions.json` is `application/json` (was `text/json`; open to whoever may see the site, nothing but handle, name and avatar); the token endpoint reads no `url` (a missing one was a 500 page), answers the session's one stored
token (`FormToken::value()`) with `Cache-Control: no-store` through `setCaching()` (the session's `Expires` and `Pragma` stay as on every page, no `Last-Modified`), and still answers only an XHR request (the drawn 403 otherwise);
WebFinger answers a `resource` that is not text with the 404 page (was a 500 page), and `links` is always a list (`LegacyWebfingerDirectory`: the `webfinger` event's response when it is an array, else `[]`; it printed `true` when no listener returned a list); `robots.txt` is `text/plain; charset=UTF-8`; other methods and a malformed `Host` are the legacy router's 404 page; a webmention to any of these paths is
`target_not_found` (`WebmentionToADeletedPageTest`); an XHR GET gets the headers of the same request that is not XHR. A plugin that extends a deleted class stops the site booting until it is disabled (`docs/install/upgrade.md`,
`docs/developers/plugins/upgrading.md`). `Tests\Fixtures\LegacyPlainPage` (`/_test/legacy-plain`, `?throw=1` to fail) is the legacy page the lifecycle tests hold a controller's page to.

**Replacing the home page: the event `homepage/replace`.** A plugin that wants the site's root to be something other than the stream does not extend a shell or
`hijackRoute()` the root: it listens to the event `homepage/replace`, which `HomePages::home($page, root: true)` triggers FIRST, through `EventTrigger::trigger('homepage/replace',
['page' => $page, 'renderer' => $renderer], $keepResponse)` (an `Idno\Queue\EventTrigger` taken as a service closure, like the logger, so the service is built in a kernel with no
legacy application). `page` is the `Idno\Http\Page\PageContext` of the request and `renderer` the `Idno\Http\Page\PageRenderer`. The contract:
- A listener sets a `Symfony\Component\HttpFoundation\Response` as the event's response (`$event->setResponse($response)`) and that response IS the home page: the
  lifecycle (events, walled garden, headers, caching, `Last-Modified` from `PageContext::addHeader()`) is the controller's, as for any home page. Anything else a
  listener leaves or sets (the event's default `true`, `false`, `null`, a string, an array, an object that is no `Response`) is ignored and the stream is drawn.
- THE FIRST `Response` WINS. `HomePages` triggers the event with a rule for the response it keeps (`$keepResponse`: `EventTrigger::trigger()`'s third argument,
  `EventDispatcher::triggerEvent()`'s fourth, `Event::keepResponseWhen()`): once the event's response is a `Response`, a later listener's `setResponse()` changes
  nothing, so a later listener can neither replace it with its own page nor erase it by setting its default (`false`). A non-`Response` answer is replaced by a later
  one. An event triggered without the rule is as every event is: the last listener to set anything wins. A listener that sets `$event->forward()` makes `triggerEvent()`
  send `Location` and `exit` (every event does); a listener must not.
- A listener draws through the renderer it is given (`$renderer->page($page, ['title' => ..., 'body' => $renderer->template('name', [...]), 'description' => ...])`,
  `$renderer->redirect($page, $location)`, `denied()`, `notFound()`, `gone()`), never with `echo`, `exit`, `header()` or `drawPage()`: nothing is sent or ended by it,
  so the lifecycle's header rule and `kernel.terminate` still apply. It sets what the page is about on the context (`setOwner()`, `setPermalink()`,
  `addHeader('Last-Modified', ...)`) before it draws.
- ACCESS. Two rules, and a listener answers only the second. (1) The site's own rule runs BEFORE the event: the controller's lifecycle refuses a visitor of a private
  site (`walled_garden`) with the 403 page unless the home route is public (`addPublicRoute('Idno\Pages\Homepage')`: the question asked is about the home page's route, the
  page a plugin puts there is not asked about), so a replacement is never reached by a visitor the site refused. (2) The listener must respect the VIEWER's access to what
  it puts in place of the stream, because the replacement is served to everyone the first rule lets in: it checks `canRead()` (or the equivalent) for the viewer, and for
  a viewer who may not read it it returns nothing (no `Response`: the ordinary home page is served), not a refusal and not a redirect to `/` (which asks for the same page
  again: an infinite redirect for a visitor). So on a private site whose home route a plugin made public, a static page with public access is shown and one that is not
  public is not.
- ROOT ONLY, decided by the caller. The one place that serves the site's root as the home page, `HomepageController::home()`, passes `root: true`; `content()`
  (`/content/<types>`), `feed()`, `search()` and `tag()` never ask, which is how the stream stays reachable. A subclass of the deprecated `Idno\Pages\Homepage` shell that a
  plugin registered for the root (`hijackRoute('/')`) is NOT replaced (the shell's handlers never offer the root): a plugin that took the root with its own page keeps it
  and a static home page does not override it. The decision does not depend on the path, so a site under a subdirectory and a request for `/index.php` are the
  root as the controller's route has matched them (`StaticPagesHomepageMatrixTest::testTheRootIsReplacedUnderASubdirectoryAndAtTheFrontController`).
  The event fires after the page's own events (`page/ready`, `page/head`, `page/get`) and before the stream is asked anything, and is counted by the site's statistics
  (`event.homepage/replace`) on each request for the root, whether or not a listener answers.
- A listener that throws fails the page as a page that throws does (the 500 page, the exception reported once); `home()` does not catch it, and a broken listener
  therefore cannot do worse to the home page than a broken page can. A listener that answers nonsense is ignored (above).
- Replaced: the StaticPages plugin's former home page class (a subclass of the shell that hijacked `` and `/`, and registered `/content/default/?` for the core page so the
  stream stayed reachable) is deleted. `IdnoPlugins\StaticPages\HomepageReplacement::respond(PageContext, PageRenderer): ?Response`, built by the plugin's
  `homepage/replace` listener (`Main::registerEventHooks()`, always registered: it asks for the setting at each request), answers: null when no home page is set; the
  page by id, else by slug; a page that is gone or is no `StaticPage` is unset (`Main::clearHomepage()`, which only an administrator's session can do) and null is
  returned, so the stream is served at once with no redirect; a page the viewer may not read (`Entity::canRead()`: its access, a draft that is not the viewer's, a group
  the viewer is not in) is null and nothing is unset; a page with a `forward_url` redirects a visitor (not an administrator) there; otherwise the page's owner,
  `setPermalink()` and `Last-Modified` are set on the context and the page is drawn in the shell with the title, the body `staticpages/page` and the short description.
  What differs from the subclass: the body class and the event data's `page_class` name `Idno\Pages\Homepage` (the page is the home page's, not a class of the plugin),
  the order of the lines of different names in the header block (the controller's order, as for every ported page), `OPTIONS /` and a forward carry `Content-Type` where the
  legacy lines read `Content-type` (header names are case-insensitive), and a home page the viewer may not read is the stream, where the subclass redirected to `/` (an infinite
  redirect for a visitor). Pinned by `Tests/Kernel/StaticPagesHomepageReplacementTest` (the answers, and that the plugin listens), `Tests/Unit/Http/Controller/HomePagesTest` (the event's
  data, the rule for the response it keeps, the root flag, a throwing listener), `Tests/Core/EventKeepResponseTest`, `Tests/Characterisation/StaticPagesHomepageTest` and
  `Tests/Characterisation/StaticPagesHomepageMatrixTest` (over HTTP: each kind of chosen page (public, members only, owner only, group, draft, deleted, missing, with a
  forward address) for a visitor, a member, the page's owner and an administrator, on an open site, a walled garden and a walled garden with a public home route, in the
  default, json, rss and markdown types and HEAD and OPTIONS, no deprecation line; fixture listeners that throw, set a non-`Response`, and compete (`IDNO_TEST_HOMEPAGE_LISTENERS`
  in `Tests\Fixtures\TestEventHooks`); a subdirectory install, `/index.php`, a third-party page that took the root).

A page that a controller serves (a site page: a controller method or class carrying `#[Idno\Http\Page\SitePage]`, which says `shell: true` when its
page class `name` is kept and core still registers it for the path) and the legacy router share the site's addresses:

- The route convention, checked by `Tests/Kernel/PageRoutesTest` for every site page route in the container's route collection (the check itself,
  `Tests\Unit\Http\PageRouteConvention`, is pure and proven on synthetic routes by `PageRouteConventionTest` in the unit tier): the path is `/{lead}<rest>{slash}`
  (`/{slash}` for the root) with `requirements: ['lead' => '/?', 'slash' => '/?']` and `defaults: ['lead' => '', 'slash' => '']`; the methods are
  exactly those the page declares plus HEAD (with GET) and OPTIONS (`SitePage::allowedMethods()`, so `GET`, `HEAD`, `OPTIONS` for a page that reads);
  a route whose last parameter may hold any byte (a requirement that is not a plain ASCII class) sets `utf8: false`, as the legacy catch-all does.
  `{lead}` and `{slash}` make one route answer `/x`, `/x/`, `//x` and `//x/`, which the legacy patterns (`/x/?` under Toro's `^/?`) answered, with no
  redirect (verified against the installed `CompiledUrlMatcher`; the optional slashes are captured, so Symfony's trailing-slash redirect never applies). Symfony
  also lets ONE more trailing slash match (`/x//`); a legacy pattern that ends in `/?` (most do) matched it too, one that does not answered its 404 (pinned in
  `RouteOverrideTest`).
- A POST, PUT, DELETE or PATCH (any method the route does not list) never matches the route: it reaches the catch-all, so a kept shell still
  serves it through `Page::post()` and the other handlers, with its own form token check; a path with no shell answers the legacy 404 as before. A
  controller that serves a state-changing method lists it in `#[SitePage(methods: [...])]` and in its route.
- The invariant: THE LEGACY ROUTE TABLE IS EXACTLY WHAT IT WAS BEFORE ANY PAGE MOVED: the same keys, in the same order, registered at the same moments of the boot. A page that moves
  to a controller does not delete its registration: core registers the legacy pattern(s) again where it always did, with a placeholder, `Idno\Legacy\ControllerRoute`, in place of
  the class that was deleted (`Idno::registerPages()` for the home page's streams, the feeds, search, tags, the manifest, the service worker, the mention search, robots.txt and the
  token endpoint; `Idno\Core\Webfinger::registerpages()` for WebFinger, which `LegacyBootstrap::boot()` builds AFTER the plugins, so it still registers after them; `/bookmarklet.js`
  was removed outright and has no registration: a plugin's broader pattern, or the 404, answers it as for any unknown path). A kept shell is still registered under its own class.
  `ControllerRoute` is no `Page` and has no method: a request the legacy router dispatches to it (a method the controller does not take, a spelling Symfony's router did not match)
  fires Toro's `404` hook, the site's 404 page, for every method, as a path with no registration did; a webmention aimed at it finds no page (`target_not_found`). Third-party code
  that `hijackRoute()`s or `addRoute()`s BY PATTERN works as it did; code that referred to the deleted class by NAME does not (documented in the upgrade notes).
  Because the table is what it was, every rule the legacy router has still decides what a plugin's registration does, with no rule of ours: the router chooses an EXACT KEY first (a
  plugin's `addRoute('/feed.xml')` or `addRoute('/')` is the path's key), then the first pattern that matches in registration order; `addRoute()` appends a new key and for an existing
  key replaces the handler IN PLACE (an identical pattern replaces the placeholder and keeps core's place); `hijackRoute()` removes the key and puts it in FRONT of every other (the
  home page's alias key `/blog/` for `hijackRoute('/')` on a site in a subdirectory too: `LegacyDispatcher::routes()` writes it); a plugin's pattern registered before core's matches
  first and one registered after loses (core registers `Idno::registerPages()`' pages before the plugins are built, at its end: `Idno.php` "These must be loaded last"; the routes of
  `Webfinger`, `Account`, `Admin`, `Webmention` and `PubSubHubbub` are registered after the plugins', so there a plugin's broader pattern, and a plugin's `hijackRoute()` core's
  later `addRoute()` of the same key overwrites, behave as before); a registration core makes later overwrites an earlier one under the same key.
  `Idno\Legacy\LegacyRouteOverride` (`kernel.request`, priority 31, just after `RouterListener` at 32) therefore asks ONE question of the legacy router's own lookup over the whole table
  (`PageHandler::claimsOf()` over `handlerFor()`: the exact key first, then the patterns in order, Toro's algorithm, pinned against Toro by `Tests/Unit/Markdown/RouteLookupTest`): which
  page would you choose for this path? When it is a placeholder, or the controller's own kept shell (the `#[SitePage]` `name` when it says `shell`), core's place is held and the controller
  serves; when it is anything else (a handler that is no class name too), a plugin took the path and the request is handed to the legacy controller: `_controller` becomes
  `LegacyController`, the route's parameters are removed and `_route` is `idno_legacy`, so the catch-all's code serves it as if no controller had matched, for every method including HEAD
  and OPTIONS. A path is asked as it can be given (`PageHandler::spellingsOf()`, whose docblock has the derivation from `Request::prepareBaseUrl()`, `preparePathInfo()`, the matcher and
  Toro): the path Symfony matched (its path info with the site's subdirectory in front, as the table is keyed: never the base URL, the script's name) raw and decoded once, and what the
  legacy router itself is given (`toroPath()`: `PATH_INFO` where the server sets it, else the raw `REQUEST_URI`, which carries the front controller's name where the server does not split
  it: nginx's shipped configuration), each with slash runs made one; any of them naming another page is a claim. Toro then routes the path it is given, which is what a hand-back means: where that is a spelling its
  patterns do not match (an encoded one, with only `REQUEST_URI`) it answers the 404 it always did, and where it lands on core's placeholder it answers the 404 too, which is NOT what it
  always did for one case: a spelling Toro routed to core's page whose plain form a plugin took with an EXACT key (the plugin registered `/feed.xml`; `//feed.xml` reached core's feed and now
  reaches the placeholder, the 404 page; likewise `//tag/x` with an exact `/tag/x`). The controller's page is never served for an address a plugin took, in any spelling, and a plugin's page serves
  exactly the spellings the legacy router itself resolves to it.
  The shell itself is shadowed for GET, HEAD and OPTIONS only. Differences from the legacy router alone: an encoded spelling of a path nobody took (`/x%61`, `/%2Fx`, `/x%2F`) is served by the controller as the
  plain spelling is, where a server that gave the legacy router only the raw path answered a 404; the controller also serves `/index.php` (Symfony's path is `/` there; the legacy router
  had no page for it), unless a plugin took `/`; a dot segment (`/y/../x`) is no spelling Symfony matches, so it stays the legacy router's.
  `LegacyDispatcher::routes()` writes the subdirectory's alias of the home page into the router's table once, whoever asks first (the dispatcher and `LegacyRouteOverride` both ask).
  A `#[SitePage]` lists no patterns (the override asks the table, so nothing undeclared can fail open), and `SitePageClaimsTest` holds the table to the recorded snapshot.
- The `.md` suffix. A GET or HEAD whose path ends in `.md` is the Markdown form of a permalink where the legacy router would treat it as one
  (`PageHandler::routeMarkdownSuffix()`: the path as given matches a `MarkdownPermalink`'s route, or matches none and without the suffix routes to one);
  `claimsOf()` reports that as a claim with `markdown: true`. The convention: a page that replaces a `MarkdownPermalink` (or is the permalink of a post) says so,
  `#[SitePage(markdownPermalink: true)]`, and then it answers the form itself: `PageRequestListener` takes the `.md` off the last route parameter (the route may take
  it with its parameter, as the legacy pattern did: `[^/]+`; `_route_params` and the request attribute are rewritten, so the controller reads the same slug in both
  forms), `PageContext::markdownSuffixRequested()` is set and `LegacyPageLifecycle::begin()` selects the `markdown` type as a permalink page's
  `selectMarkdownIfWanted()` did (an explicit `?_t=` of another type wins, as for a legacy permalink). A page that is not marked while its shell is a `MarkdownPermalink`
  is a violation (`SitePageClaimsTest`), and at run time the override hands its `.md` request back to the legacy router, so the Markdown form is served by the shell
  while that is registered. A Markdown permalink a plugin took (a `MarkdownPermalink` class that is not the controller's shell) keeps its Markdown form and its HTML form, whichever
  the controller is. A route that takes no free parameter never matches `<path>.md`, which the legacy router then serves as it did.
- Moving a page to a controller. The page keeps core's registration of its pattern(s) where and when core made it, with `ControllerRoute` in place of a class it deletes (or its
  shell's own class, which its attribute names with `shell: true`: a class that plugins may extend is kept as a deprecated shell, because deleting a class makes `addRoute()` skip it silently and breaks a
  plugin's subclass of it); a page registered after the plugins (a component `LegacyBootstrap::boot()` builds after `Idno`) keeps that registration in the
  component that made it. Nothing else is needed for a plugin's registration to keep its effect, and a broader pattern of core's own that follows (a profile's general page after its edit
  page) never takes the moved path: the controller's route matched first, and the placeholder holds the place in the table. A malformed `Host` request, which the legacy router serves,
  finds the shell where there is one and the 404 page where the page is only a controller. `Tests/Kernel/SitePageClaimsTest` enforces it over the real route collection and the booted
  site's real table (core and the plugins the site enables), from the registrations alone: for each site page route, when a snapshot pattern (or a placeholder or the route's shell in the
  table) matches a sample path of the route, the legacy router's choice for that path is the placeholder or the route's OWN shell (its `name`, when it says `shell`), under a key core
  registered, so a route whose path core's pattern matches must have its placeholder or shell registered; a page whose shell is a `MarkdownPermalink` says `markdownPermalink`; and the keys of the WHOLE core table, in order and on the same side of the plugins' marker, are `Tests/Kernel/fixtures/core-routes.php`, generated from the tree before any page moved (less `/bookmarklet\.js` and
  StaticPages' own routes, each listed with its reason), whichever component registers them (`Idno`, `Account`, `Admin`, `Webfinger`, `Webmention`, `PubSubHubbub`), so a page that moves cannot silently drop, reorder or re-side one. Synthetic tables and
  controllers prove each check can fail. It sees the plugins the rig enables only (Styles and StandardSiteSync are not among them).
- Cost: the listener does nothing for any request that is not a site page's (a check of the request and one attribute lookup, cached per controller), and
  builds nothing when the legacy application is not booted (the dispatcher is a service closure: `LegacyRouteOverrideTest::testNothingHappensWithoutTheLegacyApplication`;
  `LegacyFallbackListener` takes the legacy controller as a service closure too, so in a kernel with no legacy application the dispatcher is never built, pinned by
  `PageListenersWithoutLegacyTest`; `KernelExceptionTest` and the upgrade tests stay as they were). For a site page's request it makes one lookup of the whole table for each distinct spelling
  of the path (usually one): an exact-key lookup, then the patterns in order until one matches: about 1 microsecond for a path core registered early (the home page, the feeds), 16 for one
  in the middle of the table and 33 for the last (WebFinger), measured on 190 patterns.
- A malformed `Host`, and a site in a subdirectory. A request Symfony rejects before routing (a malformed `Host`) is served by the legacy router (`LegacyFallbackListener`),
  which serves what it registered. A site in `/blog` registers its patterns under it (`addRoute()` adds `IDNO_SUBDIRECTORY`, which the legacy bootstrap derives from
  `PHP_SELF`; `hijackRoute()` adds no prefix, so in a subdirectory it never matches), Symfony's base URL is `/blog`, and the router is asked about the path with it
  (`toroPath()` and `REQUEST_URI` carry it). `?_t=` types are the page's, whoever serves it (`?_t[]=x` asks for no type).
- A `markdownPermalink` page sends `Vary: Accept` on every answer (`PageRequestListener::onResponse()`: the HTML and the Markdown form, a refusal, a HEAD, OPTIONS), as the
  legacy permalink pages did.
- Pinned by `Tests/Kernel/PageRoutesTest` (convention, spellings, methods, base URL) and `Tests/Unit/Http/PageRouteConventionTest`, `Tests/Kernel/PageHandlerChoiceTest` (the
  choice and the spellings: hijack, identical and later patterns, the exact key, encoded paths, the `.md` form, the subdirectory in a subprocess, `toroPath()`),
  `Tests/Kernel/LegacyRouteOverrideTest` (in process, with the router's table), `Tests/Kernel/SitePageClaimsTest` and, over HTTP on a site of its own with the
  fixtures of `Tests/Fixtures/TestEventHooks` (`IDNO_TEST_CLAIM_ROUTES`: `replaced`, `1`, `identical`, `subclass`, `plugin`, `mdclaim`, `broad`, `corehijack`, `coreidentical`, `feedhijack`, `hijackbroad`, `moved-none`, `moved-identical`, `moved-hijack`, `moved-exact`, `moved-broad`, `moved-prehijack`, `moved-hijackroot`, the `moved-*` ones with `Tests\Fixtures\LateRoutes` registering the late path's (`/.well-known/webfinger`) after every core component's; the claimed modes also register `/_test/legacyonly/?`, a claimed path with no controller, whose answer in each spelling is what a claimed path answered before a controller served it, registered on `plugins/loaded`, the first
  event after every plugin's `registerPages()`; `IDNO_TEST_SERVER_NO_PATH_INFO=1` makes the server give the legacy router only the raw path, as Apache and nginx do;
  `IDNO_TEST_SUBDIRECTORY=blog` serves the front controller as `/blog/index.php`), `Tests/Characterisation/RouteOverrideTest`.

`Idno\Legacy\` is where the shims between the kernel and the legacy application live
(`LegacyBootstrap`, `LegacyServiceFactory`, `LegacySiteSettings`, `LegacyController`,
`LegacyDispatcher`, `LegacyRouteOverride`, `LegacyResponse`, `LegacyFallbackListener`, `FatalErrorPage`,
`VerbatimLogFormatter`, `NotInstalledException`, for the site pages controllers serve `ControllerPage`, `BarePage`, `ControllerRoute`, `PageShell`,
`LegacyPageLifecycle`, `LegacyPageDrawer`, `LegacyHeaderReconciler` (with `HeaderQueue` and `PhpHeaders`) and the adapters `LegacyHomeStream`, `LegacySiteIcons`, `LegacyWebfingerDirectory` and
`LegacyMentionCandidates`, and for storage `LegacyRecordStoreFactory`,
`LegacyRecordDefaults`, `LegacyAccessContext`, and the `db()` service of SQL sites,
`RepositoryDataConcierge`, with `LegacySqlMaintenance`). They
exist to be removed as the legacy code is replaced. New code must not depend on anything
in `Idno\Legacy\`; depend on the interfaces in `Idno\Config\` and on injected services.
The only classes outside `Idno\Legacy\` (and outside the legacy `Idno\Core`, `Idno\Common`,
... code) that reference it in code is `Idno\Kernel`, for wiring (others name an adapter in a docblock only);
the entry points (`index.php`, `bin/console`, `Idno/start.php`) and the kernel configuration
(`config/services.php`, `config/routes.php`, `config/packages/monolog.php`) wire it too. No
other new code may.

A page a controller draws has a current page too (`Idno::site()->currentPage()`), because templates, helpers and plugins ask it for
the request's input, address, entity, owner, assets and status (`Idno\Core\Idno`, `DefaultTemplate`, `LegacyTemplateHelpers`,
`Syndication`, `Webmention`, the bundled plugins' entities all do), and a controller serves no `Page`. An attribute and a per-request
context in `Idno\Http\Page\` and two shims in `Idno\Legacy\` (all `final`, excluded from the container scan) provide it; the home page, the feeds, the search,
the tag pages and the small public endpoints are served through them (and the fixtures of the tests):

- `Idno\Http\Page\SitePage` (an attribute on a controller class or method; the method's wins; `SitePage::of()` takes an object, a
  `Class::method` string, an `[object|class, method]` array or an invokable, cached per class and method; a method that does not exist has the class's attribute) says which legacy page the
  controller serves: `name` (the page class: the body class and, through the controller wiring, `page/get`'s `page_class`; `Page::pageClassName()` is what the body class reads, and a legacy event carries `get_called_class()`, the same for a page that is its own class), `shell` (the class `name` is kept as a shell whose legacy
  routes it shadows; false when the class was deleted), `publicOnPrivateSite`, `accountPage`, `settingsPage`, `defaultType`, `methods` and `markdownPermalink` (a permalink that answers its `.md` form: see "Legacy pages and routes" and "Markdown output").
  `Idno\Http\Page\PageContext` (not a service; the request's attribute `_idno_page`, `PageContext::of()` throws without one, `has()`,
  `attachTo()`) is the state a legacy page kept in its properties and the superglobals, for one request: `input()` (`requestField()`, a POST field over a
  query field, never a cookie; then `dataField()`: what `setInput()` set, then the fields of a `json` input and of the body of a request
  whose REAL method is not GET (an override header does not hide a body), the earlier winning, as `Page::parseJSONPayload()` merged them), `isXhr()` (`X-Requested-With`), the permalink flag, entity, owner, assets per class,
  status (200), the `.md` suffix flag, a recorded redirect and the work to do after the response, headers (`addHeader()` replaces, or adds with `$replace` false, the name in any letter case; never for `Cache-Control`, `Pragma` or `Expires`), caching (`setCaching()` takes the
  lines of `Cache-Control`, `Pragma` and `Expires`, a string or a list for each, and replaces the lines of the names it sets and no others, so the
  router's XHR lines and then `setNoCache()` add up as they did; `ownsCaching()` says the page asked; `cachingLines()`), `requestUri()` (the raw `REQUEST_URI`, which `Page::currentUrl()` uses) and `arguments()`
  (the route's parameters but `lead`, `slash` and the framework's `_*`, in order, or what `setArguments()` set). `PageContextResolver` gives a
  controller argument typed `PageContext` that of the request. `PageRedirect` (a `\RuntimeException` with the `location`, an optional `body` that an API request gets in place of a redirect, and the `status`, 302
  unless a 307 keeps the method) is what a page throws where a legacy page forwards and exits.
- `Idno\Legacy\ControllerPage` (a `Page`) answers what the legacy application asks of the current page from the context, and
  answers as `Page` does (`Tests/Kernel/ControllerPageEquivalenceTest` asks a real legacy page and a controller page the same questions
  of 36 requests: queries, an encoded plus, HEAD and PUT, `X-Forwarded-Proto`, `HTTPS`, port 443, XHR, a referrer, a malformed Host, a
  subdirectory install, `?_t=` types, POST over query, a `json` field, tables of `Accept` headers): `getInput()` is `Page::getInput()` line for
  line (the request's field, else a non-null default, goes through the filter; only when that is null is `setInput()` or the JSON payload read,
  so a non-null default hides them; the filter runs again on the result when the first answer was empty), `setInput()`, `xhr()` and `$xhr`,
  `$arguments`, `response()`/`setResponse()`, the entity, permalink, owner (false when none) and assets (false when none) accessors,
  `currentUrl()` (the configured `url`'s scheme, host and port plus the raw request address, so `doesPathMatch()` and `matchUrl()` work),
  `referrer()`/`getReferrer()` (the `Referer`, ''), `isAcceptedContentType()` (the request's `Accept`, else, when it is absent or empty, `REDIRECT_HTTP_ACCEPT` from the request's server bag, with the old
  algorithm, quirks included: a space after a comma stays in the key and a tie takes 1 off the quality), `isSecure()` (`Page::isSSL()`'s rule read
  from the request), `setNoCache()` (`Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache`: the legacy page's
  third header, `post-check=0, pre-check=0`, is not sent), `setLastModifiedHeader()`, `pageClassName()` (the attribute's `name`);
  `getIcon()` and `forwardToLogin()` work through those. The one deliberate difference in input: a cookie is never input (a legacy page's
  `$_REQUEST` held cookies where PHP's `request_order` let it). Where a legacy page exits it throws what the response is made from:
  `deniedContent($title)` `AccessDeniedHttpException` (the title is its message), `noContent()` `NotFoundHttpException`, `goneContent()`
  `GoneHttpException` (each after setting the status), `forward()` a `PageRedirect` with the location `Page::forward()` computes (the template
  type, when it is not the default, in `_t`; the session closed first, as `finishEarly()` did; `forward_trace` logged and sent as
  `X-Idno-Forward-Trace`; a POST, PUT or DELETE sent as an XHR request does nothing, as before; an API request gets the JSON
  `{"location": ...}` in the redirect's `body` instead of a `Location`), `sslGatekeeper()` a 307 `PageRedirect` (when `Config::hasSSL()` says the site wants https: an https `url` or `force_ssl`; `ConfigHasSslTest`), `exception()` rethrows (the controller
  wiring must not leave the exception handler `Page::init()` installs pointing at a `ControllerPage`); `forward($location, false)`, which a
  legacy page used to send the redirect and carry on, records the redirect on the context and returns, and `PageRequestListener::onResponse()`
  puts it on whatever the page answers (a 200 becomes the redirect; an API body is put before the page's); `AfterResponse::run()` for a
  controller page registers its work on the context (`PageContext::afterResponse()`) and forwards, the listener runs it on `kernel.terminate`
  (no time limit; a failure goes to `reportException()`), and the PageRedirect is turned into the response by `PageRequestListener::onException()`
  (`kernel.exception`, priority 10, which calls `allowCustomResponseCode()`: without it Symfony makes a 500 of the 200 an API request's forward or
  the empty answer of work after the response is; `Tests/Kernel/PageRedirectKernelTest` goes through the real kernel; a forward that went on, then one that exits: the second wins); `lastModifiedGatekeeper()` does nothing (a site page answers no conditional request with a 304) and
  `data()` is always empty (the context reads a JSON payload on demand). The Markdown members work from the request: `wantsMarkdown()` (the
  request's `Accept`, `format`, `_t` and the context's suffix flag), `sendVaryAccept()` (a `Vary: Accept` added to the context),
  `selectMarkdownIfWanted()`, and `drawMarkdown()`, which RETURNS the document (a legacy page echoed it and exited) and records the Markdown
  headers on the context; the canonical `Link` is kept apart (`PageContext::addHeaderOnSuccess()`) and added by the response listener only to a response whose
  status is 200, whatever the page's own status was, and the session's messages are used up as `drawPage()` did (`drawPageWithoutSideEffects($echo, $shell, true)`).
  `DefaultTemplate` reads the page's status (`pageStatus()`), not `http_response_code()`, for a `ControllerPage`. Every other member that would read the process's request, send a header, set the status,
  echo or exit throws a `\LogicException` naming it (`get()`, `post()`, `put()`, `delete()`, `head()`, their `_xhr` forms, `options()`,
  `webmention()`, `parseJSONPayload()`, `flushBrowser()`): the router's dispatch, which a controller replaces. `Tests/Kernel/ControllerPageMembersTest`
  walks every public method `Page` declares and fails for one that `ControllerPage` neither overrides nor lists as pure (with the reason; the list's
  methods are token-scanned for `header()`, `http_response_code()`, `echo` and `exit`; the static `isSSL()`, `getallheaders()`, `getBearerToken()` read
  the process's request and are called by class name, never through a page). Its constructor does not run Component's (no `init()`, no
  event hooks, no `page/ready`, and it does not make itself the current page): whoever draws the page does. A `null` context is a request for `/` served as the home page.
  `PageHandler::isRoutePublic()` (which takes a class name or the page), `isAccountPage()` and `isSettingsPage()` answer a `ControllerPage` from its
  attribute OR what was registered with the legacy router for the class it stands for (its `name`, which is its shell's when it has one: `addRoute()` under
  `/account/` or `/admin/`, `addPublicRoute()`, `hijackRoute()` and the public flag of `addRoute()`), so a flag left off the attribute never opens what a
  registration closed and a plugin's opt-in keeps applying (an account page is a settings page, as a route under `/account/` is), and
  `Session::publicGatekeeper()` passes the page to `isRoutePublic()`. A route under `/account` or `/admin` whose controller is a site page must
  carry the flag: `Tests/Kernel/SitePageRoutesTest` scans every route (`{lead}`, `{slash}` and a leading subdirectory placeholder
  accepted) and fails for one that does not. The refusal of an API request on an account page is the lifecycle's `refusesApiRequest()`,
  which asks the attribute's own `accountPage` and `isAccountPage()` of the current page (`SitePageLifecycleTest` proves it for a flagged controller, `ControllerPageTest` for a
  class only the router knows). `Page::pageClassName()` is `static::class`, and `getBodyClasses()` builds the page's body class from it.
- `Idno\Legacy\BarePage` is a page with no content, for an answer no page produced (the 404 page of `LegacyDispatcher`, the 403 of
  `PageHandler` for an API request on an account page and of `LegacyForbiddenPage`, and the stand-in current page of tests): it runs `Page::init()` as
  the home page did, and carries the home page's body class (`pageClassName()` is `Idno\Pages\Homepage`: the golden `not-found.html`).
  A test that listens for an event removes its listener (`Idno\Core\EventDispatcher::removeListener()`). Pinned by `Tests/Unit/Http/Page/PageContextTest` and `SitePageTest` (unit tier) and `Tests/Kernel/ControllerPageTest`.
- The page lifecycle of a controller (`Idno\Http\Page\PageLifecycle`, an interface; `Idno\Legacy\LegacyPageLifecycle` the adapter, aliased
  and public in `config/services.php` so that a test can ask whether it was built): what a legacy page did around its handler, in the same
  order. `PageRequestListener` (`#[AsEventListener]` on `kernel.controller` at priority 0, so before Symfony's attribute listeners (`ControllerAttributesListener`, which runs `#[IsGranted]` at -10000);
  constructor: the kernel and the lifecycle as a SERVICE CLOSURE) does nothing unless the request is the main one, `Kernel::legacyBooted()` and
  `SitePage::of($event->getController())` is not null: a plain controller, a sub-request, the legacy catch-all, `/_upgrade/step`, a `bootLegacy:
  false` kernel and a kernel with the schema gate closed never build the lifecycle and install no PHP error handler
  (`Tests/Kernel/PageListenersWithoutLegacyTest`, `Tests/Unit/Http/Page/PageRequestListenerTest`). In this order:
  1. A method the page did not declare is refused (`MethodNotAllowedHttpException`, 405) before anything is begun or fired. A page serves GET (and
     HEAD with it) and OPTIONS unless `#[SitePage(methods: [...])]` names more (`SitePage::allows()`); the route's `methods` must include
     `OPTIONS` (Symfony would answer 405 where a legacy page answered 204) and the declared methods.
  2. The `PageContext` is built and attached to the request, and `begin()` runs (`Page::init()`: the `defaultType` of the attribute set as
     input `_t` when the request names none, the template type from `_t` (`TypeChain::requested()`, requestable and existing types only), the
     current page made a `ControllerPage` whose XHR flag is still off, `$_SERVER['HTTP_REFERER']` made non-empty, `Idno::embedded()`, `page/ready`; PHP's own
     `X-Powered-By` is removed while headers are not sent, because the response adds the page's beside it). Then, for an XHR request that is not a GET, a HEAD or an OPTIONS (the router's
     `<method>_xhr` handlers sent them; an XHR GET and the HEAD that answers like it get the headers of the same request that is not XHR, which is a stated
     difference from the legacy router, pinned in `CachingHeadersTest`, `PortedPagesTest` and `SitePageLifecycleTest`), what the legacy router sent: `Content-Type: application/json` as a default only (the
     page's own type wins, as the shell's replaced it), `Last-Modified` now, and, as the page's caching (`setCaching()`, so the page owns those three
     names from here), `Expires: Mon, 26 Jul 1997 05:00:00 GMT`, TWO `Cache-Control` lines (`no-store, no-cache, must-revalidate` and `post-check=0,
     pre-check=0`) and `Pragma: no-cache`, in place of the session's lines (`LegacyHeaderReconciler` sends them).
  3. `refusesApiRequest()` (an `accountPage`, or a page the router registered as one, and `Session::isAPIRequest()`: the warning `API request
     to an account page refused: <name>` and the template type from `Accept`), for OPTIONS too, as the router's check ran for every method.
  4. OPTIONS: the controller is replaced by one answering 204 with `Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS, PUT, DELETE` and
     `Access-Control-Max-Age: 86400`, as `Page::options()` did. Nothing more.
  5. `mayView()` (`publicGatekeeper()`'s rule: a public site, or a logged-on visitor, or `publicOnPrivateSite`).
  6. `enter()`: the XHR flag is set (so `page/ready` saw it off, `page/head` sees it on, as for a legacy page), `autodetectTemplateType()`, then
     for GET `page/head` with `['page' => <the current page>]` and `page/get` with `['page_class' => <the attribute's name>, 'arguments' =>
     <the context's>]`; for HEAD `page/head` with the `page_class` and `arguments` and NO `page/get`, as a legacy HEAD (the controller still runs,
     for its headers; Symfony strips the body). For a declared POST, PUT, DELETE or PATCH: `page/head` with the page, then the session's form
     token is checked (a signed API request needs none) and only then `page/<method>` fires: a POST without it logs `Invalid token.`, adds the
     session message and forwards (a `PageRedirect`; an XHR page is refused instead), the others throw `AccessDeniedHttpException` ("The page you were on
     timed out."), an ordinary refusal: the site's 403 page, never reported (no critical line, no `oopsAlert()` mail), so a stranger's PUT
     cannot make the site mail its administrator (`Page::put()` threw `SecurityException`, the reported 500 page); a legacy page fired
     `page/post` before it checked, which a listener may no longer see for a request that fails.
     Stated differences from a legacy page, each pinned in `SitePageLifecycleEquivalenceTest`: an XHR POST, PUT or DELETE with a bad form token is
     refused with a 403 (a legacy page's `forward()` did nothing for an XHR request, so it went on and answered 200 with no body); PATCH can be
     declared on a controller's page and then gets the token check and `page/patch` (a legacy page had no PATCH: the router answered 404); and a method
     the page did not declare is a 405 with an `Allow` header (every legacy page had `post()`, `put()` and `delete()`, so PUT and DELETE reached the
     handler's defaults and only PATCH was a 404).
  EVERY refusal of a site page is drawn ONE way, as a legacy page's `deniedContent()` was: `PageExceptionListener` answers it with `PageRenderer::denied()` (the
  site's 403 page in the page shell for the web types, the JSON shell for `json`, the type's own form otherwise; the session's messages shown and used up; the session's
  caching lines; the headers of the type and the page's own; nothing logged as an error), whatever refused it: a refusal of an API request (step 3, Symfony Security's
  `AccessDeniedException`, with the session message `Account pages are not available to API requests.` the legacy router's refusal added; the type autodetected, so a
  signed request is answered in JSON), the walled garden's (step 5, an `AccessDeniedHttpException`, the type from `_t` only: `publicGatekeeper()` ran before `Accept` was
  read), `#[IsGranted]` on the method (after `page/get`, as a legacy gatekeeper ran in the handler) and the page's own `deniedContent()`. `DeniedResponder` answers only
  controllers that are not site pages. Steps 3 and 5 run no `page/head` or `page/get`. The one difference from a legacy refusal of an API request: the 403 is drawn in the
  page itself (the legacy router built a second page, which said `page/ready` again). `SitePageDrawingTest::testEveryRefusalOfASitePageIsDrawnAsALegacyPagesDeniedContent`
  compares each, status, headers and body, with the legacy refusal of the same caller in the default, json and markdown types.
  On the response (`kernel.response`, priority 16, before the reconciler) of a site page the listener adds `standardHeaders()` (`X-Powered-By: https://idno.co`,
  `X-Clacks-Overhead`, `X-Idno-Build-Fingerprint`: none when `IDNO_UNIT_TEST` is defined, as `init()` sent none), the context's default headers
  (only where the response has none of the name), the context's headers (a name replaces the controller's unless the page began it with
  `addHeader(..., $replace: false)`: `PageContext::replacesHeader()`; `Link` is added). The caching the page asked for is NOT put on the response: see the header rule.
  `LegacyPageLifecycle::reportException()` is what a legacy `Page::exception()` reported (one critical line `<message> [<file>:<line>]`, the
  `error.exception` statistic, `Logging::oopsAlert()`) and never throws; `PageRequestListener::onTerminate()` calls it for work after the response
  that fails. Pinned by `Tests/Characterisation/SitePageLifecycleTest` (over HTTP, on `Tests\Fixtures\SitePageFixtureController`: the routes
  `/{lead}_test/site-page/{state,public,account,typed,args/{word},post,patch,undeclared}{slash}`, in the convention of a page's routes),
  `SitePageLifecycleEquivalenceTest` (the same request through a real legacy page and the fixture: the events with their order and data, the
  refusals, the headers) and `Tests/Kernel/LegacyPageLifecycleTest`. `Tests/Kernel/SitePageRoutesTest` scans the real route collection for a
  site page under `/account` or `/admin` without its flag.
- Errors on a site page (`Idno\Http\Page\PageExceptionListener`, `#[AsEventListener]` on `kernel.exception` at priority 2, a service in a scanned
  directory; constructor: the kernel, and the renderer and the lifecycle as SERVICE CLOSURES). It does nothing, and builds nothing, unless the request is the main one,
  `Kernel::legacyBooted()` and the request carries a page context (`PageContext::has()`): the legacy catch-all, `/_test/*` routes, `/_upgrade/step`, a `bootLegacy:
  false` kernel and a closed schema gate never fetch the renderer or the lifecycle (`PageExceptionListenerTest`, `PageListenersWithoutLegacyTest`; `KernelExceptionTest`
  is unchanged). Priority 2 puts it above the firewall's exception listener (1), which answers the Security exceptions of every controller that is not a site page,
  and above Symfony's `ErrorListener` (0), which logs: a response set here
  stops propagation, so Symfony never logs a site page's exception (no `request.ERROR` or `request.CRITICAL` line for it). What it answers, in the request's template
  type, each with `allowCustomResponseCode()` so that the kernel keeps the status:
  - `NotFoundHttpException` (what `ControllerPage::noContent()` throws): `PageRenderer::notFound()`; `GoneHttpException` (`goneContent()`): `gone()`;
    `AccessDeniedHttpException` (`deniedContent($title)`, its message the title, and the walled garden's refusal): `denied()`; each logs nothing.
  - a Security exception (`AccessDeniedException`: `#[IsGranted]`, the lifecycle's API refusal; `AuthenticationException`, a failed `#[IsCsrfTokenValid]` among them):
    `denied()` with no title, as `deniedContent()` with none; nothing logged, nothing reported.
  - a `PageRedirect` (`forward()`) never reaches it: `PageRequestListener::onException()` (priority 10) answers every one of a main request first, with
    `PageRedirect::toResponse()`, logs nothing and forgets a forward the page made and went on from. A forward the page made and went on from is carried by the
    page's own response by the same rules (`PageRedirect::statusWithLocation()` and `PageRedirect::contentType()`, the JSON type, the one deliberate addition: a legacy page sent none).
  - any other `\Exception`: `PageLifecycle::reportException()` FIRST (what `Page::exception()` reported: one critical line `<message> [<file>:<line>]` from the site's
    logger, the `error.exception` statistic, `Logging::oopsAlert()` under the same conditions), then `PageRenderer::error()`: status 500, `pages/500`, which shows the
    exception's message only when `ExceptionMessage::forVisitor()` says so (friendly, or `debug`), and for `json` and `jsonp` the shells' `exception` object. So a 500 is logged
    once, by the site's logger, as a legacy page's was.
  - an `HttpExceptionInterface` with a 5xx status (a `ServiceUnavailableHttpException` a page chose to throw) is a failure of the page like any exception: reported once and drawn as
    the 500 page at its own status (`PageRenderer::error($context, $e, $status)`), carrying the headers the exception asks for (`getHeaders()`: `Retry-After` of a
    503), on the plain-text answer too. Any other HTTP exception below 500 (a bad request, a method not allowed) is left to Symfony,
    which logs and answers it as it does for any controller.
  - an `\Error` (a `TypeError`, a call on null): Symfony dispatches nothing for it, so `PageRequestListener::onControllerArguments()` (`kernel.controller_arguments`, priority
    -10100: after the attribute listeners such as `#[IsGranted]` (-10000), which read the controller's own signature) replaces the controller of a site page, for the main request with the
    legacy application booted, by one that calls it with its resolved arguments and throws `Idno\Http\Page\PageError` for an `\Error`, carrying the original as `previous` and as
    `$error`. The listener unwraps it, so the logged class, message, file and line are the ORIGINAL error's, and the answer is the same as for an exception: reported once and drawn as the
    500 page (the message under `ExceptionMessage::forVisitor()`'s rules: an `\Error` is technical, so shown only when `debug` is set). Other controllers and the legacy catch-all keep
    today's behaviour, an `\Error` ends at `FatalErrorPage`. The wrapper is given the attributes the controller had, less `#[Cache]` (refused above), so the
    attribute listeners of the phases after it still see them. An `\Error` thrown by a listener of the page's own events while the page is begun or entered
    (`PageRequestListener::onController()`: a plugin's `page/head` listener) is carried as a `PageError` too. Not covered: an `\Error` thrown by an argument resolver or by
    another Symfony listener (it ends at `FatalErrorPage`).
  - a Security exception anywhere in the chain of `previous` exceptions (`AccessDeniedException`, `AuthenticationException`) is the refusal it is, as the firewall's own listener reads the chain. So an exception that merely WRAPS a Security exception (a Twig `RuntimeError` around an
    `AccessDeniedException`) is answered as the refusal, a 403, and is neither reported nor mailed nor drawn as the 500 page, exactly as for any Symfony controller;
    but it is logged once, as a warning that names the wrapping exception's class (never its message or anything of the request), so that a real fault whose
    chain holds a refusal is not silent (the listener takes the site's logger as a service closure; `PageExceptionListenerTest`,
    `SitePageDrawingTest::testAWrappedRefusalIsARefusalAndIsLoggedOnceAsAWarning`).
  - when drawing the answer throws (a template, a plugin's extension of the shell), the answer is a plain-text response of the same status (the status's own words, `Cache-Control:
    no-store`; for the 500 nothing of the exception), and the drawing failure is reported once; no second error page is drawn, so nothing loops.
  `framework.handle_all_throwables` stays FALSE (see "Kernel and services"): turning it on would also change what an `\Error` in any `kernel.request` listener of any request becomes,
  which the controller wrapper above avoids. Differences from a legacy page's error: a 5xx HTTP exception is drawn at its own status (a legacy page's exception handler always sent 500).
  Other differences from a legacy page's error, each pinned in `SitePageDrawingTest` (the one in the structured data's `url` is normalised in the comparison and nothing else): a legacy
  500 page was drawn after its request had left the request stack, so the `url` of its structured data was empty and a page under `/admin` or `/account` that threw was drawn in
  the account shell; a controller's 500 page says its address and is drawn in the shell of its directory. An exception after output began is answered as before (the output
  stays, the page follows it, the status is what PHP could still change). Pinned by `PageExceptionListenerTest` (unit tier), `SitePageDrawingTest` (`testErrorPages`:
  the 404, 410, 403 and 500 against a real legacy page for the same case, thrown directly and through the current page as legacy code calls it, in five template types, as
  a visitor and a member, in the settings directories; `testARedirectAndA404LogNothing`, `testA500LogsOneCriticalLine`,
  `testAnExceptionThrownAfterOutputBeganIsAnsweredAsALegacyPageDid`, `testAPageThatCannotBeDrawnEndsAsPlainTextAndIsReportedOnce` (one `Content-Type`),
  `testAnErrorIsTheSites500PageAsItIsForALegacyPage`, `testNothingTechnicalIsShownWhenDebugIsOff` (a site with `debug` off: a friendly exception, a technical one, a 5xx and an `\Error`, in the web page, JSON, JSONP and Markdown types, the same as a legacy page, nothing technical shown), `testAServerErrorExceptionIsTheSites500PageAtItsStatus`, `testAClientErrorExceptionIsSymfonysAndNotReportedAsAFailure`, `testAnErrorAndAServerErrorAreMailedToTheAdministratorAsAnExceptionIs`,
  `testAWalledGardenRefusalIsTheDrawn403`: the caching lines of the refusal, line by line) and `SitePageLifecycleTest::testAWalledGardenRefusalIsTheDrawn403AndIsNotLoggedAsAnError`. The `throw-*` cases of the two
  fixtures (`LegacyDrawPage`, `SitePageFixtureController`) stay identical.
- Drawing a site page into a Response (`Idno\Http\Page\PageRenderer`, a service in a scanned directory, public because `ControllerPage::forward()` takes the
  forward rule from it; it depends on the interfaces `PageDrawer` and `Idno\Templating\TemplateTranslation`, so that a test can double it, and
  `Idno\Legacy\LegacyPageDrawer` implements `PageDrawer` over `Idno::site()`, built when the renderer is and reading the application only when something is
  drawn). `page($context, $vars, $status = 200, $shell = 'shell')`: the status is the context's when the caller passes 200 and the page set another
  (`setResponse()`), and the context is given the status it ends with, because templates read the page's status (`http_status()`); the body and the headers
  come from `PageDrawer::drawPage()`, which sets the variables on the SITE's template object (so `leaked_user()`, a JSON page's printed variables and a
  template that reads what an earlier draw left behave as on a legacy page), puts `http_response_code($status)` in place for the draw (the Markdown shell reads
  it) and back afterwards (a process that had none, the CLI's, has none again: `header('HTTP/1.1 0')`, PHP having no call that unsets it; and once the output has begun nothing is set, because PHP warns), and calls `DefaultTemplate::renderPage($status, $shell)`: `drawPage()`'s work (the
  shell chosen, with the `/admin` and `/account` overrides; the session's messages taken and flushed, so they are shown once; the session closed; the headers of the
  template type worked out BEFORE the draw, as `sendShellHeaders()` did; the private settings-shell flag reset afterwards) with the headers returned as `[name, value]`
  pairs instead of sent and the shell returned instead of echoed. The headers the DRAW sent itself with `header()` (a plugin's extension of `shell/headers`, a
  third-party shell: the extension point `shell/headers` still is) are taken too, and so is what the draw does to the type's own: under a web SAPI `LegacyPageDrawer` sends
  the type's headers into PHP's list at the moment `drawPage()` sent them (`DefaultTemplate::renderPage()`'s `$sendHeaders` callback: after the session is closed, before the
  shell is drawn), reads `headers_list()` before and after, and `Idno\Http\Page\AddedHeaders::between()` (pure) works out the lines that are new and whether each replaced a
  header of its name (it did when PHP's line is gone), was added beside it, or, with none held, replaces unless `ShellHeaders::replaces()` says the name is added (`Link`); the
  new lines are removed from PHP's list (what PHP held of those names put back) and carried on the `DrawnPage`, each with its replace flag as a third element: so a
  third-party shell's own `Content-Type` wins over the type's declared one, a plugin's `Access-Control-Allow-Origin` replaces core's, one added beside it is sent after it, a
  `header_remove()` of one of the type's headers takes it off, and nothing is sent twice. The read-back is in a `finally`: a draw that fails leaves none of the headers it (or the type) sent in PHP's list, so the error page that answers is not given them. A status the draw set with `http_response_code()` is the response's
  (`DrawnPage::$status`) and a `Location` it sent is answered as PHP answers one (302 unless the status is a 201 or a 3xx: `PageRedirect::statusWithLocation()`). Under the CLI
  (no list) the type's declared headers are the response's, unchanged (`SitePageDrawingHeadersTest`, a site with `IDNO_TEST_DRAW_HEADERS=1` and the templates of
  `Tests/Fixtures/DrawHeaders`). A cookie is never taken (PHP adds the session's), and where PHP keeps no list (the CLI) there is nothing to take: that is observed over HTTP, a
  `php -S` of the test's own. The response takes each pair with its flag (without one, `ShellHeaders::replaces()`: every name replaces but `Link`), so a repeated
  header keeps its order, and `Content-Length` is the length in bytes of the body, as a legacy page sent it (a HEAD answer keeps it: it is the GET's);
  `PageRequestListener::onResponse()` works the length out again, last, when the response has one or work is registered after the response, so that a forward's body put
  before the page's is counted (the one place it is computed after any redirect body). Not set by the renderer: the standard and caching headers (the listener's).
  `template($name, $vars)` draws one template with the variables set the same way. `notFound()` (`pages/404`, title `t("This page can't be found.")`), `gone()`
  (`pages/410`, `t("This content isn't here.")`), `denied($context, $title = '')` (`pages/403`) and `error($context, $e)` (`pages/500` with `exception`, title
  `Exception`) set the context's status (404, 410, 403, 500) and draw the body before the shell, as `noContent()`, `goneContent()`, `deniedContent()` and `exception()`
  did; they log and alert nothing (`reportException()` is the listener's: a caller that uses `error()` alone gets no report). The forward has ONE rule,
  `PageRenderer::forward($context, $location, $end, $status, $frames)` (what `Page::forward()` did): null for an XHR POST, PUT or DELETE (the page cannot forward and goes
  on); an empty location is the site's address (`PageDrawer::displayUrl()`); a template type other than `default` is carried in `_t` (`PageDrawer::urlWithVar()`, the template's
  own `getURLWithVar()`); the session is closed first when the page ends (`PageDrawer::finishSession()`, `finishEarly()`); the trace when the site sets `forward_trace`
  (`PageDrawer::forwardTrace()`: `X-Idno-Forward-Trace` on the context, `trace` in the JSON); and an API request, decided by the SESSION's flag (`PageDrawer::isApiRequest()`,
  `Session::isAPIRequest()`, which stays true when a stateless credential left the request anonymous, a blocked member's signed request) is not redirected but given
  the JSON `{"location": ...}` at the page's status. It returns a `PageRedirect`, made into the Response without deriving anything again by
  `PageRedirect::toResponse()` (a pure method, the ONE place it is built: `PageRequestListener::onException()` answers a thrown redirect with it too, so the two never differ:
  a `Location` and an empty body at PHP's status for a `Location`, or the JSON body with `Content-Type: application/json`: the legacy page echoed the JSON with no type of its own,
  so `text/html`, the one difference); `redirect($context, $location = '', $status = 302)` is `forward(...)->toResponse()` (an empty answer at the page's status where the forward does nothing),
  and `ControllerPage::forward()` is the same `forward()`, so a thrown `PageRedirect` and a returned redirect never differ. A forward keeps the status the page set when PHP would keep it (a 201 or a 3xx: pinned by `SitePageDrawingTest::testAForwardOfAPageThatSetA201KeepsIt`), else it is the `$status` asked for (302). `PageDrawer` has methods beyond drawing for this
  (`isApiRequest()`, `finishSession()`, `forwardTrace()`, `displayUrl()`, `urlWithVar()`). Pinned by `Tests/Unit/Http/Page/PageRendererTest` and `AddedHeadersTest`
  (doubles), `Tests/Kernel/LegacyPageDrawerTest` (the CLI's status) and, over HTTP on a private site, `Tests/Characterisation/SitePageDrawingTest`: a REAL legacy page
  (`Tests\Fixtures\LegacyDrawPage`, registered in the test environment by `TestEventHooks` on `plugins/loaded` at `.../draw-legacy`, with a class of its own under `/admin/`
  and `/account/`) and the fixture controller (`.../draw-new`, `Tests\Fixtures\SitePageFixtureController`) do the same for each `case` (`draw`, `drawmessage`, `permalink`,
  `notfound`, `gone`, `denied`, `error`, `technical`, `redirect`, and the `throw-*` cases that stop the page by a call or an exception: see "Errors on a site page") and the two answers are compared whole: the status, every header by name with a repeated header's values in
  order, and the body, in every template type (`default`, `json`, `jsonp`, `rss`, `jsonfeed`, `kml`, `markdown`, `activitypub`), as a visitor and a member, in the two
  directories that have a shell of their own, by `Accept` and as an XHR request, for a HEAD, and for a signed API request (also of a blocked member). What the
  comparison treats as the same: the name of the page in its own address (also in the base64 of a `fwd` link, and the class in the body's classes), a stack trace, the
  transport's headers (`Date`, `Server`, `Connection`, `Transfer-Encoding`), a cookie but for the session id and its date, `Content-Length` as the length of its own body (and
  present for a HEAD, where it is the GET's), the space after the `;` of a media type. Every other header is compared line by line, in the order sent (`Cache-Control` included: one line, the
  session's, never the second a response computes, and no `X-Robots-Tag`). The cases `cached` (`Last-Modified` set through the context, a fixed time) and `no-cache`
  (`setNoCache()` through the current page) carry the caching rule. Keep a case of the two fixtures
  identical.

- The headers of a site page (`Idno\Legacy\LegacyHeaderReconciler`, `kernel.response` priority 8, after `PageRequestListener::onResponse()` (16) and before Symfony's
  `ResponseListener` (0); it does nothing unless the request is the main one, the legacy application booted, the request has a `PageContext` and the response is not a
  `LegacyResponse`; `reconcile(Response, PageContext, bool $knownType)` is the same rule for a caller that has a response in hand). A legacy page's caching headers
  are PHP's: the session's cache limiter queues `Expires: Thu, 19 Nov 1981 08:52:00 GMT`, `Cache-Control: no-store, no-cache, must-revalidate` and `Pragma: no-cache`
  at `session_start()` for every request, a visitor's too, a page adds `Last-Modified` with `header()` (the newest listed post's `updated` on a stream) and sends no
  `ETag`; the legacy router sends its own lines for an XHR request of a legacy page (a page a controller serves answers an XHR GET and HEAD with the lines of the same request that is not XHR) and `setNoCache()` two `Cache-Control` lines and `Pragma`. A Symfony `Response` always has a
  computed `Cache-Control` (sorted, `no-cache, private`, or `private, must-revalidate` once `Last-Modified` or `Expires` is set; `ResponseHeaderBag` recomputes it
  whenever one of those is set) and a `Date`, and `Response::sendHeaders()` sends every header but `Content-Type` with `header($line, false)`, after what PHP holds:
  without a rule a page sends two `Cache-Control` lines. The rule: (1) `Cache-Control`, `Pragma` and `Expires` are never the response's (taken off it, however it came by
  them), and PHP's lines are sent as PHP queued them; (2) for a name the page asked for with `PageContext::setCaching()` (`ControllerPage::setNoCache()` does), PHP's
  lines of that name are removed and exactly the lines the page asked for are queued with `header()`, the first replacing, the others added: so `Cache-Control` may
  be two lines, and `Expires` stays the session's after `setNoCache()`, as it did; where PHP keeps no header list (the command line, or after output began: every
  in-process test) the response carries what the page asked for, one line a name; (3) `Last-Modified` is the response's when it has one (PHP's queued line is
  removed), else PHP's stands; (4) a response with no `Content-Type` and a type that declares none (a third party's) takes the `Content-Type` PHP queued, because a response
  with none is given `text/html` when it is prepared and sends it replacing PHP's; (5) everything else (`Link` from `page/head` and the plugins, `Vary`, `X-*`), `Date`
  and `Set-Cookie` is left alone, the response's lines beside PHP's, so `Link` values add up; (6) nothing else is the page asking for caching: a `Cache-Control`, `Pragma` or `Expires` that is merely on the response (a controller set it, or a `BinaryFileResponse` did:
  it sets `public` by itself) is NOT honoured, so a page is never made more cacheable than the session's lines by accident. It is taken off the response like the computed one; in
  debug mode a value the framework did not set itself (the three `Cache-Control` values a response computes and a `BinaryFileResponse`'s `public`) is refused with a
  `LogicException` naming `PageContext::setCaching()`, and otherwise it is ignored and one warning is logged for the process. `PageContext::addHeader()` refuses the three names too. A site page's refusal or file response must not set `Cache-Control`, `Pragma` or `Expires` on the Response object (a port of the legacy file page's admin-only 403, which sent `private, no-store` itself, asks through `setCaching()`); the two answers the site makes itself are exempt, with their `no-store` replaced by the session's lines: a refusal (`DeniedResponder`) and the plain 500 that `PageExceptionListener` answers when the error page cannot be drawn (both mark the request with `DeniedResponder::ATTRIBUTE`), so in debug mode a page whose error page fails still answers a 500 and never an exception.
  A file-serving site page asks the supported way for what the legacy file page (`Idno\Pages\File\View`) sent: a public file `Pragma: public`, `Cache-Control: public` and a
  30-day `Expires`; a restricted one `Cache-Control: private, no-cache`; an administrators' one `Cache-Control: private, no-store` and `Pragma: no-cache`; `Last-Modified` is the
  entity's, through `addHeader()` (it replaces the one `BinaryFileResponse` sets from the file's date); `ETag` is none unless the controller sets one; `Accept-Ranges`, range
  answers and `Content-Disposition` are the `BinaryFileResponse`'s (`SitePageDrawingTest::testAFileASitePageServesKeepsWhatTheLegacyFilePageSent`). The rule runs again at priority -20,
  for whatever sets caching on the response after the first pass. Symfony's `#[Cache]` is refused on a site page in debug mode (`PageRequestListener`: a `LogicException`) and ignored
  otherwise: the controller wrapper is not given the attribute, so its listener never runs; a refusal's own `no-store` (`DeniedResponder`) is not an ask either;
  (7) an empty answer (an OPTIONS 204) is sent the `Content-Type` PHP's `default_mimetype` gives it, as a legacy page's was (a prepared empty response drops its type and
  switches the default off for the process: the setting is recorded before and put back by the second pass, and by `kernel.terminate` should something have stopped it); (8) `X-Robots-Tag: noindex`, which Symfony's `DisallowRobotsIndexingListener` (-255) adds in debug mode, is
  taken off a site page's response (priority -300) only if it was not on the response before that listener ran (`noteRobotsTag()`, -250), so a page that says `noindex`
  keeps it in every environment, and a legacy page never had the one Symfony adds; the responses of every other controller (`/_upgrade/step`, `/_test/*`) keep it and keep every header they have. A
  header a draw sends itself with `header()` for one of the three caching names stays in PHP's list (`AddedHeaders` never takes them): it is PHP's to send. The seam over
  PHP's functions is `Idno\Legacy\HeaderQueue` (`listed()`, `queued()`, `remove()`, `send()`; `PhpHeaders`), so the rule is tested against a recording double
  (`Tests/Kernel/LegacyHeaderReconcilerTest`) and over HTTP in `Tests/Characterisation/CachingHeadersTest` (the real pages, line by line, for a visitor and a member, written
  from what the site sent before any page was ported), `SitePageDrawingTest` (`cached`, `no-cache`, the `Link` lines, no `X-Robots-Tag`), `SitePageLifecycleTest` (XHR) and
  `Tests/Kernel/PageListenersWithoutLegacyTest` (no legacy application: nothing changes). Two differences from a legacy page remain, neither of them a caching header: the order of lines of DIFFERENT names (PHP's lines first, then the response's), and
  `Date`, which the response always carries and which therefore comes after PHP's lines, where the server wrote its own first. (OPTIONS had a third, its `Content-Type`:
  it is the same now.) Every call to PHP's header functions on the controller path (`LegacyPageDrawer`, `LegacyPageLifecycle`, the reconciler) goes through `HeaderQueue`
  (and `ini_get`/`ini_set` of `default_mimetype`: `HeaderQueue::defaultMimetype()`); `HeaderSeamTest` scans every file of `Idno/Http/` and the page-path adapters of `Idno/Legacy/` for a direct call, with only `PhpHeaders` allowed (the install redirect is `InstallGateListener`'s response); the drawer asks `listed()` again before it reads PHP's list back, because a draw may have flushed output.

### Kernel and services

- `Idno\Kernel` is configured by `config/` (`bundles.php`: FrameworkBundle, MonologBundle,
  DoctrineBundle (DBAL only, no ORM) and TwigBundle (see "Templates"); `packages/framework.php`: Symfony's session and error
  handling are disabled, the legacy application owns both; `packages/doctrine.php`: the DBAL
  connection, see below; `services.php`; `routes.php`).
  `Kernel::boot()` stops for a site with no configuration (`LegacyBootstrap::installed()` is false: `Kernel::installed()`, see "Installation"),
  asks the schema gate (`Idno\Upgrade\SchemaGate`, a public service, unless a kernel has already
  booted the legacy application in this process: `LegacyBootstrap::bootedInThisProcess()`), and
  boots the legacy application through `Idno\Legacy\LegacyBootstrap` only when the gate is open;
  `Kernel::legacyBooted()` says whether it did. Pass `bootLegacy: false` to the constructor to boot
  the kernel alone, as some tests do: such a kernel never asks the gate, and the gate's listener
  does nothing in it.
- `framework.handle_all_throwables` is FALSE (`config/packages/framework.php`): Symfony dispatches `kernel.exception` for an `\Exception` only, and an `\Error` thrown
  outside a site page's controller (a `kernel.request` listener, any other controller) ends at `FatalErrorPage`, as before. A site page's controller is the one place an `\Error`
  is made an exception (see "Errors on a site page").
- The listeners around site pages (`LegacyRouteOverride`, `PageRequestListener`, `PageExceptionListener`, `LegacyHeaderReconciler`, `LegacyFallbackListener`) take what needs the legacy application as SERVICE
  CLOSURES (the dispatcher, the lifecycle, the renderer, the logger) and do nothing, building nothing, unless the request is the main one, `Kernel::legacyBooted()` is true and the controller is a site page
  (`PageListenersWithoutLegacyTest`). `framework.disallow_search_engine_index` is left on (Symfony's `DisallowRobotsIndexingListener` adds `X-Robots-Tag: noindex` to a response with none in debug mode); the
  reconciler takes the tag off a SITE PAGE's response unless the response had it before, and every other controller keeps it. `Idno\Core\Session::init()` sets `session_cache_limiter('nocache')` itself,
  so the session's caching lines, which a site page sends in place of the response's, do not depend on the host's `php.ini`.
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
  `Console`, `Legacy`, `Storage`, `Upgrade`, `Queue`, `Mail`, `Portability`, `Templating`, `Security`, `Install`) and registers the classes as autowired, autoconfigured
  services, except the files it excludes (classes that are not services, or that are wired
  by hand). Value objects and static helpers in a scanned directory are not services and
  must be added to the `$excludes` map in `config/services.php` (today in `Idno/Storage/`:
  `ConnectionFactory.php`, `ConnectionParameters.php`, `Criteria.php`, `Criterion/`, `EntityClassFinder.php`, `EntityQuery.php`,
  `Attribute/` (attribute classes), `FixedAccessContext.php`, `FixedRecordDefaults.php`, `IdentityTypeMap.php`, `Migrations/` (the schema migrations),
  `Files/` (the file store, below), `MySqlSemantics.php`, `Platform/`, `QuoteNormaliser.php`, `RecordDefaults.php`, `RecordIndex.php` and `SettingsRecordStore.php`; in `Idno/Legacy/`, `FlysystemFile.php`, `FlysystemFileSystem.php`, `RepositoryDataConcierge.php`
  and `LegacySqlMaintenance.php`, which `LegacyServiceFactory::db()`
  builds, and `MessengerEventQueue.php` and `LateLogger.php`, which `LegacyServiceFactory::queue()` builds; in `Idno/Upgrade/`, the value objects `UpgradeProgress.php` and `PublicMessage.php`, the
  enum `SchemaStatus.php`, the exception `LegacyTableReferenced.php`, the static helpers
  `ServerVersion.php` and `UpgradePage.php`, and the copier's helpers `ConfigCopier.php`,
  `DocumentCheck.php`, `LegacySource.php`, `RowWriter.php`, and `Mongo/`, the MongoDB import, built with
  new by its command), or the container will try to autowire
  them. `RecordStore` implementations (`InMemoryRecordStore.php`,
  `JsonRecordStore.php`, `SettingsRecordStore.php`) are excluded too: the container's `RecordStore` is built by a factory (see
  the storage services below). The container's `Idno\Storage\TypeMap` is `AttributeTypeMap` (an alias in `config/services.php`). `BootConfigConnectionFactory.php` is
  excluded from the scan and wired by hand. In `Idno/Kernel/`, the compiler pass
  `DoctrineTrimPass.php`, `MessengerTrimPass.php` and `TwigTrimPass.php` are excluded (the kernel adds them in `build()`); in
  `Idno/Queue/`, the message `QueuedEvent.php`, the interfaces `EventTrigger.php`, `EntityCache.php` and
  `UserContext.php`, `ResilientTransport.php` (a decorator) and `KeepFailedMessage.php` (wired by hand),
  `StopWhenQueueIsEmpty.php` (built by the command) and the static helper `WorkerSiteUrl.php`; in
  `Idno/Portability/`, the static helpers `SafeXml.php` and `SettingsRedactor.php`, the value objects
  `ImportedPost.php`, `ImportedComment.php` and `ImportResult.php`, the helper `ImportedImages.php`, the five
  interfaces, and `ImportedMedia.php` (wired by hand: it takes a closure that fetches the remote client), and in `Idno/Templating/` the value objects and static helpers
  `LocatedTemplate.php`, `OutputBuffers.php`, `ShellHeaders.php`, `TemplateName.php` and `TypeChain.php` and the classes wired by hand (`CascadeTwigLoader.php`,
  `SlotRegistry.php`, `TemplateCascade.php`, `TemplateRenderer.php`, `TwigTemplateEngine.php`), and in `Idno/Install/`
  everything but the services (`InstallPaths`, wired with the project directory, `WebInstaller`, `SetupToken` and the two
  listeners): `AdministratorDetails.php`, `CheckResult.php`, `ConfigFile.php`, `DatabaseChoice.php`, `DatabaseFinding.php`,
  `FirstAdministrator.php` (an interface, aliased to `Idno\Legacy\LegacyFirstAdministrator`), `InstallForm.php`,
  `InstallPage.php`, `ServerChecks.php` and `SiteAddress.php`.
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
  filesystem (NFS, SMB) or with several web servers on one file. The web installer and `idno:install` can set up an
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
    request: the catalog, the probe, then the legacy application's, 10 on the fixture site (Idno 2026 draws one lookup fewer than the look it replaced did, the Twig shell one fewer again: the structured data partial no longer looks up an icon of the page's object that it never used, and the Twig entity templates one fewer again: two lookups of an entity by its uuid where there were three), the same
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
- What the home page lists is one interface, `Idno\Http\Controller\Home\HomeStream` (a public alias of `Idno\Legacy\LegacyHomeStream`). `Idno\Http\Controller\Home\HomePages`
  (a public service on `HomeStream`, `PageRenderer`, and the logger and the event trigger, which it takes as service closures and fetches only when it logs, so the service is built in a kernel with no legacy application: `PageListenersWithoutLegacyTest`) is the home page: `home($page, root: true)` (which first offers the root to the event `homepage/replace`: see "Replacing the home page", under "Legacy pages and routes") and `content($page, $types)` return its `Response`, and
  `receiveWebmention()` takes a webmention of the site's root. `HomepageController` calls them, and so does the deprecated `Idno\Pages\Homepage` (and so a page that
  extends it): one implementation, which is also the feeds', the search's and the tags'. The stream holds what the legacy
  `Homepage::getContent()` asked the site, moved unchanged, so the database statements (and their order), the events and the template variables are what they
  were: `siteHasUsers()` (`User::get()`), `singleUserOwner()` (the one administrator of a `single_user` site, else null, with the warning logged when there is not
  exactly one), `isSingleUser()`, `firstAdministrator()` (`User::getOne(['admin' => true])`: what a webmention of the root is passed on to, which does not insist on
  exactly one), `types($friendly, $query, $requested)` (the friendly path's
  types, `all` for every one; without a path and without a query the configured home page types; without a path and with a query the request's `types` as given;
  empty is every registered class; the friendly names come back as `array_filter()` left them, keys kept, because the page prints them as `content`),
  `page($types, $query, $offset)` (published posts only, by the viewer's read groups: `Entity::countFromX()` then `getFromX()` with `items_per_page`; `items` is
  what the finder returned, false included), `creatableTypes()` (the logged-in member's content types, each with `editUrl` set; false for a visitor), `titles()`,
  `siteAddress()` (the configured `url` as written: where the search page sends a query), `lastModified($timestamp)` (the page's `Last-Modified`, from its first item's `updated`, asked for with `PageContext::addHeader()`) and `profilePageWebmention()`
  (what a webmention to the home page of a single-user site does: the profile page's own `webmentionContent()`). `$query` is `string|array|null` because a
  request can carry `q[]`, which the stores read as a query that matches nothing. `HomePages::serve()` (the one flow of the home page, the feed and the tag page) is the flow `Homepage::getContent()` had: on a site with no user
  every one of them answers the installation's "being set up" page (`InstallPage::pending()`: 503, `Retry-After: 300`, a Content-Security-Policy with a nonce of its own,
  and `no-store` asked with `PageContext::setCaching()`; nothing drawn in the shell), the single-user owner is set as the page's owner when it has none, then the types, the page, the
  creatable types and the titles are asked in that order, `Last-Modified` is the first item's, the body `pages/home` is drawn with `items`, `contentTypes`,
  `offset`, `count`, `subject` and `content`, and the shell with `title`, `description`, `content` and `body`. It reads `q`, `offset` and `types` through
  `PageContext::input()`. A new code path takes `HomeStream` by constructor injection; it is a legacy-backed service (it needs the legacy application) until the
  listing moves to `EntityRepository`. `Tests/Unit/Http/Controller/HomePagesTest` pins the flow on doubles; `Tests/Kernel/LegacyHomeStreamBehaviourTest` pins what the stream gives on a
  seeded site with private posts and a draft (each friendly path with and without a search; the pages of each viewer, visitor, member and administrator, at three offsets,
  without a search and with one that matches nothing (what a search matches is the database's); requested types with and without a search; drafts and private posts; titles; creatable types), and runs it on an empty site, a single-user
  site and a single-user site with no administrator.
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
    such a URL, and do not add a second SSRF check by hand around it. It is NOT a shared service: each
    fetch builds a new one over the shared site client (in the test container too), because the
    decorator keeps a DNS cache, and pins curl to the address it cached, for its whole life, which in a
    queue worker would be until the worker restarts. Fetch it at each use (`Webservice` and
    `ATProtoClient` do); do not keep it in a property.
  - The boot-configuration setting `http_allow_private` (a list, `http_allow_private[] = "192.168.1.0/24"`
    in `config.ini`, or one comma-separated string in `IDNO_HTTP_ALLOW_PRIVATE`; never read from the
    stored settings: `Idno\Core\Config` drops it) names the IP addresses and CIDR ranges (IPv4 or IPv6)
    the remote client may connect to although they are private, or is the single entry `*`, which
    makes `HttpClientFactory::createRemote()` return the site client itself: nothing is refused. It is
    the remedy for intranet, single-user, split-horizon-DNS and development installs that lose
    webmentions to their own posts, feeds, sharing and hub discovery under the refusal. It is built on
    `NoPrivateNetworkHttpClient`'s own allow-list argument (an address that is private is let through
    when it is in the list; a public one never needs it). An entry that is not an IP address, a CIDR
    range or `*` makes the factory throw a `\LogicException` naming the setting and the entry when
    the service is fetched (a mistyped list never silently allows or refuses); a prefix length of 0
    (`0.0.0.0/0`, `::/0`, each of which opens every private address of its family) is refused the same way,
    the message saying to use `*`, which is the one way to switch the refusal off. A proxy is recognised
    from `proxy_string` or, when that is empty, from the environment variables the HTTP client itself reads
    when it is given no `proxy` option (Symfony's `HttpClientTrait::getProxyUrl()`: the http proxy is
    `http_proxy`, else `HTTP_PROXY` on the CLI only, else `all_proxy`, else `ALL_PROXY`; the https proxy is
    `https_proxy`, else `HTTPS_PROXY`, else the http one; a variable that is set but empty is the proxy and
    hides the later ones; curl itself reads none, the client always sets `CURLOPT_PROXY`), both schemes,
    since the remote client fetches both (`HttpClientFactory::proxyAddressesFromEnvironment()` is pure, on
    an array; `createRemote()` takes the environment as its fourth argument, `$_SERVER` when null, so tests
    pass arrays; `no_proxy` needs no handling: an exempt host connects directly and reports its own
    address). Idno still passes a `proxy` option only when `proxy_string` is set, and `proxy_string` wins:
    the environment is then not consulted. The proxy's own addresses (its host if an IP address, else what
    it resolves to when the client is built; nothing when it does not resolve or cannot be read) are not
    refused as the address the client connected to:
    curl's connection is to the proxy, whose address the refusal's after-connect check (`primary_ip`)
    would otherwise block, so every remote request of a site behind a proxy would fail. That is done
    by `Idno\Http\Client\ProxyAddressMask`, a decorator under the refusal that hides a reported
    `primary_ip` equal to a proxy address, and NOT by the allow-list, which would also let a target at
    the proxy's address through (a proxy on `127.0.0.1` would admit `http://127.0.0.1/`). The TARGET's
    addresses are still checked before each request where the server can resolve them (a hostname the
    server cannot resolve is refused). Behind an HTTP proxy or a `socks5h` proxy the PROXY resolves the
    target's name for the real connection, so the server's own lookup is only a pre-check: the protection
    against a name that resolves differently for the proxy (DNS rebinding) does not apply there, and
    anything the proxy can reach, including services on the proxy host's own loopback, can be reached
    through a hostname; enforce egress rules at the proxy, and use `*` if the server cannot resolve
    external names at all. With a `socks5` proxy (names resolved by the server) or no proxy, the address
    checked is the address connected to. The unit tier never resolves a name: a test that makes a request
    to a hostname through the real private-network client gives it a `resolve` option. `createRemote($client, $config)` takes the boot configuration's values (as
    `create()` does; `remoteFromBootConfig()` is the container's factory); `options()` stays pure and
    ignores the setting.
  - Both send `User-Agent: Idno https://idno.co` and `Accept-Encoding: identity` (no compression: the
    client decodes a compressed body a chunk at a time, and a few kilobytes of gzip decode to megabytes in
    one chunk, which `BoundedBody` cannot bound: measured at 17 MB of PHP memory for a 40 MB body with the
    limit at 1 MB; naming an encoding also stops it decoding a body a server compresses anyway; pinned by
    `Tests/Characterisation/WebserviceCompressionTest` on `/_test/gzip`; the old client asked for none
    either), fail after `HttpClientFactory::IDLE_TIMEOUT` (10 s)
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
  - A plugin's test that needs a remote server to answer (the ActivityPub inbox fetching a sender's actor
    document for its key) installs mock clients with `Webservice::useClient()` and records what they were
    sent: `IdnoPlugins/ActivityPub/Tests/ServesTestActor` is the trait the inbox signature tests use. Tests
    that post to the rig itself from outside the site (a logged-in browser with a cookie file, a multipart
    upload) may use PHP's curl functions directly, as `InboxRequestTest` and `MicropubEndpointAuthTest` do.
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
    one error line is logged (`Webservice request failed: <reason>: <scheme>://<host><path>`: no query,
    no credentials, control characters replaced, each part at most 200 bytes, never a header or a body).
    `error` is empty when a response was received and kept whole; a response refused or cut for its size has its
    status AND a non-empty `error`. The log line holds a fixed reason or the exception's class, never its message
    (which can quote the address), and the URL as scheme, host and path (`[unparseable address]` if it has none).
    `$result['error']` is read only as a flag (empty or not) in the repository, by
    `IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint::fetchMedia()`, `file_get_contents()` and
    `safeFileGetContents()`; it is never logged or shown.
  - A request's URL is sent to the client with every byte outside ASCII percent-encoded after the authority (path,
    query, fragment: `wireUrl()`), because the client's own `parse_url()` would turn some of those bytes into an
    underscore. The scheme and the authority (user information, host, port) are left exactly as given, so an
    internationalised host (`bücher.example`) is turned into punycode by the client, never percent-encoded.
  - Redirects are followed BY THE ADAPTER, one request per hop, each sent with `max_redirects` 0, so
    that `header` holds the header block of every hop in order, `effective_url` is the last URL, and the
    remote client checks every hop's address. Five are followed; a sixth is the failed result with the
    sixth response's status and URL, a non-empty `error` and empty `header` and `content`. On 301, 302
    and 303 a POST becomes a GET with no body and no Content-Type of its own, and a PUT or DELETE keeps
    its method and the caller's Content-Type but sends no body; on 307 and 308 the same method, body and
    Content-Type are sent again. `Authorization`, `Cookie`, `Signature`, `X-Idno-Service-Signature` and
    `DPoP` headers are not sent to another origin.
    No Referer is sent on a redirect. `file_get_contents_ex()` is `get()`; after too many redirects it
    returns the failed result and `file_get_contents()` returns false (neither throws).
  - Size limit: `webservice_max_download` (default 10 MB, read from the legacy config at call time). A
    response that DECLARES a `Content-Length` over it is refused without reading the body (`response` is
    its status, `error` non-empty, `header` and `content` empty; a HEAD is never refused for its declared
    length, which describes a body it does not send, so a large file can be probed); a body with no declared
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
- `IdnoPlugins\StandardSiteSync\ATProtoClient` (the AT Protocol OAuth and XRPC client; tests in
  `IdnoPlugins/StandardSiteSync/Tests/ATProtoClientTest.php` on a `MockHttpClient`) uses the HTTP client
  directly, not `Webservice`, because it needs exact control of methods, headers (DPoP proofs, `Authorization:
  DPoP ...`), raw bodies and response headers (`DPoP-Nonce`). It uses `idno.http_client.remote` for every
  request, fetched from the container at each request: the URLs it requests are `https://<handle>/.well-known/atproto-did`
  (member input), `https://plc.directory/<did>` and `https://<did:web domain>/.well-known/did.json` (a DID from
  DNS or from a remote document), `<pds>/.well-known/oauth-protected-resource` and `<pds>/xrpc/<method>`
  (the PDS a DID document names), `<issuer>/.well-known/oauth-authorization-server` and the pushed
  authorization request and token endpoints (from the metadata a remote server returned). Tests give it a
  client with `ATProtoClient::useClient()` and a DNS TXT lookup with `useTxtResolver()`; the public methods
  and the constructor are unchanged. Every request has a 30-second total limit (shorter than the client's
  60), a GET follows `HttpClientFactory::MAX_REDIRECTS` redirects and a POST none (a redirected POST is its
  3xx response, as it was under curl), and a response is read through `BoundedBody` up to 1 MiB and cut
  there (a cut document does not parse: the call fails as it does for any unparseable one). A GET that fails
  is `null` to the callers that tolerate it (identity and discovery methods throw a `\RuntimeException`);
  a request that fails in `authenticatedRequest()` (`xrpcGet()`) throws `\RuntimeException('HTTP GET failed:
  <scheme>://<host><path>')`, so `documentExists()` is false on a transport failure (the curl version ended
  in a `\TypeError` that no catch in the plugin handled). A POST that fails throws `\RuntimeException('HTTP
  POST failed: ...')`. Exception messages, which the plugin's pages and `Main.php` log and show, never hold a
  response body: an error document's `error_description`, `message` or `error` (control characters replaced,
  at most 200 bytes), else `HTTP <status>`; URLs in them are reduced to scheme, host and path. The class logs
  nothing itself.
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
  in a unit test instead: the level filter is the legacy `Idno\Core\Logging`'s), or test it black-box over HTTP. Do not boot a second kernel with
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

- **Entity system**: All content inherits from `Idno\Common\Entity` (→ `BaseObject`). Building an entity (and every hydration builds one) runs
  `registerEventHooks()`, which registers the `object/getbyurl` lookup ONCE for each class on each event dispatcher (`Tests/Common/EntityListenersTest`):
  a listener for each object kept every entity a process loaded alive and was run for every address looked up (a queue worker, an export or an
  import grew by hundreds of kilobytes a post). A hook added to a constructor path must do the same.
  Entities are stored as JSON text, one row each, in `idno_<collection>`
  (MySQL, MariaDB, SQLite; see "Database"). Key
  methods: `save()`, `delete()`, `getByUUID()`, `getActivityStreamsObjectType()`,
  `getFormattedContent()`.
- **Plugin system**: Plugins extend `Idno\Common\Plugin`, register legacy routes via
  `registerPages()`, and content types via `registerContentTypes()`. They hook
  into events (`saved`, `updated`, `deleted`, `syndicate`, etc.) using the
  Symfony EventDispatcher (wrapped by `Idno\Core\EventDispatcher`).
- **Template system**: templates are Twig files (`.html.twig`, `.txt.twig`: all of core's and the bundled plugins' web and mail templates) or `.tpl.php` files (PHP: third-party templates, and the feed, JSON, Markdown, ActivityPub and KML types), found and drawn by
  `Idno\Templating` (one cascade, two engines) and called through `Idno\Core\DefaultTemplate` (`Idno::site()->template()`). See "Templates".
- **Async queue**: `Idno::site()->queue()` is `Idno\Legacy\MessengerEventQueue`. With
  `event_queue = 'AsynchronousQueue'` an event becomes a message on Symfony Messenger (`Idno\Queue\`,
  the table `idno_messages`) and the worker, `service-event-queue` (`Idno\Console\QueueWorkCommand`),
  runs it in its own process; otherwise it runs at once in the request. **Required for ActivityPub.**
  Code asks `queue()->isAsynchronous()`, never for a class. See "The queue of events".
- **Slugs in URLs**: `Entity::prepareSlug()` stores a slug percent-encoded (the title
  `Café notes` gives `caf%C3%A9-notes`), and `getURL()` prints it that way. A web server
  may hand the router the path as sent (percent-encoded, hex digits in either case) or
  decoded, so a route that takes a slug matches any one path segment without a dot
  (`([^/.]+)`: the `:slug` part of `Entity::getPermalinkRoute()`, and `/pages/` in
  StaticPages), and `Entity::getBySlug()` looks the value up as given and then as
  `rawurlencode(rawurldecode($slug))`. A value that is not UTF-8, as given or once decoded
  (`%FF`), is looked up as nothing (false), because no slug is anything but UTF-8 and a
  database may refuse to compare other bytes; `%00` decodes to a valid string and is
  just another value that finds nothing. Don't write a slug route as a list of ASCII
  characters, and only ever pass the slug to the database as a query value.
  `Tests/Pages/NonAsciiPermalinkTest.php` and `Tests/Characterisation/SlugRoutingTest` check this
  over HTTP.

### Security architecture

Symfony Security (`symfony/security-bundle`, `config/packages/security.php`) knows who is making each request; it
authenticates nobody and never touches the session. The legacy application authenticates (`Idno\Core\Session`, while the
kernel boots, before `kernel.request`) and the legacy session stays the source of truth: PHP's session is started by the legacy
boot, `$_SESSION['user_uuid']` and `$_SESSION['secure']` (which keeps an anonymous session non-empty for the database session
handler) are written as before, `framework.session` is OFF (legacy pages run after `kernel.response`, in `LegacyResponse`'s
callback, and often `exit`: Symfony's session listener would close the session and swallow the cookie) and Symfony never writes it.

- **Firewalls.** `upgrade` (`^/_upgrade/`, `security: false`: the legacy application is not booted there) and `main` (every
  other path): `stateless: true`, not lazy, ONE authenticator, `Idno\Security\CurrentUserAuthenticator`, an in-memory user
  provider that looks nothing up (the bundle requires one; the passport carries the user), `entry_point` and
  `access_denied_handler` both `Idno\Security\DeniedResponder`, no `access_control`. Nothing configured looks at a header, a cookie
  or a token; nothing is compiled from the configuration. The bundle brings the console commands `debug:firewall`,
  `debug:security:role-hierarchy` and `security:hash-password` (harmless: they describe or hash) and registers no session service,
  no session and no framework CSRF protection (the one CSRF token manager is Idno's own, below), no profiler hook and nothing that reads `kernel.secret` (remember me, login links are not enabled); no trim pass
  was needed, and `Tests/Kernel/SecurityTrimTest` pins all of it in the test and a freshly compiled prod container. The firewall
  runs for every request, legacy pages included (the catch-all route is not access-controlled): it only fills the token storage.
  The `upgrade` firewall is for the routes the gate's listener lets through while the gate is closed; with the gate OPEN a request to
  `/_upgrade/step` runs with the legacy application booted (and answers `current`). The firewall decides when the request reaches the
  controller, so Twig's `is_granted()` and `app.user` (the bundle's) describe that moment and must not be used in the templates a
  legacy page draws (they run later, and after a login or logoff in the same request they would be stale): use `is_admin()`,
  `is_logged_in()` and `current_user()`, which read the legacy session.
- **`CurrentUserSource`** (`Idno\Security`, an interface) says who is making the request; `Idno\Legacy\LegacyCurrentUserSource` reads
  `Idno::site()->session()->currentUser()` and builds a **`SessionUser`** (`UserInterface`: the uuid is the identifier, `ROLE_USER` for
  every member, `ROLE_ADMIN` only when `User::isAdmin()` is true, which is false for a stateless authentication; an optional
  fourth constructor argument and `isStateless()` carry the flag) from the entity the session already loaded: no database statement
  is added to a request. It answers null, and never builds the session, when this kernel did not boot the legacy application
  (`Kernel::legacyBooted()`: a `bootLegacy: false` kernel, or a closed schema gate), so a `bootLegacy: false` kernel handles a
  public controller with the firewall in place. With the gate closed `UpgradeGateListener` (priority 300) answers before the firewall.
- **Stateless authentication.** "API request" is a property of the REQUEST, held by the `Session` object (one process is one request
  on the web): `Session::isAPIRequest()` is true when a stateless credential (a signed API request, a bearer token, a plugin's
  hook) authenticated this request. `$_SESSION['is_api_request']` is never written or read. A stateless authentication holds its user
  IN MEMORY and writes nothing to the session. STRUCTURALLY, on an API request `currentUser()` (and so `isLoggedIn()`, `currentUserUUID()`
  and the `CurrentUserSource`) is the stateless user or NOBODY, never the browser session's user: the request skips the form token, so
  it must never be the cookie's user, whatever happens to the stateless user (`authenticateStatelessly()` of a member who cannot be
  logged on, such as one whose address is blocked, leaves the request anonymous and still stateless, and so does `logUserOff()`) and
  whatever cookie it carries. A `user/auth/request` answer that is not a `User` authenticates nobody and the session's user stays;
  the Micropub endpoint also answers 401 unless the request is an API request AND a user is logged on. A plugin that logs a user on
  (or refreshes the session's user) and only then calls `setIsAPIRequest(true)` is put right: the user moves to the stateless slot and
  `$_SESSION['user_uuid']` and the session's password fingerprint are put back to what the browser had (recorded at the start and at the end of `tryAuthUser()`); the
  session id `logUserOn()` regenerated stays regenerated. A signed request that carries another member's browser cookie acts as the signed member for that request and leaves the
  browser's session intact (`SessionDataAcrossLoginTest`); `SessionUser::getRoles()` also withholds `ROLE_ADMIN` from a stateless member. `refreshSessionUser()` (Autosave, the `saved` listener, token issue) refreshes the user in memory on a
  stateless request and never writes `$_SESSION['user_uuid']`; `logUserOff()` on one clears the memory and leaves the session and
  cookie alone; `logUserOn()` (browser login, registration, the worker) is as before for an ordinary request and, on a stateless
  one, holds the user in memory and does not regenerate the session id. `Session::authenticateStatelessly(User $user)` (public: holds
  the user, sets the flag, triggers `user/auth/success` with `is api` true) is how `IdnoPlugins\IndiePub\Main::authenticate()`
  authenticates a bearer token, and how a plugin authenticates by a credential of its own; a `user/auth/request` listener may also
  simply return the `User` (a returned user nobody logged on is authenticated statelessly). `Session::setIsAPIRequest()` is
  deprecated: it sets the request flag (never the session) and logs one deprecation line a process. `tryAuthUser()` keeps the
  `user/auth/request` event and the `X-IDNO-*` / `X-KNOWN-*` signature check, which is now `Idno\Security\ApiSignatureVerifier`
  (pure: the base64 HMAC-SHA256 of the request address with the member's API key, `hash_equals()`, the header trimmed, an empty key
  never verifies); a request authenticated statelessly never also authenticates by the session. When a stateless request ends the
  session it STARTED (it carried no cookie, or one for a session that does not exist: `Session::init()` records it after
  `session_start()`) is destroyed (an API client leaves no session row behind each request); a session the request carried (a member's,
  or a visitor's with a message waiting) is left alone. `Idno\Security\StatelessAuthentication` (`ATTRIBUTE`, `isStateless()`,
  `mark()`) is set on the Symfony request by the authenticator, so a controller can ask. Bearer tokens stay LOCAL to the Micropub
  endpoint: no firewall, matcher or listener looks at `Authorization` or `access_token`. Pinned by
  `Tests/Characterisation/StatelessAuthenticationTest`, `TokenAgreementTest` and `Tests/Kernel/SessionStatelessTest`.
- **Denied requests.** On a site page every refusal is the page's own 403 (`PageExceptionListener`, before the firewall: "Legacy pages and routes").
  For every other controller, `Idno\Security\DeniedResponder` (access-denied handler and entry point): the legacy 403 page with status 403
  (`Idno\Legacy\LegacyForbiddenPage`, behind the `Idno\Security\ForbiddenPage` interface: `pages/403` in the page shell, drawn on a
  page that does nothing, as `Page::deniedContent()` does but returned, not echoed) for a caller that wants HTML, `{"error":"forbidden"}`
  for one that wants JSON (`?_t=json`/`jsonp`, or an `Accept` that names JSON and no HTML), for a visitor and a member alike (never a
  login redirect), and a plain-text 403 when the legacy application is not booted, the page fails to draw or the template type is
  neither a web page nor JSON (`?_t=rss`: no feed shell with the wrong content type; `?_t[]=x` is read from `query->all()` and is
  no type). The page is drawn with `DefaultTemplate::drawPageWithoutSideEffects()`: the session's messages are neither shown nor
  used up and no header (the cross-origin one) is sent. `Cache-Control: no-store` for every controller that is not a site page; on a site page the refusal's `no-store` is not the page's
  ask for caching (`DeniedResponder::ATTRIBUTE` marks the request) and the response goes out with the session's lines, as a legacy page's 403 did (see "The headers of a site page"). Pinned by `Tests/Kernel/ControllerAuthorizationTest` (`/_test/admin-only`, `/_test/member-only`, `/_test/whoami`:
  `Tests/Fixtures/WhoAmIController`).
- **The queue worker.** It handles many messages in one process and has no request: `LegacyUserContext::runAs()` and
  `ensureNobodyLoggedOn()` (and so `WorkerHygiene`) call `Session::resetStatelessAuthentication()` before and after each message, so
  neither a session user nor a stateless user (a listener may authenticate one) is left behind or shadows the next message's user.

### Database

- **MongoDB** is no longer a database Idno runs on (the backend, `Idno\Data\Mongo`, and its GridFS file
  system were removed). A site whose `database` is `mongo`, `mongodb` or `Idno\Data\Mongo` is held closed by the schema
  gate (`SchemaStatus::MongoRetired`) with a message that names `php bin/console idno:import:mongo`,
  which moves it to MySQL, MariaDB or SQLite (see "Importing a MongoDB site"). The installers refuse
  `mongo` and `mongodb` (`Idno\Install\DatabaseChoice::type()` throws a `ConfigurationException` saying MongoDB
  is no longer supported and the choices are MySQL or SQLite); any other unknown value still means
  MySQL. A `database` value naming a backend class a plugin supplies (`other`) boots as before.
- **MySQL 8.0 and MariaDB 10.6** (or later) — store each document as JSON text in the tables of the
  schema migrations (below), through `Idno\Storage\JsonRecordStore` and `SettingsRecordStore` on the
  DBAL connection; `db()` is `Idno\Legacy\RepositoryDataConcierge` over them (see the `db()` bullet
  under "Kernel and services"). The hand-written SQL backend that preceded it has been removed;
  `docs/install/upgrade.md` tells plugin authors what replaces it and what behaves differently.
- **SQLite** — the same stores with `SqliteDialect`; `database = sqlite` (with `dbname` the file's
  path) gives `db()` the same adapter. A supported production database for a small site on one server
  with a local disk (the default test rig runs on it; the installers can set up an SQLite site).
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
  pending migration for the upgrade to record once the declared indexes exist. The third migration,
  `Version20261121000000`, creates `idno_messages`, the queue's table (see "The queue of events"), and is
  now that newest migration. The one exception is `idno_migrations`,
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
`Idno\Upgrade\SchemaGate` first (on an installed site: see "Installation"), in a kernel that would boot the
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
  fields exist. When `Version20261115000001` was the newest migration, that was it: a migration with no
  SQL that stands for "the declared indexed fields have been reconciled". Now that later migrations exist
  (the queue's table, `Version20261121000000`, is the newest), the held-back one is whichever migration is
  newest, and `Version20261115000001` is recorded with the baseline, before the indexes: what protects the
  gate is unchanged (the schema is not current, and the gate stays closed, until the newest migration is
  recorded, and that happens only after a reconcile pass found nothing left to create). A database that
  was current before the queue's table existed has every older migration and its indexes, so it is
  `PendingMigrations` and one step (or `idno:upgrade`) creates the table. A new core declaration ships
  with a new, empty migration, which is then the newest. The runner, whenever migrations are
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
- After a web install the browser is sent to `/`: that request gets the upgrade page
  (`Empty`), the steps set the database up, and the reload continues to the setup step (`/_setup/`, see "Installation").
- Test hooks of the test environment (`config/services_test.php`, inert unless their environment
  variable is set): `IDNO_TEST_UPGRADE_STEP_SECONDS` (above) and `Tests\Fixtures\FailFirstConnection`
  (a `doctrine.middleware`: with `IDNO_TEST_FAIL_FIRST_CONNECTION=1` the first connection attempt
  fails, as a server refusing one connection would), `IDNO_TEST_MAIL_DELIVER` and `IDNO_TEST_MAIL_CAFILE`
  (deliver mail to a scripted SMTP server instead of recording it: see "Mail").
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
  `Idno\Core\Admin::registerPages()`; templates `templates/default/admin/legacytables.html.twig`,
  `templates/classic/admin/legacytables.tpl.php` and the shared
  `admin/legacytables/body`, which escapes every value from the database): GET (admins) changes
  nothing and lists the tables with their approximate counts (with the 1.x `entities` tables back
  beside the upgrade: no delete form, and the gate's message instead; the gate is closed then, so the
  page is reached only in a process whose legacy application booted anyway), the rejects (100 a page, `?offset=`, the total and
  where the contents are), the reappeared tables with what they are, or an empty state. POST: after
  `Page::post()`'s token check, `adminGatekeeper()`, then only `confirm` exactly `delete` with a
  complete upgrade calls `drop()`; every outcome is a session message (what was deleted, or why
  nothing was) and a redirect to the page. The admin menu entry (both menus:
  `templates/default/admin/menu.html.twig`, `templates/classic/admin/menu.tpl.php`) is
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
  in the Idno 2026 look: the entry, the list, escaping, the delete) and
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
(`settings`, `sessions`, `migrations`, `messages`, `upgrade_progress`, `upgrade_rejects`); any other name is no
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

#### The queue of events

The events a site queues to run later (syndication, federation, webhooks, ...) are messages on Symfony
Messenger's Doctrine transport. `symfony/messenger` and `symfony/doctrine-messenger` (`^8.0`) are
required. The classes `Idno\Core\AsynchronousQueue` and `Idno\Core\SynchronousQueue`, the console plugin
`ConsolePlugins/EventQueueService` and the pages `/service/queue/*` no longer exist.

- `Idno\Legacy\MessengerEventQueue` (final, an `Idno\Core\EventQueue`, built by
  `LegacyServiceFactory::queue()` whatever `event_queue` says, from closures that read the legacy
  configuration, session, event dispatcher and logger when needed: the queue is built before they exist,
  and the bus is fetched when the first event is sent) is `Idno::site()->queue()`. `isAsynchronous()` reads
  the `event_queue` setting at each call: `AsynchronousQueue`, `Idno\Core\AsynchronousQueue` (any case,
  leading backslash optional, as `componentFactory()` resolved it) is true; unset, empty, `SynchronousQueue`
  and any other value false, and any other non-empty value logs ONE warning. `enqueue($queueName,
  $eventName, array $eventData, $runAsUser = null)`: asynchronous, it dispatches a `QueuedEvent` (queue name
  `default` when empty; run as the named user, else the session's user, else nobody) and returns the
  transport's message id as a string, or false (logged: the event's name and the exception's class, never its
  data) when it cannot be sent; otherwise it triggers the event at once, catching and logging an exception
  as `SynchronousQueue` did, and returns an opaque 32-hex id whose result `isComplete()`/`getResult()` keep
  for the request (false and null for any other id: nothing reports on an asynchronous event). Bundled code
  asks `queue()->isAsynchronous()` (ActivityPub, StandardSiteSync's backfill); a plugin must not test a class.
  The legacy queue name (`default`, the first argument, kept in `QueuedEvent::$queue`) is NOT the transport's
  `queue_name` (`idno`, `failed`): every legacy queue name shares the one `idno` transport; nothing bundled
  enqueues to a name but `default`.
- `Idno\Queue\LegacyQueueDrain` moves the previous queue's records (entities of
  `Idno\Entities\AsynchronousQueuedEvent`, which still exists for this) that are not complete into the queue,
  oldest first, when the worker starts: per record ONE transaction in which the record is deleted (the delete
  is the claim: a worker that finds it already gone sends nothing, so two workers starting together move each
  record once; it is the one SQL statement of its own, on the table `JsonRecordStore::tableName()` gives,
  because `RecordStore::delete()` does not say whether it removed a row) and a `QueuedEvent` is sent; a failure
  of either rolls both back. A record whose data does not unserialise to an array, holds an object of a class
  that no longer exists (`__PHP_Incomplete_Class`, found by walking the value), or whose send fails, stays, is
  counted in `left` and logged once with its id and event name (never its data). `drain(?\Closure
  $shouldStop)` asks before each record and ends there (whole records only). `removeCompleted($limit = 500)`
  deletes complete records of the class (the old garbage collector is gone, nothing else reads them), the
  command calling it once at each start (a failure of that housekeeping is one warning, never an obstacle to
  consuming). A claim that fails because the database is busy or locked (`ResilientTransport::isInterruption()`,
  the one classification the queue uses) means another worker is moving the record: not counted as left, not a
  warning, the drain goes on. It reads documents through the `RecordStore`, never entities.
- `Idno\Console\QueueWorkCommand` is `service-event-queue [--queue=default] [--interval=N] [--once]
  [--time-limit=N] [--requeue-abandoned]` (a Symfony command; the legacy console plugin of that name is gone, so it is listed
  while the schema gate is closed too, and refuses there with exit 1 after the console's closed-gate line). It
  exits 1 with `The event queue is not set to AsynchronousQueue. Set event_queue = 'AsynchronousQueue' in
  config.ini.` unless the queue is asynchronous; prints `Processing pending events from queue '<q>' (single
  run)...` (or the starting lines); says how many abandoned messages the `idno` queue has when more than 0
  (and how to requeue), or with `--requeue-abandoned` requeues those older than an hour and prints how many;
  drains the legacy records (one line only when something moved or was left); then runs a Messenger `Worker`
  on the container's `idno` transport (so `ResilientTransport` applies) and the default bus, printing one
  line for each event taken (`[date] Dispatching event <message id>: <event>`) and one when it is done
  (`Event <id> completed.`) or failed (`Error dispatching event <id>: <message> (kept in the failure
  transport ...)`). `--once` stops the worker when it is idle (everything waiting now, exit 0); otherwise it
  runs until SIGTERM, SIGINT or SIGQUIT, sleeping `--interval` seconds when idle, and stops itself with exit 0
  at `QueueWorkCommand::memoryLimitBytes()` (80% of `memory_limit` when set, at most 256 MiB) for a
  supervisor to restart (the usage it compares is `memory_get_usage(true)`, or the constructor's `$memoryUsage` closure: a test never lets it read its own process, which in a whole suite uses over 256 MiB and made the time-limit test stop at once). SIGTERM, SIGINT and SIGQUIT set a stop flag at any time (also before the worker
  exists): the drain ends between records, the command does not start consuming, exit 0. `--once` stops on
  a GENUINE empty read (`StopWhenQueueIsEmpty`: `ResilientTransport::lastGetWasInterrupted()` says a read
  returned nothing only because it was ridden out), waiting 0.5 s between reads, and after 30 s of
  interrupted reads in a row exits 1 with a line on stderr. A failing event never makes the exit code non-zero. `--queue` is
  accepted and one worker serves every queue name (one transport cannot be filtered by the name in the
  message); a worker given another name says so. It is the ONLY worker: `Idno\Kernel\MessengerTrimPass`
  removes `messenger:consume`, which lacks the checks below. After the queue check and before anything is
  drained or consumed it refuses (exit 1, one message on stderr) unless the BOOT configuration has an
  explicit absolute `url` (`Idno\Queue\WorkerSiteUrl::problem()`, a pure check: `http` or `https` and a
  host; the message names `url = "https://example.com/"` in `configuration/config.ini` and
  `IDNO_URL=https://example.com/`); the worker never guesses the address, because `url` is never stored
  in the database and an installer-made site has none in `config.ini`, so without a request the address
  would be built from `IDNO_DOMAIN` with an http scheme. A site without the asynchronous queue never
  needs `url` (the command refuses it for the queue first). The daemon stops itself, between messages,
  after `--time-limit` seconds (default 3600; 0 none; `--once` ignores it) so that a supervisor's restart
  re-reads settings and plugins, and on `messenger:stop-workers` (FrameworkBundle's
  `StopWorkerOnRestartSignalListener`, a subscriber on the event dispatcher the command's worker runs on, so
  `--once` runs stop too; pinned by a subprocess test); it needs a supervisor (systemd `Restart=always`, or cron with `--once`).
- Webmentions are sent through the queue, for every bundled content type, by ONE helper:
  `Idno\Core\Webmention::queueMentions(string $pageURL, string $text)` enqueues the event `webmention/sendall` (data
  `source` and `text`, queue `default`, run as the user who saved) and `Webmention::pingMentions()` sends them when the
  event runs (the listener is in `Webmention`'s `registerEventHooks()`). Status, Text (articles), Photo, Media, Like
  (bookmarks), Event, RSVP and Checkin call it from their save (and from `deleteData()`, which tells the pages a post
  linked to that it is gone); no bundled code calls `pingMentions()` directly, and a plugin that announces a post
  should call the helper, not `pingMentions()` (which still works, and sends at once, in the request). So with the
  asynchronous queue a save makes no outbound request however many links its text holds, and without it the queue
  runs the event at once in the request, as a direct call did. The caller builds `text` in the request (the
  template's `parseURLs()` of the post's title and description: the worker has no request or template state) and the
  helper checks the request-only facts HERE, because the worker cannot: nothing is queued on a site that is not public,
  while an import runs (`Idno::importInProgress()`), when the request carries `nomention` or the constant
  `IDNO_NOMENTION` is defined; `pingMentions()` asks about the site and the import again when the event runs. When each
  type calls it is unchanged: every save of a PUBLIC post that is published (not a draft; Media and RSVP have no draft)
  for all types but Checkin, which sends only for a new post; a delete (Status and Reply, Like, Text, Checkin, Event
  and RSVP; Photo and Media send nothing on delete) of a post that `Entity::announcesMentions()` says is announced: PUBLIC
  and published (an empty status counts as published). A draft's delete sends nothing: its address was never public.
  Nothing records that a post was once published, so one turned back into a draft sends nothing either when deleted
  (its address already answers as missing to everyone but its owner). `pingMentions()` sends to each link on its own: a link that throws is logged
  (`Webmention to <address> failed: <exception class>`) and the next is tried, so one bad link never fails the event
  (a failed event is not run again by itself, so links already sent are not sent twice; `Webservice::safeSend()`
  already turns an unreachable or slow host into a failed result, never an exception). Pinned by
  `Tests/Queue/ContentTypeWebmentionsQueuedTest` (every type on an asynchronous site: nothing sent by the save, one
  event queued with the post's address, the worker sends once as the member; edits, private posts and drafts, `nomention`,
  a delete, a draft's delete for each type that announces one, a slow link, an unreachable link and the failure transport), `ContentTypeWebmentionsSynchronousTest` (the
  same types sent during the request) and `Tests/Kernel/WebmentionQueueTest` (the helper's data and checks, a link
  that throws).
- Events run in the worker's process (the console), so their listeners must be registered there: the
  link-preview listener is registered by the console boot too (it was web-only). A listener must not read the
  request. `Page::isSSL()` in a console process (no `REQUEST_METHOD`) is true exactly when the explicit boot
  `url` is https (the worker refuses to start without one, above), so an activity's `keyId` built in the
  worker is https for an https `url` and http for an http one, whatever a web server would present, also with
  `IDNO_DOMAIN` set (pinned in a subprocess by `Tests/Queue/QueueWorkerSiteUrlTest`): a site served over TLS
  must have an https `url` configured, or what its worker signs and sends names http.

- `Idno\Queue\QueuedEvent`: the message, a value object: `queue` (the queue it was sent to), `event`
  (the event's name), `data` (an array) and `runAs` (the uuid of the user it runs as, null for nobody).
  Messenger's default PHP serializer is kept ON PURPOSE: `data` may hold entity objects, as the data of
  an event always could (`serialize()`); `Tests/Queue/TransportTest::testAMessageKeepsItsDataIncludingAnEntity`
  pins it with a real entity. Do not switch it to JSON.
- `Idno\Queue\QueuedEventHandler` (`#[AsMessageHandler]`): runs the event as `runAs` (a user that no
  longer exists, or whom the session will not log on, is a `\RuntimeException` and nothing is triggered;
  `LegacyUserContext` reads the user uncached, never from the entity cache, so a user deleted after an earlier
  event cached them does not pass), returns what the listeners returned,
  and LETS EVERY THROWABLE OUT (`\Error` too) so that Messenger moves the message to the failure
  transport; nobody is left logged on either way. It depends on two interfaces of `Idno\Queue\`, not on
  the legacy application: `EventTrigger` (trigger a named event with its data) and `UserContext`
  (`runAs($uuid, $work)`, `ensureNobodyLoggedOn()`); `Idno\Legacy\LegacyEventTrigger` and
  `Idno\Legacy\LegacyUserContext` implement them over the legacy event dispatcher and session
  (aliased in `config/services.php`; they need the legacy application, so they are built only when a
  message is dispatched or consumed). The unit test uses doubles (`Tests/Unit/Queue/`).
- `Idno\Queue\WorkerHygiene` (a bus middleware, named in `config/packages/messenger.php`, and a
  subscriber of the worker's `WorkerRunningEvent`): for RECEIVED messages only (a `ReceivedStamp`; a
  message dispatched by a web request is untouched) it checks the site's DBAL connection before the
  message is handled and again after it (the handler may outlive the server's idle timeout, and the
  transport acknowledges on that connection) and closes a lost one so the next query opens a new one;
  after it, also when it failed, it makes sure nobody is logged on (each cleanup step on its own: a
  failure of one is logged and never masks the handler's own exception), and it empties the entity cache
  (`Idno\Queue\EntityCache`, a third small interface with the adapter `Idno\Legacy\LegacyEntityCache` over
  `Entity::clearEntityCache()`: `Entity::$entity_cache` is static and unbounded, and the old worker ran each
  event in a fresh request), so one event never sees what an earlier one loaded and memory does not grow with
  the number of events. The same connection check runs after each message and whenever the worker is idle.
- `Idno\Queue\ResilientTransport` decorates the `idno` transport (`config/services.php`; it takes the
  transport's tags, so the worker and anything else that receives from or sends to it
  get it). `get()` returns no message, after logging ONE warning line, when the transport fails because
  the database is locked or busy (DBAL's `RetryableException`: lock wait timeout, deadlock; on SQLite
  this is what a read gets when another process committed between the transport's SELECT and UPDATE,
  any commit, not only on `idno_messages`) or the connection was lost (`ConnectionLost`: it also closes
  the connection so that the next query opens a new one), so the worker sleeps and reads again.
  Any other failure propagates; `ack()` and `reject()` failures propagate too (a message that could not
  be acknowledged must be visible); sending is passed through.
- A worker killed while it handles an event leaves that event ABANDONED, and it is never run again
  unless an operator requeues it. The Doctrine transport gives any message taken longer ago than
  `redeliver_timeout` (default an hour) to the next worker; both transports set it to
  `AbandonedMessages::REDELIVER_TIMEOUT_SECONDS` (ten years), because an event may already have posted
  to another server and running it twice would deliver it twice. `Idno\Queue\AbandonedMessages` (on the
  site's connection, table `idno_messages`, every value bound) lists them for an operator:
  `count($queue = AbandonedMessages::QUEUE, $olderThanSeconds = 3600)`, `ids(...)` (at most 100) and
  `requeue(...)` (sets `delivered_at` to NULL for exactly those rows, in one statement, so the next worker
  runs them). `$queue` is the TRANSPORT's queue name (`idno`, the default, or `failed`), not the legacy queue
  name of an event. An age below `AbandonedMessages::MINIMUM_AGE_SECONDS` (60) is an
  `\InvalidArgumentException` from every method: a live worker holds its message that long (and an event may
  take minutes), so 0 would requeue a message being handled.
- `config/packages/messenger.php`: transports `idno` and `failed`, both `doctrine://default` (the site's
  own DBAL connection: one database, no second connection), table `idno_messages`, queue names `idno`
  and `failed`, `auto_setup` OFF; BOTH have `max_retries` 0 (a message is never retried by itself: what a
  queued event sends to other servers must not be sent twice; FrameworkBundle gives `failed` three retries
  unless told, so `messenger:failed:retry` of an event that fails again would run it up to four times);
  `failure_transport: failed`; `QueuedEvent` is routed to `idno`. A failed message stays in `failed` until
  someone looks at it (`messenger:failed:show|retry|remove`); an event runs again only when an operator
  retries it, once per retry, and retrying one that had partly succeeded sends again to whoever already
  received it. Messenger removes a message that fails without a retry and `failed` has no failure transport
  of its own, so `Idno\Queue\KeepFailedMessage` (a subscriber on the failure event, receiver `failed`) puts a
  retried event that fails again back in `failed` with the error of the retry, paused for a minute
  (`available_at`: Messenger lists and finds only available messages, so `messenger:failed:retry` does not find
  it again at once and loop); it reappears in `messenger:failed:show` a minute later. The new copy and the
  delete of the received copy's row are ONE transaction on the site's connection, so a crash between them leaves
  neither two copies nor none (the worker's later `reject()` deletes nothing, which the transport tolerates: it
  is `delete ... where id = ?` and returns whether a row went); if the copy cannot be written the exception
  propagates and the original stays. The copy has a NEW id. Pinned by `MessengerConfigurationTest`,
  `KeepFailedMessageTest` and `FailedRetryGuardTest`. The error stored with a failed message (`messenger:failed:show -vv`) can
  include the arguments of the failing call unless PHP's `zend.exception_ignore_args` is on (the production
  php.ini default): that output is sensitive.
- The table: `Idno\Storage\Migrations\Version20261121000000` creates `idno_messages` as literal SQL, the
  columns, types and index of the installed transport's own schema
  (`Connection::configureSchema()`): `id` auto-increment (`BIGINT`; `INTEGER PRIMARY KEY AUTOINCREMENT` on
  SQLite), `body` and `headers` text, `queue_name` `VARCHAR(190)`, `created_at`, `available_at` and
  `delivered_at` `DATETIME` (whole seconds, UTC, written and compared by the transport), and the index
  `(queue_name, available_at, delivered_at, id)`. On MySQL and MariaDB it is InnoDB and utf8mb4
  (`utf8mb4_bin` for the two text columns, the schema's `utf8mb4_unicode_520_ci` for `queue_name`); on
  SQLite `queue_name` is `COLLATE NOCASE`. Pinned against the transport's own declaration by
  `SchemaMigratorTest` and `SchemaMigratorMySqlTest`. It is the newest migration, so the upgrade holds it
  back until the declared indexes exist (see "The indexed-fields marker"); a database upgraded earlier
  needs one more upgrade step (the upgrade page or `idno:upgrade`) to get the table. `messages` is in
  `JsonRecordStore::SYSTEM_TABLES`: the table is never a collection.
- At run time the transport never issues DDL (auto_setup off: a missing table is an error, pinned by
  `testWithoutTheTableTheTransportFailsInsteadOfCreatingIt`). It sends with one transaction around an
  insert; it reads in a transaction with `SELECT ... FOR UPDATE SKIP LOCKED` on MySQL and MariaDB (the
  clause is left off on SQLite, which has none), then marks the row delivered; it acknowledges by deleting
  the row. `LISTEN/NOTIFY` (`pg_notify`) is PostgreSQL only and never used here. A message handled but not
  acknowledged (the worker was killed) is not delivered again: it is abandoned (above). `WorkerHygiene` checks
  the connection after the handler so that the acknowledgement is made on a live one.
- At most once, with several workers: pinned by `TransportTest::testTwoConsumersNeverHandleOneMessageTwice`
  (two consumer subprocesses, `Tests/Queue/consumer.php`, on a SQLite file and on MySQL/MariaDB: each of
  100 ids is handled exactly once, both consumers handled some and both exit 0). On SQLite the read runs
  in a deferred transaction, so a read that finds another process's commit in between fails at once
  ("database is locked", never waited for); the message is untouched (rolled back before it is marked
  delivered), and `ResilientTransport` makes the worker read again, so no worker dies of it.
- Console: FrameworkBundle registers `messenger:stop-workers`, `messenger:failed:show|retry|remove`,
  `messenger:stats` and `debug:messenger`; they are wanted and stay. `messenger:setup-transports` would
  create the tables outside the migrations and `messenger:consume` is a second, unchecked way to run a
  worker: `Idno\Kernel\MessengerTrimPass` (added in `Kernel::build()`, as `DoctrineTrimPass` is) removes both.
  `messenger:failed:retry` runs events, whose listeners are legacy code: `Idno\Console\Application` refuses it
  (exit 1, before a message is taken) while the schema gate is closed
  (`Application::NEED_THE_LEGACY_APPLICATION`), and also (same hook, `Application::failedRetryRefusal()`)
  without an explicit absolute boot `url` (`WorkerSiteUrl::problem()`, with the worker's message: a shell
  without it would sign what it sends with a guessed http url, and a remote server's refusal still counts as
  handled, deleting the message) and without message ids (it loops over the failure transport until nothing
  is available, and takes back an event it put back after the pause); the retry command's own hint to run
  `messenger:consume failed` does not apply (that command does not exist here). `messenger:stop-workers` works (the
  framework's cache pool `cache.messenger.restart_workers_signal` is on the kernel's cache directory:
  workers and the command must share `IDNO_CACHE_DIR`). Pinned by `Tests/Kernel/MessengerConfigurationTest`
  (test and prod containers).
- Tests: `Tests/Unit/Queue/QueuedEventHandlerTest` (doubles), `Tests/Queue/TransportTest` and
  `WorkerHygieneTest` (a SQLite file, and a private MySQL or MariaDB database on the rig's server; the
  tables come from the migrations only; `MessengerRig` builds the transport and bus as the config does),
  `Tests/Kernel/MessengerConfigurationTest` and `QueueAdaptersTest` (the container and the legacy
  adapters; the test container exposes the private transports and bus through `idno.test.*` aliases in
  `config/services_test.php`), `Tests/Kernel/QueueShimTest` (the adapter and the deleted classes),
  `Tests/Queue/LegacyQueueDrainTest` (a SQLite file and a private MySQL or MariaDB database, two drains in
  subprocesses: `Tests/Queue/drain.php`), `AbandonedMessagesTest`, `QueueWorkCommandTest` and the
  black-box `QueuedDelivery*Test` classes.

#### Mail

Mail is sent by Symfony Mailer (`symfony/mailer`, `symfony/mime`); SwiftMailer is gone. FrameworkBundle's own
mailer is switched off in `config/packages/framework.php` (it needs a configured DSN), so there is exactly one.

- The mail settings (`smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_secure`, plus
  `from_email` and `title` for the sender) are STORED settings, which an admin edits, not boot
  configuration. `Idno\Mail\MailerFactory` reads them through `Idno\Config\SiteSettings` (so `Idno\Mail`
  depends on neither `Idno\Legacy` nor `Idno::site()`) at each `create()`, and builds a `Mailer` over the
  transport the injected `TransportFactoryInterface` makes from the DSN
  (`idno.mail.transport_factory`, `SmtpTransportFactory`, below, in `config/services.php`). The container service
  `Symfony\Component\Mailer\MailerInterface` is public and NOT shared: it is built at each fetch from the settings as THIS process last
  loaded them, so a change reaches a long-running queue worker only when the worker next starts (it stops
  itself after an hour; the documented rule of the queue: a changed setting reaches the worker when it restarts). Like the storage services it needs the legacy
  application (the settings are in the database), so it must not be fetched while the kernel boots.
  Nothing from the settings is compiled into the container. `Idno/Mail/` is in
  `CodeFingerprint::SERVICE_DIRECTORIES`; `MailTransportDsn.php`, `InvalidMailSetting.php`, `CramMd5Authenticator.php` and
  `SmtpTransportFactory.php` (wired by hand) are in the `$excludes`.
- `Idno\Mail\MailTransportDsn::fromSettings(array)` is pure and keeps what the settings did under
  SwiftMailer (read `git show 9c7f3f8c2:Idno/Core/Email.php`; the proof is `Tests/Mail/SwiftMailerParityTest`,
  below): the DSN ALWAYS carries a port, `smtp_port` when it is a positive integer (also as numeric text),
  else 25, WHATEVER the security (`ssl` with no port connected to port 25, encrypted from the start, so a
  site that set it keeps working; the docs say to set the port, usually 465; Symfony's own default for a DSN
  with no port is 465 with implicit TLS: never rely on it); an empty host is `localhost`, and the port and
  security still apply to it, but a user name and password do NOT (SwiftMailer ignored them without a host);
  `smtp_secure`, compared exactly, `ssl` is `smtps://` (implicit TLS), `tls` adds `?require_tls=true`
  (STARTTLS, mandatory), anything else is `?auto_tls=false` (sent in the clear, no STARTTLS even if the
  server offers it: the old code did that); a host written `ssl://host` OR `tls://host` is that host with
  implicit TLS (PHP's `tls://` stream scheme is TLS from the first byte, so that is what SwiftMailer did),
  unless `smtp_secure` is `ssl` or `tls` itself, which decides (SwiftMailer built an address that could not
  connect in every such combination: it never delivered), any other scheme is refused; the user name
  and password are percent-encoded; a user name with no password is used as it is (SwiftMailer logged in
  with an empty password), a password only with a user name. A host that is not a host name or an IP
  address or a port that is not 1 to 65535 throws `Idno\Mail\InvalidMailSetting` (an
  `\InvalidArgumentException`; `setting()` and the message name the setting, never its value).
- `Idno\Mail\SmtpTransportFactory` (the container's `idno.mail.transport_factory`, public) builds the SMTP
  transport and does exactly what the DSN says: Symfony's `EsmtpTransportFactory` would choose implicit TLS
  for port 465 whatever the DSN asks for (so `tls` on 465 would change from STARTTLS to implicit TLS, as
  SwiftMailer's `tls` was STARTTLS on a plain connection) and try STARTTLS on every server that offers it.
  It bounds the connection and every read at `SmtpTransportFactory::TIMEOUT_SECONDS` (30, SwiftMailer's own
  default; Symfony's is PHP's `default_socket_timeout`, 60) through the stream's `setTimeout()`, and the
  constructor takes another bound for a test of a server that hangs (production uses the default). It
  replaces Symfony's CRAM-MD5 authenticator with `Idno\Mail\CramMd5Authenticator`, which accepts an empty
  password. Certificates are always verified: nothing in the settings or the DSN reads a `cafile` or any
  verification option (pinned by `testNoVerificationOptionIsReadFromTheDsn`). Pinned, without connecting,
  by `Tests/Unit/Mail/SmtpTransportFactoryTest` (host, port, implicit TLS, auto-TLS and require-TLS of the
  transport for the whole table, the timeout, the authenticators) and, for the production container,
  `Tests/Kernel/MailServicesTest`.
- `Idno\Core\Email` stays as the adapter for legacy callers (the password reset, invitations, the admin test
  mail, the import and export mails, `Logging::oopsAlert()`), with the same methods. Its `message` is a
  `Symfony\Component\Mime\Email`. A setter that is given an address or name the message refuses (a line
  break, not an address) does not throw: it remembers it, and `send()` sends nothing. A subject's line
  breaks become a space. `send()` sets From from `from_email` and `title` when `from_email` is set (else what
  the caller set stands; with neither there is no sender and nothing is sent), triggers `email/send` with the
  `Email` adapter as data and the message as the value (a listener may return another
  `Symfony\Component\Mime\Email` to replace it; anything else is ignored), sends through the container's
  `MailerInterface`, and returns the number of To and Bcc recipients. It catches every `\Throwable` (also
  while building or sending), logs ONE line `Error sending mail: <exception class>` (the exception's message
  can quote the DSN, so it is never logged, and a failing logger changes nothing) and returns 0. This is
  what lets `Logging::oopsAlert()` call it from an error handler: with no container or no legacy
  application it returns 0 quietly. `Email::lastFailure()` says why the last send returned 0 in a fixed
  vocabulary, never exception text: `settings: <name>` (an `InvalidMailSetting`), `message` (an address or
  name refused, or no sender or body) or `transport`; the admin test mail page (`Idno\Pages\Admin\EmailTest`)
  uses it to name a bad setting. `Email::useMailer(?MailerInterface)` replaces the mailer for tests.
  `Logging::oopsAlert()` is called from the exception handler and the fatal-error shutdown function, so
  everything in it after its early returns is in a `try`/`catch (\Throwable)`: it closes any output buffer
  a failing template left open, writes one line `Could not send the alert: <class>` through the logger if
  there is one, and otherwise does nothing. When drawing the HTML or text part throws (a broken engine, an unloadable template)
  it still sends a short plain text alert built in PHP (the site's address and the message, which carries the file and line; nothing
  from the request) and logs `Could not draw the alert: <class>`; only if sending fails too does it log `Could not send the alert`. The email shell does not need `REQUEST_URI` (a console process
  has none).
  The mail templates are Twig: `templates/email/**/*.html.twig` (the shell `shell.html.twig` and the eight bodies) and
  `templates/email-text/**/*.txt.twig`, drawn as every ported template is (a third-party `.tpl.php` mail template still wins,
  and its HTML is placed in core's shell as `body`). A `.txt.twig` is never escaped and never holds HTML: it controls its
  newlines with `{%-`/`-%}` and is compared with its original byte for byte (a `{{ }}` does not eat the newline that follows
  it, as PHP's `?>` does, so a text template has one newline fewer than the original source where an echo ended a line). The HTML
  parts escape every value from outside. The shell has no footer address and no request-derived tracking link; the mail
  templates use only `site_url()`, `site_title()`, `setting('from_email')`, `t()`/`t_html()` and the pure filters, so they
  draw in a console process with no request, no session and no logged-in user (`TemplateEscapingTest` draws them in a
  console subprocess). Pinned by `TemplateEscapingTest::testTheOopsMailEscapesRequestValues`, `testAnInviteEscapesTheInvitersName`,
  `testATextMailIsNeverHtmlEscaped` and `MailFlowsTest::testTheMailShellHasNoPostalAddressAndNoRequestPath`;
  `EmailAdapterTest::testOopsAlertReturnsWithoutThrowingWhenATemplateThrows` and `testOopsAlertSendsAPlainTextAlertWhenTheTemplatesCannotBeDrawn` pin that a mail
  template that throws is logged, never escapes `oopsAlert()`, and still sends the plain text alert.
- Secrets: `smtp_password` is in the DSN given to the transport factory and nowhere else: never in a log
  line, an exception message that reaches a page (the exceptions of `MailTransportDsn` name settings
  only) or the compiled container.
- No test sends real mail. In the test environment `config/services_test.php` replaces
  `idno.mail.transport_factory` with `Tests\Fixtures\TestMailTransportFactory`. By default it builds
  `RecordingMailTransport`, which sends nothing and appends each message (From, To, Bcc, Reply-To, subject,
  text and HTML bodies, the prepared headers, the envelope's recipients and the transport's DSN WITHOUT the
  password) as one JSON line to `<sys_get_temp_dir()>/idno-test-mail-<first 12 hex digits of the SHA-256
  of the checkout's real path>/mail.jsonl`, made on demand (CI's servers do not use the rig's directory),
  under `LOCK_EX`. The PHPUnit process and the rig's web server both append to it: a test reads it with
  `RecordingMailTransport::drain()` (which empties it) or, for mail the web server sent, over HTTP from
  `/_test/mail/log` (`Tests\Fixtures\MailLogController`, which also empties it). A test that sends mail
  drains the file in `setUp()` and `tearDown()`.
- Test hooks for delivering to a real (scripted) server, inert unless set, read at each transport built from
  `$_SERVER` then the process environment, and live only in `Tests/` and `config/services_test.php`:
  `IDNO_TEST_MAIL_DELIVER=host:port` makes `TestMailTransportFactory` build the PRODUCTION transport
  (`SmtpTransportFactory`, from the DSN the settings give) and replace only its host and port with that
  address (so the port the settings mean, 25, 465 or 587, which a test cannot bind, is read from what the
  factory built: `TestMailTransportFactory::built()`, `forget()`), and `IDNO_TEST_MAIL_CAFILE=<PEM file>`
  gives the TLS stream that authority as its `cafile` (the way a test trusts its own certificate authority;
  `SSL_CERT_FILE` does not work where PHP names a default `openssl.cafile`). The rig's web server never has
  them; a test of the web path starts a site of its own (`AdminEmailTestPageTest` extends
  `Tests\Queue\AsyncSiteTestCase` and passes them in `siteEnvironment()`). A hung server is tested with the
  production factory's constructor argument (`new SmtpTransportFactory(timeout: 1.0)`), not a switch.
- `Tests\Fixtures\Smtp\ScriptedSmtpServer::start(array)` is a real SMTP server in a subprocess
  (`Tests/Fixtures/Smtp/server.php`) on 127.0.0.1 and a free port: plain, STARTTLS, implicit TLS, AUTH PLAIN,
  LOGIN and CRAM-MD5, failure points (`connect`, `auth`, `rcpt`, `data`, `hang`, `hangAtStartTls`). Options
  `implicitTls`, `offerStartTls`, `certificate` (`valid-for-localhost`, signed by the test authority of the
  `pki` option, or `self-signed`), `auth`, `failAt`. `port()`, `transcript()` (decrypted lines, AUTH credentials
  decoded), `connections()` (per connection: TLS first or not, STARTTLS sent, AUTH mechanism, MAIL FROM, RCPT
  TO, accepted), `messages()` and `stop()` (always in `tearDown()`: a moment's wait, then SIGKILL; it also ends
  when its parent has gone and after ten minutes). `Tests\Fixtures\Smtp\TestPki` makes the authority and the
  certificates with ext-openssl in a temporary directory, once per test class. `Tests/Mail/MailTestSupport`
  is the trait the mail tests share.
- Parity with SwiftMailer. `Tests/Mail/SettingsMatrix` lists the settings rows (each with the server it is
  meant for); `Tests/Mail/fixtures/swiftmailer-transcripts.php` is what SwiftMailer 6.3.0 did with each, captured
  ONCE by `Tests/Mail/fixtures/capture-swiftmailer.php` (it installs `swiftmailer/swiftmailer` 6.3.0 with
  Composer into a temporary directory outside the repository, and drives the transport-building code of
  `git show 9c7f3f8c2:Idno/Core/Email.php`; it cannot be regenerated once that revision is gone, and the test
  suite only reads the fixture). `Tests/Mail/SwiftMailerParityTest` sends each row through the production
  wiring and compares the port the settings mean, whether the connection began with a TLS handshake, whether
  STARTTLS was sent, MAIL FROM, RCPT TO and delivered or not; its allow-list of differences holds only `tls`
  against a server that does not offer STARTTLS (SwiftMailer sent the command anyway; both fail, nothing in
  clear), and eight rows with a scheme in the host AND `smtp_secure` that SwiftMailer never delivered.
  The old `Email::setFrom()`, `addTo()` and `setReplyTo()` passed a NAME as `addFrom([name => email])`, which
  SwiftMailer 6.3.0 throws a `TypeError` for on PHP 8.5: named addresses never worked there; they are delivered now.
- `Tests/Mail/SmtpWireTest` (the behaviour beyond parity on the wire: no STARTTLS when `smtp_secure` is
  empty even if offered, `tls` needs STARTTLS, certificates verified, special-character passwords, refused
  logins logging neither user nor password, refused recipients, a server that hangs given up on within the
  bound, the headers and parts as built, an injected header never reaching the wire, and every call site: the
  password reset, the invitation, the admin test mail, `oopsAlert`, the import and export results) and
  `Tests/Mail/AdminEmailTestPageTest` (the test mail page over HTTP: success, a refused setting named, a
  plain error for a failing transport) run in `composer test`.
- `composer test:mail` (`Tests/rig/mail.sh`, in Docker; CI job `mail`) runs `Tests/Mail/MailpitDeliveryTest`
  against three `axllent/mailpit` containers (plain 1025, STARTTLS 587, implicit TLS 465, each with a login) on
  a network of their own, no port published on the host, in a PHP 8.4 container built from a COPY of the
  checkout (`Tests/rig/mail.Dockerfile`, `mail-container.sh`); the certificates are made by `TestPki` in a
  throwaway container; the four mails are read back through Mailpit's API (subject, both bodies, From, To,
  Reply-To). The test skips without `IDNO_TEST_MAILPIT_SMTP` and `IDNO_TEST_MAILPIT_API`, and
  `Tests/rig/mail-check.php` fails a run in which it skipped. For a release, also send the admin's test mail
  (Admin, Email) against the site's real provider once.
- Other tests: `Tests/Unit/Mail/MailTransportDsnTest`, `SmtpTransportFactoryTest`, `Tests/Kernel/MailServicesTest`, `Tests/Kernel/EmailAdapterTest`, and
  `Tests/Characterisation/MailFlowsTest` (what each mail carries: the password reset, the invitation, the
  admin test mail and `oopsAlert`: recipients, From, Reply-To, subject, the HTML part in the email shell, the
  text part and the link; and a password reset sent by the web server and read from `/_test/mail/log`).

#### Templates

`Idno\Templating` (modern style, a scanned directory) finds and renders templates; `Idno\Core\DefaultTemplate` (over
`Idno\Core\Bonita\Templates`) is the adapter every page and plugin calls, and delegates to it. The classes:

- Excluded from the scan in `config/services.php` and wired there, public: `TemplateCascade`, `SlotRegistry`, `TemplateRenderer`,
  `TwigTemplateEngine`, `CascadeTwigLoader`, `TolerantTwigCache`, `TwigCacheWarmer` and `IdnoTwigExtension` (the adapters `Idno\Legacy\LegacyTemplateTranslation` and `Idno\Legacy\LegacyTemplateHelpers` are wired
  there too, the latter under the interface `Idno\Templating\TemplateHelpers`); `TemplateName`, `TypeChain` and `LocatedTemplate` are value objects and static helpers.
- `TemplateName::normalise($name)`: a leading `_` followed by letters, digits and slashes is removed, then a name that
  holds `..`, a character outside `[a-zA-Z0-9/_-]` or is empty is `null` (refused); a template type is checked the same way
  (`TemplateName::isSafe()` is the check without the strip). The pattern ends in `$`, so a trailing newline passes (it names no file).
  A template TYPE is stricter: exactly one segment, `/\A[a-zA-Z0-9_-]+\z/` (`TemplateName::isType()`, `normaliseType()`), because a type is a
  directory name and `classic/` is the directory `classic` once the path is built. It is enforced wherever a type is accepted:
  `Templates::setTemplateType()` and `templateTypeExists()`, `TemplateLocator::locate()` and `typeExists()`, `TypeChain::for()` (empty
  for a non-type) and `selectable()`; a slash in a `?_t=` value (`classic/`, `CLASSIC/`, `/classic`, `default/`, `rss/`) asks for no type.
- `TypeChain::for($type)`: `default` and `modern` (in any letter case) are `['modern', 'default']`; any other type is `[$type, 'classic',
  'default']`. No core or bundled directory has a `modern` type: it is there so that a third-party plugin's or theme's `templates/modern/`
  files (written for the Twenty26 theme, whose `modern` type Idno 2026 replaced) still win on web pages for one release; the first
  `modern` file a process draws logs ONE `warning` line, `Deprecated: the template <path> is in a `modern` template directory...`
  (`TemplateRenderer`, with the logger as a closure: the site's logger needs the legacy application; the "already logged" state is
  shared by the copies `withSlots()` makes), so one line a request; removed in 3.0 (the registration line below has a flag of its own, so neither
  hides the other). `classic` holds the entity templates of the look
  Idno 2026 replaced (the old Bootstrap-era `default` ones): feeds, JSON, JSONP, JSON Feed, Markdown, ActivityPub, KML and mail reach them by falling back
  from their own type, before `default`, so what they contain is what it was; a web page never draws `classic`. `classic` also holds a
  copy of every `default` file those types reach by name from there (the syndication buttons, `forms/token`, `forms/action`, the annotation
  and profile partials, `pages/home/nocontent`, the output of a few form controls, the entity templates of Media and StaticPages): the
  original in `default` is the web page's (a Twig file) and may be changed, the copy is what a feed draws.
  `Tests/Unit/Templating/NonHtmlReachTest` works out, from the file system, the closure of template names each non-HTML type reaches:
  it starts from the type's own directory, `entity/<Class>` for each `#[AsEntity]` class, `entity/default` and the helper methods'
  names, follows the string literals passed to `draw()` (a literal prefix followed by `.`, as in `draw('content/syndication/icon/' . $service)`,
  stands for every template of any type under it) and resolves each name through the type's chain. It asserts that no file under a
  `default` directory is reached (so a bundled plugin that adds a `default` file under such a prefix fails it until the file is copied),
  and, starting also from the string literals passed to `extendTemplate()`, `prependTemplate()`, `replaceTemplate()`,
  `extendTemplateWithContent()` and `drawPage()` in PHP outside the templates (and from the one name listed in the test as drawn through
  a `template` input), that every `classic` file is reached. The URL shell overrides (`/admin/` and `/account/` draw `settings-shell`)
  apply to web pages (the `default` type) and to `jsonp`, in any letter case: a JSONP page is a script any site can load, and these
  pages hold the account's and the site's settings, so they stay the HTML settings shell. Any other type, `?_t=json` included, draws the shell
  of its own type (`Tests/Characterisation/SettingsPagesJsonTest`). `selectable($type)` is
  false for `classic` (in any letter case: `CLASSIC` is the directory `classic` on a file system that ignores case, and
  `Templates::setTemplateType()` and the `?_t=` check both refuse it) and for anything that is not one segment; `requestable($type)` is
  `selectable()` less `email` and `email-text` (any letter case): a REQUEST (`?_t=`, in `Page::init()` and
  `DefaultTemplate::autodetectTemplateType()`) never selects a mail type, because a plain-text mail is not escaped and a web page drawn from it
  would print text as markup, while code (the mail sender's `setTemplateType('email')`) still can (`BuiltInLookTest::testAMailTypeCannotBeRequested`); `setTemplateType()` of `default` or `modern` in any letter case stores `default`
  (`getTemplateType()` never returns `modern`: a plugin that tested for it tests for `default`), so `?_t=modern` is the default type. What a
  third-party plugin registered for the type `modern` (`extendTemplate(..., 'modern')`, `replaceTemplate(..., 'modern')`) is applied to a web page
  draw too, INSTEAD of the `default` ones for a name that has any `modern` registration (a page applied the registrations of `*` and `modern`
  or of `*` and `default`, never both: `TemplateRenderer::slotTypes()`), with one deprecation line of its own a process, logged only when a
  registration for `modern` was applied (not by a draw without replacements that ignored a replacement, nor for a name with none); `Tests/Kernel/TemplateTypeNameTest`. A third-party plugin's `default/<entity template>` override no longer changes a feed or a mail for a name `classic` has: `classic` is
  tried before every directory's `default`.
- `TemplateCascade` (container service, public, shared; core's directory is `%kernel.project_dir%`): `addBase($dir)`
  puts an existing directory that is not listed yet FIRST, `bases()` lists them with core always last, `coreDir()`,
  `generation()` (changes with each directory added), `isFile($path)`: whether a path is a file, asked of the disk ONCE and remembered (the
  locator and the Twig loader ask about the same paths, and each answer is a system call: a page drew about 702 of them for its 26 Twig
  templates, 26 for each, before the answers were shared; the extension set-up that the first function call forced cost 135 us and 117 more classes before the functions became methods), until a directory is added (`addBase()`) or `forgetFiles()` is called (which also changes `forgotten()`, the number the Twig loader reads to drop its resolved names) (the locator
  calls it when it empties its own answers, so a forgotten answer is looked up on the disk again); it holds at most
  `TemplateCascade::REMEMBER_LIMIT` paths and is emptied when it passes. A file created after its path was asked about is not seen until then,
  as for the locator's answers (`Tests/Unit/Templating/EngineCostTest`).
  Filled at run time by the plugins and the theme (`Bonita\Main::additionalPath()` forwards to it), never from the
  container's configuration. A command-line process has core's directory too (it is the cascade's, not added at boot).
- `TemplateLocator::locate($name, $type, $fallbackToDefault = true)`: the order is the type, then the base, then the format:
  for each type of the chain, for each base, `<base>/templates/<type>/<name>.html.twig` (`.txt.twig` for `email-text`; only
  the types `default`, `email` and `email-text` have Twig files, `TemplateLocator::TWIG_EXTENSIONS`; the feed, JSON, JSONP, JSON Feed,
  Markdown, ActivityPub and KML types are `.tpl.php` only, whatever Twig file of their name exists) and then `<name>.tpl.php`, as a `LocatedTemplate`
  (`path`, `engine` `php` or `twig`, `type`, `base`, `logicalName` `<type>/<name>.<ext>`), or null. So a third-party
  `.tpl.php` override in a plugin or theme still beats a Twig file of the same name and type in core, and within one directory
  Twig wins. Every file it looks for is asked of `TemplateCascade::isFile()`. The answers are remembered (type, name, fallback) until the cascade's `generation()` changes: the files of a directory do not
  change while a request is served, and an ordinary page draws the same names again and again, each look-up a file-system check per directory,
  type and format (measured: the Twig check alone made a locate about twice as slow; remembered, a repeat costs a fraction of a microsecond).
  The Twig candidate is offered only when `CascadeTwigLoader::isLogicalName()` (the one predicate) accepts its logical name: a name with a
  leading or doubled slash (`TemplateName` accepts it, the file system resolves it) is a `.tpl.php` name only, and a Twig file of it alone is not found.
  The memo holds at most `TemplateLocator::REMEMBER_LIMIT` answers and is emptied when it passes (names come from requests). Whether
  `<base>/templates/<type>` is a directory is asked once per directory and type (`is_dir`) and remembered the same way (every web page's
  chain includes `modern`, which no bundled directory has, so each name would otherwise cost a failed file check per directory), and a
  directory without the type's folder is not probed for files; the trade-off: a `templates/<type>/` folder created while a process runs is
  seen only after the cascade changes or the process restarts. A plain-text mail
  (`email-text`) looks for `.txt.twig` in EVERY directory of its chain (its own, `classic`, `default`) and never for `.html.twig`, which would
  escape its text as HTML: it draws `.txt.twig` or `.tpl.php` files only (`TemplateLocatorTest::testEmailTextNeverDrawsAnHtmlTwigFile`). The
  feed, JSON, Markdown and KML types still find a `default/<name>.html.twig` by fallback (they never draw one: `NonHtmlReachTest` pins that every `default` file they reach has a `classic` copy, or is a page body that leaves nothing on the page); a template of those types that
  is Twig must be a `.tpl.php` file in the type's own directory or in `classic`.
  `typeExists($type)`: some base has a `templates/<type>/` directory (a type is one segment: `classic/` is no type).
- `SlotRegistry`: what `extendTemplate()`, `prependTemplate()`, `replaceTemplate()` and `extendTemplateWithContent()`
  record (`extend`, `prepend`, `replace`, `extendWithContent`; `extensions()`, `prepends()`, `replacement()`, `content()`
  and the `*Map()` readers). The container has one (the site's template object keeps its registrations in it:
  `DefaultTemplate::useSlots()`, called by `LegacyServiceFactory::template()`), but every other `DefaultTemplate` (`new
  DefaultTemplate()` for the feed shell or a user search) has a registry of its own, empty, and a `clone` of a template object
  copies its registry: what is registered on a copy does not reach the original (pinned by `TemplateContractTest`). The public
  properties `extensions`, `prepends`, `replacements` and `rendered_extensions` are read-only views (`DefaultTemplate::__get()`, and `__isset()`, always true for them; a write is an ordinary template variable). The registry is made when first needed (`DefaultTemplate::slots()`), so a subclass that skips the parent constructor works.
- `TemplateRenderer` (container service): `draw($name, $vars, $type, $context, $contributions = true, $fallbackToDefault = true,
  $replacements = true)` is `DefaultTemplate::draw()`'s order (prepends; for `*` then the type the replacement and the extensions;
  the template unless replaced; the added content) and returns null when nothing was rendered; without `$contributions` it draws the
  one file (the plain `Bonita\Templates::draw()`);
  `withSlots()` reads another registry. `$vars`, `$type` and `$fallbackToDefault` may be closures (`DefaultTemplate` passes them), called each time a file is drawn: an extension that sets a variable, or a prepend that changes the type or turns the fallback off, changes what the later files and the template itself see (the type that picks the `*` and type registrations is read once, after the prepends), as `Bonita\Templates::draw()` read `$this` for each contribution. Each file drawn calls the trace hook `TemplateRenderer::$trace` (type, name, path or `-`)
  before it is rendered, except a refused name (a Twig file is traced like any other). `drawSlot($name, $vars, $type, $context,
  $fallbackToDefault = true)` draws only the registrations of a name (prepends, then the `*` and the type's extensions, then the added
  content: no replacement, not the template itself), for `slot()`. A located Twig file with no Twig engine is a `\LogicException`, never
  `include`d as PHP. `$vars` is untyped on purpose: an object whose `vars` were replaced by a non-array
  through `__(['vars' => ...])` fails in the render, as it always did (in a Twig render: an `\UnexpectedValueException`).
- `PhpTemplateContext` has, beside `withVars()` and `phpTemplateScope()`, `draw($templateName, $returnBlank = true)` and
  `drawSlot($name)`: what `idno_include()` and `slot()` of a Twig template draw through (`Bonita\Templates` implements both).
- `PhpTemplateEngine` (`TemplateEngine`): `include`s the file in a function bound to the `PhpTemplateContext` (`$this` and `$t`;
  `DefaultTemplate` is one, `withVars()` gives a copy with other variables; the file is bound with the scope `phpTemplateScope()` names, `Bonita\Templates`, so no private member of a subclass is reachable from a template and a variable may be named `slots`) with the variables extracted into its scope, inside an
  output buffer. The file included is the file the locator found, whatever the variables are called: its name is kept on a static stack
  (one entry for each draw in progress, popped in a `finally`, so a nested draw includes its own file and an exception leaves nothing
  behind; `PhpTemplateEngine::currentFile()` is read by the including function after the variables are in scope), where extracting cannot
  write it. In scope of the file are the variables, `$vars` (the variables as an array, which templates use) and `$t` (the context); a
  variable named `vars` or `t` replaces them; the loop's own variables have names no template can write (`${' k'}`), so a variable named
  `path`, `k` or `v` is the caller's (`TemplateContractTest::testAVariableNamedPathDoesNotReplaceTheFileIncluded`,
  `testTheEnginesOwnNamesAreOrdinaryVariables`; no core or bundled template relied on the engine's `$path`: those that use `$path` assign it
  first); a variable named `this` is an Error; an exception in a template propagates after the
  engine has ended the buffer(s) its render opened (what they held is discarded; buffers opened before the render are left
  alone), so a draw nested any depth leaves the buffer level as it found it
  (`TemplateContractTest::testAnExceptionInATemplateLeavesNoOutputBufferOpen`, `testAnExceptionThreeDrawsDeepLeavesNoOutputBufferOpen`).
  `Idno\Templating\OutputBuffers::endDownTo($level)` does the closing (the engine and `Logging::oopsAlert()`, which stays as a defence, use it): a buffer PHP cannot remove (one opened without the flag `PHP_OUTPUT_HANDLER_REMOVABLE`, which is all `ob_end_clean()` needs: a removable buffer that is not cleanable is ended) stops it, with what is below it left as it is, and nothing is thrown or raised, so the exception being handled is never replaced and nothing loops (`Tests/Unit/Templating/OutputBuffersTest`, `TemplateContractTest::testAnExceptionWithABufferThatCannotBeRemovedPropagatesTheOriginalAndStops`). `View::endOutputBuffers()` already stops at a buffer it cannot end.
- **Twig** (`symfony/twig-bundle`, `config/packages/twig.php`) is the second engine. Twig's cache is `%kernel.cache_dir%/twig` (compiled
  templates are PHP files that get included: they are trusted exactly as the compiled container is, and never go in the upload path or a
  shared temporary directory; `TemplateCachesTest`, `TwigEngineTest::testCompiledTemplatesLiveInTheKernelCacheDir`); `strict_variables` is
  off (legacy templates pass sparse variables); the autoescape strategy is the bundle's `name`: HTML for `.html.twig`, none for `.txt.twig`.
  There are no `paths` and no globals from the configuration (the bundle's `app` global stays); `auto_reload` is on in every environment (one `filemtime` for each Twig file loaded), because the compiled files are kept per build and the
  code fingerprint does not cover `IdnoPlugins/` or `Themes/`: a plugin or theme template updated in place is compiled again
  (`Tests/Kernel/TwigAutoReloadTest`, a production kernel in a process for each render: Twig declares a compiled class once a process). `translator` stays OFF in FrameworkBundle: `t()` is Idno's own.
  - `CascadeTwigLoader` (`Twig\Loader\LoaderInterface`, tagged `twig.loader` priority 100): `<type>/<name>.html.twig` or `.txt.twig` is the
    file in the first directory of the cascade that has it, `@core/<type>/<name>...twig` is core's alone; any other name is not its own
    (`exists()` false). A name reaches the file system only after it is at least two segments of `[a-zA-Z0-9_-]` joined by single slashes, that
    `TemplateName::normalise()` leaves as they are, with the suffix (`isLogicalName()`): `..`, a backslash, NUL, a trailing newline, a leading or
    doubled slash, an absolute path and `@Twig/...` are refused before any file is looked for, so `{% include %}`, `{% extends %}` and `source()` of
    any name reach only a file of a `templates/` directory of the cascade (`CascadeTwigLoaderTest`, `TwigEngineTest::testATemplateCanLoadOnlyAFileOfTheCascade`).
    `getCacheKey()` is the resolved file and its CONTENT VERSION (path, `stat()` modification time and size; a real `stat()` each call, with PHP's stat
    cache cleared for the path first), and Twig names a compiled class after the key, so a file whose content changes always gets a class of its own, whatever
    its date: a rollback to an artifact built earlier, an archive that keeps the build's dates (`rsync -a`, `tar`) and a `git checkout` of an older commit give a
    file OLDER than the compiled copy of the one it replaced, which a check on dates alone would keep serving until `var/cache` was cleared (pinned by
    `TwigAutoReloadTest`, a fresh process each render, and `CascadeTwigLoaderTest`). `isFresh()` therefore only asks whether the name still resolves (a compiled class
    under a key is for exactly that version: no further `stat()`); a file deleted under a long-lived process has no key (`getCacheKey()` and `getSourceContext()`
    throw `LoaderError`). The key's equal size and equal modification time with different content (two saves in one second of the same length) is not told apart.
    Compiled classes of superseded versions stay in the cache directory until it is cleared; nothing reads them again. `TwigCacheWarmer` warms under the same keys
    (the class is named after the key, not the name asked for), so a warmed template is a cache hit at run time (`TwigCacheWarmerTest`). A name is resolved once and
    the answer kept (until the cascade's `generation()` changes, at most 2000 names), and the paths it looks at are `TemplateCascade::isFile()`'s, which the locator has asked
    about already, so loading a template the locator found costs one `stat()` of the source (and Twig's of the compiled file under `auto_reload`, which is on) and no
    search of the directories (`EngineCostTest::testATemplateIsStatedAtMostOnceWhenFirstLoadedAndNeverAgainInTheSameProcess`). The loader drops its names when a directory is
    added or `forgetFiles()` is called. What a long-lived queue worker sees: the NAMES are remembered, the version is not (each `getCacheKey()` asks the disk), but
    `TwigTemplateEngine` keeps every template it has loaded (at most 2000) for the life of the process, so a worker keeps the version of a template it first drew until it
    restarts (it stops itself after an hour), whether the file changes or is deleted under it; a web request is a process of its own and always sees the file as it is. `Idno\Kernel\TwigTrimPass` (priority 9: after the bundle's
    extension pass, which tags what this removes, and before its loader and environment passes) removes the bundle's own filesystem loader from the chain (it would answer any file under `templates/`, `.tpl.php` source
    included), so this is the only loader, and removes `twig.error_renderer.html` (which would draw Symfony's production error page of a
    new-style controller from the bundle's `@Twig` templates: the page is what it was; the CLI never used it), `twig.mailer.message_listener` and
    `twig.mime_body_renderer` (mail is `Idno\Mail`, whose mailer has no dispatcher), and the bundle's HttpKernel extension and runtime
    (`twig.extension.httpkernel`, `twig.runtime.httpkernel`: `render()`, `controller()`, `render_esi()`, `render_hinclude()`, `fragment_uri()`: a
    template draws no other request, and a legacy page's templates have no controller; routing's `path()` stays) and its template cache warmer
    (`twig.template_cache_warmer`), and sets the environment's cache to `Idno\Templating\TolerantTwigCache`. The bundle's `lint:twig` and `debug:twig` stay, and so does
    the `kernel.view` listener for `#[Template]`, which only acts when a controller returns no response; the others it registers do not run on a
    legacy request. `Tests/Kernel/TwigTrimTest` (test and freshly compiled prod containers, no legacy application: `debug:twig` runs too).
  - The compiled-template cache (the bundle's directory, `%kernel.cache_dir%/twig`) is `Idno\Templating\TolerantTwigCache` over Twig's
    `FilesystemCache`: when it cannot create or write its directory (a deploy run as another user, a read-only file system) it logs ONE warning
    a process (`Twig could not write its compiled template cache: <directory>`, through a logger closure, and nothing if the logger is not available
    because the legacy application did not boot) and goes on, so Twig evaluates the compiled code in memory: slower, otherwise the same, and no page
    fails (`Tests/Unit/Templating/TolerantTwigCacheTest`). `Idno\Templating\TwigCacheWarmer` (a `kernel.cache_warmer`, OPTIONAL, so it runs in
    `php bin/console cache:warmup` and not at a boot) compiles every `.html.twig` and `.txt.twig` file under `templates/` of the project (by its
    `@core/` name), of each `IdnoPlugins/<name>/` and of each `Themes/<name>/` (by its logical name, through the cascade, to which it adds those
    directories for the life of the process; a name an earlier file already has resolves to that file). A core file that does not compile is an
    error; a plugin's or theme's is skipped (the page that draws it shows the error; `lint:twig` checks them). Run it as the web server's user after
    an update (`docs/install/upgrade.md`). It warns (the site's logger when there is one, and standard error: the warmer interface has no output of its own) about each plugin or theme
    template it skipped, naming the logical name and the error's class, and when the cache directory could not be written (it still returns normally). `Tests/Kernel/TwigCacheWarmerTest`.
  - `TwigTemplateEngine` (`TemplateEngine`): holds a CLOSURE to the `twig` service, so the environment is built when the first Twig file is
    drawn and a page that draws none never builds it (`TwigEngineTest::testTheEnvironmentIsBuiltWhenAFirstTwigFileIsDrawnAndNotBefore`,
    `TwigTrimTest`). It loads `$located->logicalName` through the loader (the file the locator found) and renders it with the variables
    plus `_idno_type` (the type folder the file was found in) and `_idno_context` (the template object), in an output buffer of its own, which
    it ends, with any the template left open, when the render throws (it does not use Twig's `render()`, whose own cleanup fails on a buffer PHP cannot
    remove). An exception thrown by something a Twig template called (a `.tpl.php` file drawn by `idno_include()`) leaves as itself, not as Twig's
    `RuntimeError` wrapper; a Twig error (syntax, a missing template) is thrown as it is, never swallowed (`HybridTwigTemplate` caught every exception).
    The variables are a snapshot for the whole render: a Twig template does not see a variable an included template sets afterwards (a `.tpl.php`
    file reads them from the object at each draw); they must be an array, else an `\UnexpectedValueException`.
  - Every function and filter of `IdnoTwigExtension` is a METHOD of the extension (`siteUrl()`, `autop()`, ...; they pass on to the helpers), never a closure
    over the helpers or of the extension's own code: Twig compiles a call of a method of an extension to `$this->extensions[...]->method()`, and any other
    callable to `$this->env->getFunction(name)->getCallable()()`, whose first use in a request makes Twig build every function, filter, test, token parser and
    operator of every extension (about 0.14 ms and 60 classes a request: the largest part of what a page drawn from Twig cost over the same page from the
    old engine, with the Symfony bridge's extensions loaded too). A new function is a method of the extension, passed as `$this->name(...)`
    (`EngineCostTest::testEveryFunctionAndFilterIsAMethodOfTheExtension` and `testATemplateIsDrawnWithoutBuildingTheExtensions`).
  - `object.method(...)` in a Twig template (a method called by a name written in the template: no `?.`, no `is defined`, not a property) is compiled by
    `Idno\Templating\MethodCallNodeVisitor` and `MethodCallExpression` (not services: in `config/services.php`'s excludes; the extension's `getNodeVisitors()`)
    to `$this->extensions[...]->callMethod(object, name, arguments, ...)`, which calls a public method of the object and for anything else (another letter
    case or the shorthand `name` for `getName()`, `__call()`, a value that is not an object, a missing method) is `CoreExtension::getAttribute()`, as Twig
    answers it. Twig's own attribute access builds, once for each class a request touches, a table of the class's methods (about 40 microseconds for an
    entity class: `get_class_methods()`, a sort and a lower-casing of 150 to 200 names) and then spends about a microsecond a call; a home page made 230 such
    calls and Twig's share of them was about 0.4 ms a request. The call is rewritten only for a PUBLIC method whose declared name is EXACTLY the name in the template (decided once for each class and name by reflection, kept in a static array); a name in another letter case, a getter's shorthand, a protected or private method (even with `__call()`), `?.`, `is defined` and a property are Twig's. The rewrite stands down when a `SandboxExtension` is registered (checked when the template is compiled; the extension set is part of the compiled class's name), so a sandbox's method policy still applies. Pinned by `EngineCostTest` (compiled form, and the same
    answer as `getAttribute()` for a public method, a name in another case, `__call()`, a private or protected method, null, an array, a string and a closure).
  - `TwigTemplateEngine` keeps the compiled template of each (logical name, file) it has drawn (at most 2000), so a template drawn again in the request (a
    partial in a loop) is not looked up in Twig again; it is a property of a container service, so it lives as long as the container: one request on the web, the worker's life in the queue worker (where Twig itself keeps every loaded template).
  - `IdnoTwigExtension`: `idno_include(name, vars)` draws as `$this->draw()` does (type then `default`, either engine, contributions) through
    a COPY of the template object (`withVars()`) holding the including template's variables, less Twig's globals (`app`, ...: only a value identical to the global) and bookkeeping (`_idno_type`, `_idno_context`, `_parent`, `_seq`, `_key`, `_iterated`, `loop`; any other `_foo` variable is kept), and
    the given ones over them; `slot(name)` is `drawSlot()`; both are `is_safe: html` and need `_idno_context`; `t(text, subs)` is
    `TemplateTranslation::translate()` (`Idno\Legacy\LegacyTemplateTranslation` over `Language::_()`, fetched when a string is first translated, so
    the environment can be built without the legacy application) and is escaped as any string.
  - The helper surface of Twig templates (named functions and filters, no `site`/`idno` object and no lookup of a service or class by name;
    `IdnoTwigExtension`, with the interface `Idno\Templating\TemplateHelpers`, one method for each name that needs the site, implemented by
    `Idno\Legacy\LegacyTemplateHelpers` over `Idno::site()`; the adapter holds nothing and reads the application only when a method is called, so
    the environment is built without it). Functions: `t_html`, `site_url`, `static_url`, `site_title`, `site_icons`, `setting`, `can_write`,
    `content_types`, `content_type_for_post_type`, `current_user`, `is_logged_in`, `is_admin`, `current_url`, `current_url_parts`, `current_path`, `referrer`, `input`,
    `path_matches`, `is_permalink`, `is_xhr`, `is_secure`, `page_assets`, `page_owner`, `page_icon`, `leaked_user`, `http_status`, `login_url_for`, `url_with_var`, `url_without_var`,
    `current_url_without_var`, `body_classes`, `form_token`, `action_link`, `form_data`, `shell_variables`, `pagination`, `syndication_services`,
    `syndication_selected`, `share_types`, `expand_urls`, `local_entity`, `local_file`, `access_groups_of`, `latest_post_of`, `entity_value`, `twitter_account_of`, `is_user`;
    filters: `sanitize_html`, `autop`, `parse_urls`, `parse_hashtags`, `parse_users`, `http_url`, `identity_url`, `to_int`, `to_timestamp`, `ucfirst`, `decode_entities`, `display_url`, `fix_url`, `proxied_image`,
    `sample_text`. `current_url()`, `current_path()` and `referrer()` come from the `RequestStack` ('' with no request: a legacy page's request is
    not on the stack, so they answer '' there until it is put on it); `setting()` is an ALLOW-LIST (`LegacyTemplateHelpers::TEMPLATE_SETTINGS`, in groups with a reason each: exactly the names core's and the bundled plugins'
    Twig templates read; any other name is null, so the database settings, `uploadpath`, `oops_notify` and every secret are never readable by a third-party template; a plugin
    adds its own names with `TemplateHelpers::allowSettings()` from `registerPages()`, which refuses a name `SettingsRedactor` calls a secret; `LegacyTemplateHelpersTest` derives
    the names the templates use from the file system, so a new `setting('x')` with no entry fails), and the extension still answers null for a secret by `SettingsRedactor`'s rule (name or
    value); `http_url` is `UrlValidator::httpUrl()`; `entity_value(object, name)` reads a stored attribute (an array by key) and never calls a method.
    `current_url_parts()` is `Page::currentUrl(true)` (`parse_url()` of the configured address with the request URI as `path`; `[]` with no request),
    `is_secure()` is `Page::isSSL()` (the adapter calls it), `page_icon()` is `Page::getIcon()`, and `leaked_user()` is the `user` variable of the SITE's
    template object when the template that asks is that object, else null: icon lookups (`Page::getIcon()`, `Entity::getIcon()` through
    `User::getIcon()`) draw a default avatar on the site's template object, which keeps the user there, and the old page shell drew everything after
    its icons with the user the LAST lookup left as the page's `user` (the object's owner when the page has an object, else the page's owner; the HTML
    goldens pin it and the JSON pages print the same leaked `user`: do not change `User::getIcon()` without changing both). A Twig template cannot see
    what another template set on the object, so `shell.html.twig` merges `leaked_user()` into `shell` before the structured data and the ActivityPub
    link (the head's partials make the same icon calls in the same order: `TemplateDrawCasesTest::drawIconLeakCases()`); a shell drawn on a copy of
    the site's template saw none of it, and gets none. `is_user(v)` is `instanceof \Idno\Entities\User`, `to_int` is `(int)`, `identity_url` is
    `UrlValidator::httpUrl()` or a `mailto:`, `sms:` or `tel:` address (any case, trimmed) with no white space or control character, else ''; all pure.
    `twitter_account_of(person)` is pure (it needs nothing of the site) and the old Open Graph partial's search of a profile for a Twitter account.
    SAFE for HTML, exactly (pinned by `IdnoTwigExtensionTest::testOnlyTheListedFunctionsAreSafe`): the functions `idno_include`, `slot`, `t_html`
    (the translated text is trusted; each string sub is escaped, a `Twig\Markup` one kept; `|raw` output is not `Markup`), `form_token`, `action_link`, `form_data` and
    `pagination`, and the filters `sanitize_html` (HTMLPurifier through the `text/filter` event) and `autop`, `parse_urls`, `parse_hashtags` and
    `parse_users`, which have `pre_escape: html` (raw text is escaped first, sanitized HTML and `Markup` are kept). `action_link`'s label and
    `form_data`'s argument are markup only when they are `Twig\Markup` (a `{% set %}` capture or the output of a safe function: the safe functions
    return `Markup`; a plain string, and `|raw` output, which is a plain string at run time, are escaped); `action_link` draws nothing for an address that
    is neither relative to the site nor http(s) (`UrlValidator::httpUrl()` or a relative address: no scheme, backslash, white space or control character).
    The text linkers are fixed in the LEGACY methods, so the `.tpl.php` templates, the `classic` copies and the filters share one implementation:
    `DefaultTemplate::parseURLs()`, `parseHashtags()` and `parseUsers()` transform only the TEXT of their input (`Parsing::mapText()`: not the inside of a
    tag, a comment, an `<a>` element, `<script>`, `<style>` or `<textarea>`; a tag that is never closed ends the transformed part) and `parseHashtags()` never
    decodes an entity whose character is `<`, `>`, `&`, `"`, `'` or a backtick, in any spelling (`&auml;` still decodes). Before, an address in an `alt` or
    `title` of sanitized HTML became a link that ended the attribute (a stored XSS), and `&quot;` was decoded to a quote. `Actions::createLink()` still
    prints the url, class, title and confirmation text unescaped, so `actionLink()` escapes them, percent-encodes a character that ends an attribute in
    the url and takes only the options the link template reads.
    `form_data()` registers with `shell/form-data` (nothing returned) or, in an XHR request, returns the markup to the template that is drawing
    (the legacy link template appended it to a global that only the user search page read). `shell_variables()` computes what
    `formatShellVariables()` does on a copy of the template object with no variables and returns them (the site's template object is untouched);
    `pagination()` draws on a copy too and uses only the `control-id` and `source-url` of its variables; `shell/pagination` escapes what it prints (the
    count, offset and limit as integers, the control, the address of the links). Tests: `IdnoTwigExtensionTest` (a `TemplateHelpersDouble`, no legacy application),
    `Tests/Kernel/LegacyTemplateHelpersTest` (each adapter method on the rig's site), `Tests/Kernel/TemplateLinkersTest` (the three linkers against the HTML5 parser) and `RawUsageTest` (the first argument of `t_html(` in a core or bundled Twig file is a string literal). `Idno\Core\PotGenerator` also reads `.twig` files: the first
    argument of `t(` and `t_html(` when it is a string literal (Twig's lexer; `PotGeneratorTest::testTwigTranslationCallsAreExtracted`). It reads the string literals of `$title` and `$category_title` in a class that extends a `ContentType` too (`ContentType` translates them with `language()->get($this->title)` when asked, so no call names them; `PotGeneratorTest::testAContentTypesTitlesAreExtractedFromItsPropertyDeclarations`). A string that reaches `t()`/`_()` only through a variable (`t(label)` in `entity/edit/header`, `forms/input/form-list`) is extracted only where the caller writes it as a literal.
  - Tests: `Tests/Kernel/CascadeTest` and `TwigEngineTest` run cases of `Tests/Kernel/template_twig.php` (one subprocess each, on the fixture
    bases, whose Twig files are the `tw-*`, `mix-*`, `inc-*`, `slot-*` and `esc*` files), `Tests/Unit/Templating/CascadeTwigLoaderTest`,
    `IdnoTwigExtensionTest`, `TemplateLocatorTest`, `TemplateRendererTest`, `Tests/Kernel/TwigTrimTest`, `LegacyTemplateTranslationTest`, `LegacyTemplateHelpersTest`.
    The `@core` real-container case writes a probe file in `templates/default/` (`zz-core-probe-*`, gitignored) and removes any left by a killed
    run when it starts.
  - The page shells are Twig (`templates/default/{shell,account-shell,admin-shell,settings-shell}.html.twig`, `shell/**`, `js/*`, `assets/*`,
    `txt/robots`). `shell`, `account-shell` and `admin-shell` have the blocks `head`, `nav`, `content` and
    `footer` (the same names in all three), so a theme can `{% extends '@core/default/shell.html.twig' %}` and replace one
    (`BuiltInLookTest::testAThemeExtendingTheCoreShellReplacesOneBlock`); they do not extend one another and use no `slot()`. Each reads
    `{% set shell = shell_variables() %}` and draws every partial with `idno_include(name, shell)` (the partials see the formatted variables, as they
    did on the one template object), `shell/headers` included: it has no core file, a plugin extends it, and its output is discarded. The page's
    `body` is printed with `|raw` (the one place that may). Two things to know: (1) a draw of a name keeps the order `TemplateRenderer::draw()`
    gives it (the prepends, then the replacement and the extensions, then the template itself, then the added content); (2) the site's template
    object is what code like `User::getIcon()` and a link's form registration reach, so a Twig partial must not expect to see what they leave
    there (above). `shell/icon` is one `{% if iconName == ... %}` chain of literal SVG. Values from outside are escaped where the old templates
    printed them as they were: the path in the body's classes, the Open Graph description and address, the Dublin Core and structured data
    values; `shell/identities` links only http(s) addresses; `js/idno` and the service worker script use JSON_HEX_* / `|e('js')`
    (`Tests/Templating/TemplateEscapingTest`). The service worker branch of `shell/webapp`
    (HTTPS and a member) is not drawn by the golden pages (the rig is http); `TemplateEscapingTest::testTheServiceWorkerScriptIsWrittenForAScript` draws it.
  - Entities, content, streams, pages, files and following are Twig too (`templates/default/{entity,content,stream,pages,file,following}/**` and
    `drafts`: 58 files). They draw every other template with `idno_include()`, which draws on a COPY: a variable a `.tpl.php` template (the old look's, or a third party's) left on the page's template object
    by `__()` is gone for the Twig ones, and an HTML page that showed one changed by it, on purpose (below); the JSON keys they left stay, because (the JSON, JSONP, Markdown, feed and KML pages draw `templates/classic/` copies of the
    page-body templates `entity/wrapper`, `entity/User/profile` and `pages/home/blurb`, which still draw on the page's object, so those pages are byte for byte what they were:
    `fixtures/fallback-trace.php`; `NonHtmlReachTest::testAPageBodyANonHtmlTypeCanReachIsAClassicFileOrAListedTwigFile` lists the other
    page bodies a non-HTML request can reach from a Twig file (which publish nothing on the page: a Twig body draws on a copy), and fails for a new one, which needs a `classic` copy if JSON needs what the old template left on the page; `non-html/json-profile-ada.txt` pins the profile page's JSON), and a user's profile page's Open Graph and Dublin Core describe the profile, not the last note of its feed
    (`entity/feed` drew each note with `object` left on the template, which the permalink page's head used). Helpers added for them:
    `is_entity`, `random_id` (`md5(mt_rand())`, for the ids the normaliser numbers), `default_avatar(uuid)`, `visitor_message(exception)`
    (`ExceptionMessage::forVisitor()`), `profile_link(address)` (`Idno\Templating\ProfileLink`: the icon, class and text of a profile address; the address
    is `fix_url`'s, which never leaves a script address), `embeds(text)` (`Idno\Templating\EmbedFinder`: what a text embeds; the `content/embed` template
    only draws what it finds), `syndication_has(service)`, `syndication_accounts(service)` (helpers: the viewer's services and their accounts) and the
    filters `url_host`, `url_scheme`, `url_basename`, `alnum_only`, `base64_url` (`Webservice::base64UrlEncode()`), `attachment_url`
    (`Config::sanitizeAttachmentURL()`), and `parse_urls(attributes)` (the attribute text a template writes for each link, `rel="nofollow"` on a comment).
    The annotation lists sort in the template with the old comparison (`|sort((a, b) => a.time < b.time ? -1 : 1)`, PHP's `uasort()` as before, so
    equal times come out in the same order); RSVPs are renumbered after the sort, as `usort()` did, so the delete link of an RSVP names its place in
    the list (an old defect, kept). A `|raw` is the HTML an entity's own `draw()` or `drawEdit()` produced, or the page's body (`raw-allowlist.php`).
    On purpose different from the look they replaced (each pinned by a `TemplateEscapingTest` method): `entity/shell` links an
    in-reply-to address only if it is http(s); `content/syndication/links` escapes the identifier and links only http(s); `entity/annotations/rsvps`
    lists an unknown answer as "Other responses" (the old one stopped); `file/picker/image` and `file/picker/donejs` write their words and the file
    address for a script; `entity/User/profile/contact` draws only the schemes it has an icon for. The htmlentities() of the old templates (`é` as
    `&eacute;`) is Twig's escape (`é`): the same text. `Tests/Fixtures/HostilePageController`
    (`/_test/hostile/feed`, `/_test/hostile/hcard`, test environment only) serves another site's page that names itself with a script. The following
    page that fetches a feed cannot run on PHP 8 (`Reader::getFeedDetails()` calls `DOMDocument::loadHTML()` statically), and the template of a feed item
    draws `$item->draw()`, which draws the template again: both are old defects, so those templates are drawn with the objects the pages would give them.
  - The form templates are Twig too (`templates/default/forms/**`: 28 files). Things to know when you change one.
    (1) A control is drawn on the PAGE's template object by a `.tpl.php` page (`$this->__(['name' => ...])->draw('forms/input/text')`), and the old
    control took its variables off that object again (`unset($this->vars[...])`, "prevent bonita pollution"); a Twig file draws its includes on a copy,
    so each top-level control ends with `{% do forget_variables([...]) %}` (a function of the extension, over `PhpTemplateContext::forgetVariables()`:
    it acts on the object the file was drawn on and does nothing on the copy another Twig file made), with the names the original unset
    (`Tests/Templating/FormControlsTest`; without it a spinner took the `id` of an earlier input and an icon its `class`: the golden pages caught it).
    (2) The counter that gives an input its id (`global $input_id`) is the site's one, `next_input_id()` (`TemplateHelpers::nextInputId()`), so the
    `classic` copies and a third-party `.tpl.php` that count on it stay in step. (3) The JSON look documents the form fields of a page
    (`formFields`: `documentFormControl()` writes to the page's template), which a Twig control drawn on a copy cannot do: the controls a JSON request
    can reach (`forms/input/{text,email,url,hidden,password,file,datetime-local,timezones}`, with `input`, `select`, `longtext`, `richtext`,
    `image-file`, `forms/token`, `forms/link`, `forms/action`, `forms/output/{richtext,tags,timezones}` and `forms/usersearch` already there) have
    byte-identical `classic` copies of their originals (`NonHtmlReachTest` names what is reached; `captcha` leaves nothing and is in its list); the
    ones no page of core can reach from JSON (`button`, `submit`, `form-list`, the outputs `text`, `email`, `url`) have none, and a third-party page
    that draws one for JSON loses that field's documentation. (4) `forms/token`, Twig and `classic` alike, prints the three hidden inputs and keeps no `csrf` list: the JSON of a page
    prints every variable of the template, so the token is never recorded there (nothing read the list; a signed API request needs no token and a browser has it
    from the HTML form or `/service/security/csrftoken/`). The token itself is `security_token(action, time)` (`Forms::token()`: the session's one token, the arguments ignored), so the Twig file neither calls `form_token()`
    nor `signForm()`: no recursion, and `form_token()` is `signForm()`, which draws this file. (5) `forms/link` registers its form with `form_data()`:
    in an XHR response (no shell draws the registered forms) the form is printed after the link, which fixes a link in content a script inserts
    (`BuiltInLookTest::testALinkActionInAnXhrResponseCarriesItsForm`); the old template kept it in a global (`$template_postponed_link_actions`) that only the admin user search's page, `Idno\Pages\Search\User`, read and appended to its results. That page draws its rows on the JSON type, whose `forms/link` is the `classic` copy, which still fills the global, so its read stays; the search's script sends `X-Requested-With` so that the page's request is an XHR one and the forms arrive (without the header they never did: `UserSearchActionLinksTest`).
    The address is `|link_url` (`UrlValidator::linkUrl()`), the confirmation is `js_string()` (the same single-quoted JavaScript string, escaped for the
    attribute, as `action_link()` prints), and the label is `|raw` because `Actions::createLink()` callers pass markup (`raw-allowlist.php`); `forms/action` prints its
    `body` the same way. (6) Values printed from a variable are escaped: every attribute of `input` and `select`, a `textarea`'s name, the editor's id
    (`js_string()` in `x-data`, escaped elsewhere), a tag, the user search's id, class, name, address and template (`js_string()`), the label and help of
    `form-list` (`TemplateEscapingTest::testFormValuesAreEscaped`); the `value` and placeholder were escaped before. Differences
    that are not about a value from outside: non-ASCII text in a value is printed as itself, not by `htmlentities()` as named entities; a text area whose
    value is `0` keeps it; `forms/output/url` links only http(s) (`FILTER_SANITIZE_URL` kept `javascript:`); a field of `form-list` that is not required
    has no `required` attribute (the old one printed `required="Array"` and a PHP warning). A boolean attribute (`autofocus`, `checked`, `disabled`, `multiple`, `readonly`, `required`) of `input` and `select` is printed only when set: `false`, `0`, `'0'`, `''` and `null` print none (the old template wrote `required=""`, which a browser reads as set), and any attribute given `false` is not printed; a flag or default the original tested with `empty()` is tested by plain Twig truthiness (`{% if x %}`), never `is empty`, which differs for `0` and `'0'`; a text area's height is `|to_int`. `forms/input/timezones` takes its `class` from the caller as it
    did (the template's own default was assigned to a local variable and never used). Helpers added: `js_string`, `next_input_id`, `security_token`,
    `timezone_options`, `timezone_info`, `email_is_blocked`, `forget_variables` and the filters `link_url`, `email_address` (`FILTER_SANITIZE_EMAIL`) and
  - The account pages are Twig too (`templates/default/account/**`; the onboarding pages that were converted with them are gone: see "Installation"). The
    follow bookmarklet's card (`account/settings/following/mf2user`) prints a REMOTE page's h-card: the name, nickname and addresses are escaped, the
    photo and the profile link go through `|http_url`, a photo with an alt text (the parser gives a list with a `value`) is its `value`, and an email
    loses `mailto:` only when it starts with it (the old template cut the first seven characters of every address). Scripts write the page's address
    and the site's address with `e('js')` (the tools page's request for the API key, the follow bookmarklet) and the profile page's words likewise
    (`TemplateEscapingTest::testTheAccountScriptsWriteTheirValuesForAScript`; `PageNormaliser` reads the site's
    address in that escaped form). The password reset form escapes the email address and the code a request supplied. The tools page prints no secret
    (the API key is fetched by a script, and only whether there is one is read, with `entity_value()`); `account/login` reads the forwarding address the
    page gives it (`Login::sanitizeRedirectUrl()` is applied by the page and its POST, not by the template) and the referrer with `referrer()`, and asks
    `can_add_users()` for the Register link. The JSON of these pages is unchanged: `classic` copies of `account/{menu,login,settings,export}` and
    `account/settings/tools` exist, and the other bodies a JSON request can reach left nothing on the page's template (`NonHtmlReachTest`). The golden pages
    of `account/settings/tools` and `account/settings/following` changed only in how the site's address is written inside their scripts.
    `form_urlencode` (`urlencode()`: a tag link writes a space as `+`, as it always did).
  - The admin pages are Twig too (`templates/default/admin/**`: 25 files). A page that needs a lookup only its templates
    use computes it and passes it as a variable: `Pages\Admin\Plugins` passes `plugins_visible` (the plugins the site does not hide, each with its
    `shortname`, by name: what the page listed after `isVisible()` and `ksort()`), `Themes` passes `themes_with_preview` (the themes with a
    `preview.png`: a template does not touch the file system) and `LegacyTables` passes `restored_message`; the JSON of those pages gains those keys. New
    functions: `system_info()` (`idno`, `build`, `php` and the required `extensions`), `extension_loaded(name)`, `version_compare(a, b)`,
    `can_make_site_private()` and `legacy_tables_in_menu()` (the admin menu's entry for the tables an upgrade left). The settings page and the menu read
    stored settings with `setting()`. The email form shows the stored SMTP user name and password, which `setting()` refuses: `Pages\Admin\Email`
    draws the body on a COPY of the page's template (`withVars()`) that holds exactly those two values, so they are printed once each, in their own fields,
    and are never a variable of the page, which the JSON and JSONP output of a page prints (`TemplateEscapingTest::testTheEmailFormPrintsTheStoredSecretsOnlyInTheirFieldsAndNeverAsAVariableOfThePage`).
    `admin/legacytables/body` escapes every database value by autoescape, as its `$e` did (`AdminLegacyTablesTest` is unchanged). A diagnostics message is
    HTML written by the page and by the listeners of `diagnostics/basics`, so it is the one value printed with `|raw` (`raw-allowlist.php`). Values an
    administrator or a plugin supplied (a plugin's name in a dependency label, statistics labels and values, an invitation's address, a blocked address) are
    escaped, and the diagnostics and logs scripts write the page's address with `e('js')` (`TemplateEscapingTest::testTheAdminPagesEscapeWhatTheyPrint`,
    `testTheAdminScriptsWriteThePagesAddressForAScript`). The homepage settings page no longer draws the icon of each content type and a copy of its form inside HTML
    comments (`testTheHomepageSettingsHoldNoCommentedOutMarkup`; golden `logged-in/admin-homepage.html`). The JSON of these pages is unchanged but for the new keys:
    `classic` copies of the originals of `admin/dependencies` and `admin/statistics/report` exist (their `__()` calls left `version`, `extension`, `report` and
    `level` on the page's template), and the other bodies a JSON request can reach left nothing (`NonHtmlReachTest`). `BuiltInLookTest::testAdminMenuItemsOfBundledPluginsAreListed` pins the
    plugin entries of the menu and their order.
  - The bundled plugins' entity templates (`IdnoPlugins/{Status,Text,Photo,Media,Like,Checkin,Event,StaticPages}/templates/default/entity/**`: how a post is
    drawn in a stream and on its page, its icon, its compose and edit form) are Twig files. A post's text is read as the
    old templates read it: a note's is decoded and escaped (`decode_entities`, which is `html_entity_decode()`; the old `htmlentities(html_entity_decode())`) then
    linked and followed by its tags, with `nl2br`; an article's goes through `forms/output/richtext`; the stored HTML of a photo, media, event, check-in, RSVP
    goes through `sanitize_html` first (the old templates printed it as stored, relying on the save to clean it, so a body a client stored without
    cleaning is no longer markup) and then the linkers in the order the original used. A STATIC PAGE's body is not purified: only administrators write one (`adminGatekeeper()`
    on the plugin's edit page, an editor that takes raw HTML) and the purifier would remove an anchor's `id`, a form, a style block and an embed from a host it does not list, which existing
    pages hold; it is printed as stored with `|raw` (`raw-allowlist.php`), then linked, as the original did (`TemplateEscapingTest::testAStaticPageHoldsTheFormTheAnchorTheStyleAndTheEmbedItsAdministratorWrote`).
    A photo post limits a stream to five pictures only when a page is being served and it is not a permalink (`is_stream_page()`, a `TemplateHelpers` method: false with no current
    page, which is the console and the queue worker's export, where every picture is drawn, as the original drew them: `ExportArchiveTest::testAPhotoPostWithSevenPicturesIsDrawnWithAllSevenInTheArchive`);
    `is_permalink()` is false with no page too, which is what the other templates that test "is a permalink" need. An address from a post (a picture, an audio file, a bookmark, a static page's
    forward address) is printed through `http_url`; what a script holds (the player's file address, the map's element id, the addresses of the bookmark form and of
    the page editor's file picker) is written with `e('js')`; a check-in's coordinates are `?float` (`Checkin::lat()` and `long()` answer null for a stored value that
    is not a finite number, which used to be a `TypeError`), so the map script can only hold numbers. An event's times are read with `to_timestamp` (an
    undated or unreadable time is 0, as `date()` took `false`) and written with Twig's `date`; an RSVP's answer is `ucfirst`. The forms draw each control with
    exactly the attributes it is given: the old templates left the attributes of an earlier control on the page's template, so the hidden `forward-to` field and the
    footer's spinner inherited an editor's class, `required`, placeholder or id (`TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne`).
    The differences from the old templates are each pinned by a `TemplateEscapingTest` method
    (`testThePluginPostsScriptsWriteTheirValuesForAScript`, `testAStaticPagesTitleAndForwardAddressAreEscapedAndItsBodyIsAsWritten`, `testAPhotosPictureAddressThatIsNotHttpIsNotPrinted`,
    `testAStoredBodyIsPurifiedWhenItIsDrawn`; `testABookmarkWithAJavascriptAddressRendersNoLink`, `testACheckinPlaceNameIsEscaped`, `testAnRsvpBodyAndEventAddressAreSanitized`
    and `testACheckinsCoordinatesAreNumbersInTheMapScript` pin what the old templates already did). The golden pages that hold such a post or form changed in exactly those
    lines. `forms/output/richtext` no longer ends with a newline (the original did not), so a post's text is followed directly by what the template closes. The
    media, RSVP and static page edit templates have `classic` copies of their originals, because their `__()` calls (and `drawSyndication()`) leave variables on the
    page's template that the JSON of the edit page prints; the icons leave nothing (`NonHtmlReachTest`, which now also looks at the plugins' entity templates).
  - The bundled plugins' admin, account, menu, head and footer templates (`IdnoPlugins/{ActivityPub,Bridgy,Checkin,IndiePub,Media,StandardSiteSync,StaticPages,Styles,Webhooks}/templates/default/**`,
    27 files) are Twig files. Menu entries and head links keep their places (they are drawn through
    `admin/menu/items`, `account/menu/items`, `shell/head`, `shell/head/final` and `shell/footer`: `BuiltInLookTest::testAdminMenuItemsOfBundledPluginsAreListed`), and a menu entry is
    `path_matches()` and `site_url()`. A lookup only one page's templates need is the page class's: `Pages\Admin` of StaticPages passes `category_counts` (the number of
    pages in each category; the template used to count them). SECRETS are passed to the draw of the body only, on a copy (`withVars()`), never as a variable of the page, because
    the JSON output of a page prints every variable of its template: the webhooks of `Webhooks\Pages\Admin` (the path of a webhook address is its key), the Micropub access
    tokens of `IndiePub\Pages\Account` (a token is printed once, in the hidden field of its revoke form) and the Bridgy key of `Bridgy\Pages\Account`
    (`TemplateEscapingTest::testThePluginPagesPrintTheirSecretsOnlyInTheBodyAndNeverAsAVariableOfThePage`: each is printed once in the page and is absent from `?_t=json`). The Styles head template
    prints only a link to `styles/site/`; the stylesheet an administrator typed is printed only in the text area of its admin page, escaped
    (`testThePluginPagesEscapeWhatTheyPrint`). The StandardSiteSync head link prints the recorded `at://` address escaped and not through `http_url`, which would blank it
    (a `<link>` runs nothing). The pages of ActivityPub, Bridgy, StaticPages, Styles, StandardSiteSync, Webhooks and IndiePub have `classic` copies already (the Bootstrap-era look),
    so a JSON request draws those; the others a non-HTML request can reach leave nothing on the page's template (`NonHtmlReachTest`, whose pattern now covers every `default` file of
    the plugins). Differences from the old templates, on purpose, each pinned in `TemplateEscapingTest`: the scripts of the Bridgy, static pages and custom CSS pages
    write their addresses and label with `e('js')`; the Bridgy key is escaped; the IndiePub account page and the authorization page's Cancel link print only http(s) addresses;
    `TemplateDrawCasesTest` enables Styles (with a stylesheet) and StandardSiteSync
    in its site (they are not on in the rig or in CI), so that their templates are drawn.
- `Idno\Core\Bonita\Main::additionalPath()`, `getPaths()` (the cascade's bases, then the empty `Main::$path`) and
  `getAdditionalPaths()` are a facade over the cascade. `Idno\Core\HybridTwigTemplate` is a class alias of `DefaultTemplate`
  (a `template` setting naming it builds `DefaultTemplate`); device template types, `Main::$cache`, `Bonita\Templates::drawList()`, `drawObject()` (its per-class lookup
  `object/<Class>` never matched) and `process()`, `Bonita\Forms::draw()` and `setTarget()`, and the `BonDrawable` interface file (never loaded) are gone:
  nothing called them, and `docs/install/upgrade.md` says what replaces each. A `_t` request value that is not a string asks for no type.
- `Idno\Core\Purifier` keeps HTMLPurifier's serialised definitions in `<kernel cache dir>/htmlpurifier`, created on demand
  (never the upload path; with no writable directory the definition cache is off).
  `Purifier::purify()` keeps nothing: no output in the cache pool (a private post's body would stay on disk) and no memo (the site's template object, which holds the
  purifier, lives as long as the container: in the queue worker, for many messages). `Tests/Core/PurifierKeepsNothingTest`.
- No template sends an HTTP header, and a template draw leaves nothing on the site's template object. Headers: `shell/headers` has no core file
  but the shells (`shell`, `admin-shell`, `account-shell`) still draw it, so the NAME is an extension point (a plugin extension that calls `header()`
  still runs); The headers of every template type are declared in one table, `Idno\Templating\ShellHeaders::for($type, $status, $canonical, $settingsShellAsJsonp)`
  (static and pure: a list of `[name, value]` in the order they are sent; the type compared in lower case; `default`, `json`, `jsonp`, `rss`,
  `kml`, `jsonfeed`, `activitypub` and `markdown` have headers, any other type none; the Markdown `Link: <canonical>; rel="canonical"` only at status
  200 and for an http(s) address with no `<`, `>` or white space; a settings page asked for as JSONP, which is drawn as HTML, is `text/html; charset=utf-8`
  and `nosniff`; `ShellHeaders::replaces()` is false for `Link` only, which is added), which the type shells no longer send themselves (they were the
  json, jsonp, rss, kml, jsonfeed and activitypub shells); `DefaultTemplate::shellHeaders($status)` gives the list for the template's type and
  `DefaultTemplate::sendShellHeaders()` sends it (`DefaultTemplate::renderPage($status, $shell)` is `drawPage(false)` with the list returned and nothing sent, for
  `PageRenderer`), called
  by `drawPage()` and by the two pages that draw the shell with `draw('shell')` (`Account\Register`, `Account\Password`);
  the simple shell (`shell/simple`) and `DefaultTemplate::sendSimpleShellHeaders()` are gone with the onboarding pages; `Discovery\SiteFiles` (a service, not `SiteFilesController`) sends `robots.txt` as `text/plain`; `chrome/manifest` is no template: it builds the JSON and answers
  `application/json`, so a theme's override of it no longer applies. A new template must not call `header()`: `Tests/Unit/Templating/ShellHeadersTest::testNoCoreOrBundledTemplateCallsHeader` fails for a core or bundled template that does (it scans every `.php` file, `.tpl.php` and plain, by tokens, `\header(` included, and every `.twig` file; a third party's `.tpl.php` shell that still calls it keeps working). Header values are unchanged
  (`Tests/Characterisation/PageHeadersTest`; `Tests/Kernel/ShellHeadersDrawTest` pins `DefaultTemplate::shellHeaders()` per type and status). Two behaviours changed with the table: `drawPageWithoutSideEffects()`
  no longer sends the two HTML headers (`text/html; charset=utf-8`, `nosniff`) for JSONP on an `/admin` or `/account` page (it sends no header at all, as it says; the
  private flag that makes the JSONP settings shell HTML is reset when that draw ends), and a third-party `json` or `jsonp` shell that calls no `header()` itself now gets core's headers for its type
  (one that does call `header()` still wins, its call runs later). `Bonita\Templates::__()` writes template variables only, whatever their names (a variable named
  `templateType`, `vars` or `fallbackToDefault` no longer sets the object's own property of that name: use `setTemplateType()`;
  `TemplateContractTest::testUnderscoreUnderscoreNeverSetsTheObjectsOwnProperties`); `Actions::createLink()` and `createForm()` and
  `DefaultTemplate::drawSyndication()` and `drawPagination()` draw on a `clone`, so their variables stay off the site's template object
  except the keys a page's JSON output has, which they publish by explicit assignment: `items_per_page` (pagination) and `services` (the details the
  syndication template computes), `content_type` and `posseLinks` (`Tests/Core/ActionsTest`). `signForm()` is not changed (it draws `forms/token` on the site's template). `forms/token` keeps no `csrf` list in either look, and the JSON and JSONP shells (`templates/json/shell.tpl.php`, `templates/jsonp/shell.tpl.php`) pass their variables through `FormToken::scrub()` (a top-level `csrf` key, and at any depth of an array every entry holding the token as stored, are removed) as a second guard. `createForm()` now passes the address it is given as the form's `action` (it never did, and the template needs it).
- Idno 2026, the built-in look (`templates/default/`, `IdnoPlugins/<P>/templates/default/`, `css/idno-2026.min.css`, `js/idno-2026.min.js`, and
  `Idno\Pages\Drafts`, the member's drafts page, a legacy page registered in `Idno::init()`), was the Twenty26 theme: its templates were in a
  `modern` type that the theme's controller set. It is core's: a site's template type is `default`, `Themes::resolve()` maps the stored `theme`
  `Twenty26` and every retired name to no theme, and `Themes/` holds third-party themes only (a theme's `default/` templates override core's,
  its `theme.ini` `[extensions]` apply, as before). The sources of the style sheet and script are in `frontend/` (not served); the built files
  are committed, and `npm --prefix frontend run build` writes them from Tailwind's scan of `templates/default/` and the bundled plugins'
  `default/` templates (a rebuild is not byte-identical to the committed files: a newer terser, and the old build scanned its own output;
  it drops 17 unused utilities and adds `col-12`, `contents` and `sr-only` from templates that are not Idno 2026's). Every file of `default`, `email` and `email-text` in core
  and the bundled plugins is a Twig file (`CoreTemplatesAreTwigTest`); the `.tpl.php` files that were beside them are deleted, and the `.tpl.php` engine stays for the `classic`
  and type-shell templates and for third-party plugins and themes.
  No jQuery in the built-in look. The look loads one script, `js/idno-2026.min.js` (Alpine.js, Tiptap, and the modules in `frontend/src/js/modules/`, among them
  the @-mention autocomplete), and no jQuery or plugin of it (the onboarding shell, the last page that loaded jQuery, is gone; the pages of the installation are not
  templates and load nothing). `Tests/Unit/Templating/NoJqueryInTheBuiltInLookTest` fails for a `templates/default/**/*.html.twig` file of core or a bundled plugin that calls `$(`, `$.`, `jQuery`, `mediaelementplayer`,
  `tinymce` or `.modal(`/`.tooltip(`/`.popover(` (Alpine's `$el`, `$refs`, `$dispatch`, `${...}` and comments are not calls); a small behaviour is an inline script in plain DOM
  JavaScript or Alpine attributes in the template, not a change to `frontend/src` (a rebuild is not byte-identical). What the inline scripts of the look do:
  `entity/edit/footer` (every edit form: a second submit of a form is ignored while the first is sending, without disabling the buttons, which would drop the
  submit button's own `publish_status`; an empty required rich-text field takes the cursor), `admin/diagnostics` (fetches the page with `X-Requested-With: XMLHttpRequest`,
  which answers the report as text, into `#diagnostics-report-text`), `following/home` (a `<details id="follow-enclosure">`, no script) and the static page's
  advanced options (Alpine). Media posts play in the browser's own `<audio controls>` / `<video controls>` (no waveform, no MediaElement.js, no WaveSurfer: the plugin's
  `external/wavesurfer/` and its `shell/head` and `shell/footer` extensions are deleted); static pages are edited as HTML source in a plain `<textarea name="body">`,
  because the body is HTML the administrator wrote (forms, ids, `<style>`, frames) that Tiptap, which only keeps the markup its schema knows, would rewrite on the first
  edit; a browser submits that field's line breaks as CRLF. The `assets` setting names templates under `assets/` that every page draws; none ships (`mediaelementplayer`
  and `fitvids`, jQuery plugins, are deleted, with their Composer packages `npm-asset/mediaelement`, `npm-asset/vanilla-fitvids`, `npm-asset/exif-js`, `npm-asset/tinymce--tinymce-jquery` and `tinymce/tinymce`), and a stored `assets` entry with no template draws nothing. `IdnoPlugins\Photo` no longer registers the `exif-js` asset.
  The page-level checks are in `Tests/Characterisation/BuiltInLookScriptsTest`.
  The Bootstrap-era `css/idno.css`, `css/idno.min.css` (and their SCSS sources) `js/modernizr/`, `js/idno.js` and `js/idno.min.js` (with their sources `js/src/classes/`, `js/src/lib/` and `js/src/Idno.js`) were deleted when nothing drew them. The Grunt build
  (`Gruntfile.js`, `npm run grunt`) remains for the service worker (`build-js`), the language
  catalogues (`build-lang`) and the JavaScript linter (`test`); it builds no style sheet; it does not build Idno 2026's files, which the `frontend/` build writes. The guide for plugin and theme authors,
  `docs/developers/plugins/upgrading.md` (what still works, what a `.tpl.php` template that a Twig template draws now gets, how to convert one), is pinned
  by `Tests/Kernel/UpgradingGuideTest` (its before and after draw the same markup; the example blocks are read from the guide); `.tpl.php` support ends in 3.0.
- Tests: `Tests/Unit/Templating/` (`TemplateNameTest`, `TypeChainTest`, `ClassicTemplatesTest`, `TemplateLocatorTest`, `SlotRegistryTest`,
  `TemplateRendererTest`, `CascadeTwigLoaderTest`, `IdnoTwigExtensionTest`), `Tests/Kernel/BuiltInLookTest`, `ClassicEscapingCoverageTest` (which escaping tests also run on the `classic` template a feed draws, through the rss chain), `StaticAssetsTest` (in the unit tier), `UpgradingGuideTest`, `TemplateServicesTest`, `TemplateCachesTest` (nothing under the upload path;
  Twig's cache is in the kernel's cache directory), and the
  contract and golden pages named under "Build & Test".

#### Markdown output

- Which pages stay out of Markdown is decided from how the page's route was registered: `PageHandler` records the page classes registered under a pattern starting `/?(account|admin)(/|$)` (`addRoute()`/`hijackRoute()`, `isSettingsPage($class)`; `isAccountPage()` stays for `/account/` only, which closes it to API requests), so `//admin/email` and a request seen as https on an http site are settings pages as `/admin/email` is. An explicit `_t` that is not Markdown wins over the `.md` suffix (`/permalink.md?_t=json` is JSON). On a page that is not a permalink the canonical `Link` header and the front matter `link` are the page's address WITHOUT its query string (`DefaultTemplate::markdownPageAddress()`): a reset code or an email address in the address is not echoed.

Every page but a settings page can be fetched as Markdown, and a post's permalink in three ways (proposed by John Evdemon, idno/idno#3407, whose
standalone implementation, https://github.com/jevdemon/idno-markdown, was the reference for the behaviour of permalinks). The pieces:

- A permalink served by a controller (`#[SitePage(markdownPermalink: true)]`): the router matches the `.md` form with the route (a free last parameter `[^/]+`
  takes `abc.md`), `PageRequestListener` takes the suffix off that parameter and sets `PageContext::markdownSuffixRequested()`, and `LegacyPageLifecycle::begin()`
  selects the `markdown` type first, as `selectMarkdownIfWanted()` did (an explicit other `_t` wins); the controller then checks the viewer and draws as a
  permalink page does. A permalink the legacy router still serves (a shell, or a plugin's `MarkdownPermalink`) keeps its `.md` form: `LegacyRouteOverride` hands the request
  to the legacy router when a claim serves it as Markdown and the controller is not marked, or the claim is a plugin's.
- Asking ANY page: `?_t=markdown`, or `?_t=md` (`TypeChain::requested()` is the one place the short name is read: `Page::init()`
  and `DefaultTemplate::autodetectTemplateType()` pass a `_t` value through it). `markdown` is an ordinary requestable template
  type (`TypeChain`: `markdown`, `classic`, `default`; `.tpl.php` only), so the page runs as it does for any type, with its own
  gatekeepers, and its shell is `templates/markdown/shell.tpl.php`.
- Asking a PERMALINK, three more ways: the suffix `.md` on its path, `?format=markdown`, or an `Accept` header that names
  `text/markdown` with a quality above 0 that no other range of the header exceeds (`Idno\Markdown\MarkdownRequest`, pure:
  `text/markdown, text/html` is Markdown, a tie going to the type the client named; `text/html, text/markdown;q=0.9`, `*/*`,
  `text/*` and a browser's header are HTML; a header over 2000 bytes is not read). The suffix and the query values ask whatever
  the header says; a request that names another `_t` or `format` has chosen, and the header is not asked. Only the two permalink
  pages ask these (`Idno\Pages\Entity\View`, `IdnoPlugins\StaticPages\Pages\View`): on any other page `?format=markdown` and the
  header change nothing and `.md` is no address (`MarkdownOutputTest::testOnlyAPermalinkAnswersTheSuffixTheFormatAndTheAcceptHeader`).
- A permalink page calls `Page::sendVaryAccept()` and `Page::selectMarkdownIfWanted()` FIRST in `getContent()` (the type is
  selected when `Page::wantsMarkdown()`), then makes its own checks (the entity exists, `canRead()`, the static page's
  forward), then `Page::drawMarkdown($object)` when `wantsMarkdown()`. So a private post, a draft and a missing post answer with
  the STATUS the HTML permalink has for that viewer (410, 403), as a Markdown document of that error page (its title and text),
  and never with anything of the post: `drawMarkdown()` is the only thing that names the entity to the shell (the template
  variable `markdown_entity`), and it is called only after the checks. A third-party permalink page does the same three calls and
  implements `MarkdownPermalink` (next bullet).
- The `.md` suffix is handled in ONE place, `PageHandler::serve()` (`routeMarkdownSuffix()`), before Toro routes: for a GET or
  HEAD whose path ends in `.md` (lower case, after a non-empty last segment), when the path AS GIVEN matches no route and the
  path without the suffix is routed to a page class that implements the marker interface `Idno\Common\MarkdownPermalink`, it
  sets `$_SERVER['PATH_INFO']` (which Toro reads first) to the path without the suffix and remembers the request
  (`PageHandler::markdownSuffixRequested()`). Anything else is left exactly as it was, so no address that was served changes
  its meaning: `index.md`, `session/login.md`, `<permalink>.md.md`, `<permalink>.MD`, `<permalink>.md/` and a POST are the 404
  they were. The older permalink routes take anything after the entity's id (`/entry/:id/.*`, `/bookmark/`, `/event/`, `/rsvp/`),
  so a path ending in `.md` matches them AS GIVEN: when the page of the route that matches as given is a `MarkdownPermalink`,
  the request is remembered as a Markdown one too and the path is routed unchanged (`/entry/<id>/title.md` is the post, as
  Markdown; before, it was the same post as HTML). The other address that changes: `<permalink route>/<no such slug>.md` was a
  404 (no route) and is now what the permalink of a missing post is (410, as Markdown). `PageHandler::handlerFor()` is the
  router's own lookup, written again (Toro's is inline in `serve()`): `Tests/Unit/Markdown/RouteLookupTest` serves a table of
  patterns and paths through `Toro::serve()` and fails if the two ever disagree. `REQUEST_URI` keeps the suffix (`Page::currentUrl()`); slug routes still match no dot.
- The headers are `ShellHeaders`'s `markdown` list (`DefaultTemplate::sendMarkdownHeaders()` sends it alone, for a caller of its own; `sendShellHeaders()` sends it, so every `drawPage()` of the
  type; no template sends one): `Content-Type: text/markdown; charset=utf-8`, `X-Content-Type-Options: nosniff`,
  `Access-Control-Allow-Origin: *` (as every web page has), and, only when the status is 200, `Link: <address>; rel="canonical"`: the entity's `getURL()` on its permalink,
  else the page's own address without `_t` (only an http(s) address with no `<`, `>` or white space). `Vary: Accept` is on
  EVERY answer of the two permalink pages (the HTML page, the Markdown, the 403 and 410, a HEAD and a 304) and on no other page,
  whose answer does not depend on the header.
- What the shell writes, always YAML front matter first:
  - a permalink (`markdown_entity`): the field names of an RSS 2.0 item, in this order: `title` (as an RSS item's: the
    entity's title, else five words of its description, else `New <type>`), `author` (the owner's name; left out with no owner),
    `pubDate` (`DATE_RSS` of `created`), `description` (the `short_description`, else a sample of the description with tags
    replaced by spaces; left out when empty or equal to the title), `link` (`getURL()`), `guid` (the uuid) and `category` (a
    list: `getTags()` without `#`, no duplicates; left out when empty); then the content: an `In reply to` line for each http(s)
    `inreplyto`, and the entity's own HTML, `$object->draw(true)` (the feed view: `entity/<Class>` of the `markdown` type, else the
    `classic` file a feed draws), through `sanitize_html()` and `HtmlToMarkdown`. No `# title` heading: the title is in the
    front matter;
  - a page that lists posts (the template variable `items` holds entities and the status is 200: the home page, a profile, a
    tag, a search, `content/all`): `title` (the page's, else the site's), `description` (the page's; left out when empty or
    the title) and `link` (the page's address without `_t`); then `# title`, and for each item `## [title](permalink)`, a line
    `*author, pubDate*` and its content as above (each item in full, as in the RSS feed; the list is the page's own, so it
    holds what this viewer may read), and last a rule and `Newer` / `Older` links to the neighbouring pages of the list
    (`offset`, `count` and the `items_per_page` setting), which keep `_t`. The page's `body` is not used;
  - a SETTINGS page (`DefaultTemplate::isSettingsPage()`: an address under a root that has a shell of its own, `admin` and
    `account`, or a page class registered for a route under `/account/`): `title`, `description`, `link` and ONE line saying the
    page has no Markdown form. Its `body` is NEVER printed, for anyone: these pages hold stored secrets, and although the
    converter removes forms and their fields, the `classic` templates this type falls back to print some in their TEXT (the
    IndiePub account page printed each access token: found by
    `MarkdownOutputTest::testTheSettingsPagesAsMarkdownHoldNoSecret`, which stores an SMTP user name and password, API keys,
    access tokens, Bridgy keys, a webhook address and a stylesheet and asks for the email, tools, IndiePub, Bridgy, webhooks,
    styles, settings and admin pages as the administrator, a member and a visitor). The registration and password pages are
    under `/account/` and answer the same line. Keep a page that shows a secret under `/admin/` or `/account/`;
  - any other page (a form, an error page of any status): `title`, `description`, `link` and the page's `body`, converted. The
    converter removes forms and their controls, so no field value and no form token is in the document. The page's own access
    rules apply as for HTML;
  - NOT Markdown: the pages of the installation and of the setup step (`Idno\Install\InstallPage`: self-contained HTML, answered
    before any page class runs), whatever type is asked for. The account forms have no Markdown form
    (`MarkdownOutputTest::testTheAccountFormsHaveNoMarkdownForm`).
  Bundled types with a `markdown` entity template of their own, because the `classic` feed view lacks what matters or draws
  what must not be there: Media (a link to each file, labelled Audio or Video), Like (`Bookmarked:`, `Liked:` or `Reposted:`
  and a link to the target, only if http(s)), Checkin (the place name and the coordinates as the viewer may see them,
  `canSeePreciseLocation()`, linked to OpenStreetMap) and StaticPage (the body alone: the `classic` one draws the
  administrator's controls). Status, Reply, Entry, Photo, Event and RSVP draw their `classic` templates. These templates print
  HTML (escaped with `htmlspecialchars()`, addresses through `UrlValidator::httpUrl()`), never Markdown: the converter is the
  one place Markdown is made, and the shell builds its own headings and links as HTML for it too.
  `templates/markdown/forms/output/tags.tpl.php` draws nothing, so no entity template's row of tag links is in a document.
- Never put a secret in the Markdown shell from a template variable: unlike the JSON shell it prints only the title, the
  description and the converted body, and it must stay so.
- `Idno\Markdown\FrontMatter::render()` (pure) writes every value as a YAML DOUBLE-QUOTED scalar on one line, whatever it holds
  (`\\`, `\"`, `\n`, `\r`, `\t`, `\0`, `\xNN` for the other C0 and C1 characters, `\L`, `\P`, `\uFEFF`; bytes that are not UTF-8
  replaced), so a value is never a comment, a list, a mapping, another key or the end of the block; a key must match
  `[A-Za-z][A-Za-z0-9_]*`; null and an empty list are left out. The shell also reduces each value to one line of text first.
  `Tests/Unit/Markdown/FrontMatterTest` reads the block back with symfony/yaml (a development dependency, for the tests only).
- `Idno\Markdown\HtmlToMarkdown` (pure; built with the site's address) parses the HTML with `Dom\HTMLDocument`, removes
  `script`, `style`, `iframe`, `noscript`, `template`, `object`, `embed`, forms and their controls, `svg`, `canvas`, `map`,
  `head`, `title`, `link` and `meta` with what they hold, turns an `audio` or `video` element into a link to each of its files,
  makes addresses absolute (`/x` against the site's origin, `x` against the site's address, `//host` with the site's scheme),
  keeps a link only if it is http, https or mailto (else its text) and a picture only if it is http or https (else its
  alternative text), and gives the rest to `league/html-to-markdown` (ATX headings, unknown elements stripped to their text,
  tables converted; text is escaped by the library, `<` as `&lt;`, so no HTML element can come out of text), then removes the
  white space the HTML's layout left where it means nothing in Markdown (never inside a fenced code block). No HTML element
  is ever in the output.
- Tests: `Tests/Unit/Markdown/` (the three classes, without the legacy application), `Tests/Templating/MarkdownOutputTest`
  (over HTTP on a seeded private site: the ways into a permalink, the headers, the Accept table, a private post and a draft for
  a visitor, another member and the owner, a missing post, any page by `?_t=`, the lists and their pages, what only a permalink
  answers, each bundled content type, hostile values), `TemplateTypeRequestTest` and the golden pages `non-html/markdown-*.md`
  (each content type's permalink, the home page and its second page, a profile, a tag, a search, the login page, a 404 and a
  private post for a visitor; and for the logged-in administrator a post, a private post, the home page and the admin page: the
  owner's document has none of the owner's controls). `markdown` is one of `FallbackTrace::TYPES`, so `NonHtmlReachTest` holds
  it to the rule of the other non-HTML types (no `default` file is reached but a listed page body) and
  `fixtures/fallback-trace.php` pins the `classic` files its pages draw.

#### The cache

Idno has ONE cache: `Psr\Cache\CacheItemPoolInterface` (aliased as `Symfony\Contracts\Cache\CacheInterface`), a public
container service that replaces FrameworkBundle's aliases for those two interfaces (the framework's own pools stay
under their ids, such as `cache.app`). It is built at runtime, when first fetched, by
`LegacyServiceFactory::cachePool()` through `Idno\Cache\CachePoolFactory::create(array $bootConfig, string $cacheBase,
string $host)` (a static helper in `Idno/Cache/`, which is neither scanned nor in `CodeFingerprint::SERVICE_DIRECTORIES`
because nothing in it is compiled into the container): a `FilesystemAdapter` with namespace `md5($host)` in the first
usable of the boot configuration's `cachepath`; `<cache base>/pool`; the system temporary directory
(`CachePoolFactory::directory()` decides). The cache base is what `CacheDirResolver::fromEnvironment()` resolves
(`IDNO_CACHE_DIR`, else `var/cache`, else the private temporary directory): the compiled container's base, WITHOUT the
per-environment, per-build directory, so a deploy or a version change never empties the pool. A candidate inside the
local `uploadpath` is skipped: a cache never goes in the uploads. Nothing is compiled into the container. Two processes
(web workers, the queue worker) share the pool ONLY when they run as the same Unix user (the namespace directory is
created with the process's umask, and a process that did not create it may not be able to write in it) and the
request's host equals the host of the `url` (the namespace is the host's hash; the worker has no request, so it
takes the `url`'s). `FilesystemAdapter` is safe for sharing between processes that can write (pinned in
`Tests/Unit/Cache/CachePoolFactoryTest`). Otherwise the processes have pools of their own, or one cannot write
the other's: a save that cannot write fails silently and a read misses, so the HSTS memory (and any plugin's
cached value) is lost, and no exception reaches the outbound request that triggered it (pinned by
`Tests/Kernel/WebserviceHstsTest::testAPoolThePathCannotWriteIsAMissAndNeverAnErrorForAnOutboundRequest`). Run the
worker as the web server's user.

- Directories. The pool's files are unserialized, so a directory another local user could write must never be
  used. `cachepath` and `<cache base>/pool` are trusted exactly as far as `CacheDirResolver` trusts the cache base
  (it includes the compiled container from there): they need only be writable (checked: it does not require
  more of `var/cache`, so one that is group-writable for a deploy user keeps working). The last resort,
  `<system temporary directory>/idno-pool-<12 hex of sha1(cache base)>`, is REQUIRED to pass
  `CacheDirResolver::usableAndPrivate()` (created 0700; owned by the current user; not a link; not writable by
  group or others); the pool's call passes `$requireOwner`, so without a way to learn the current user's id (no
  ext-posix) the directory is refused rather than accepted on its mode alone, while the kernel's own use of the
  method (its temp-dir fallback for the compiled container) stays lenient, so a site without ext-posix still boots.
  When no candidate is usable, `CachePoolFactory::create()` returns a `NullAdapter`: the cache
  is disabled (`load()` is null, `store()` succeeds and keeps nothing) and `LegacyServiceFactory::warnWhenDisabled()`
  logs one warning when the pool is first built. Two installs with the same host name on one cache base share a
  pool; give one a `cachepath`.
- `Idno::site()->cache()` is `Idno\Caching\PersistentCache` (final, legacy-side API for third-party plugins; new code
  injects the pool): `load($key)` (null on a miss), `store($key, $value, ?int $ttl = null)`, `delete($key)`,
  `ArrayAccess` and magic properties, over the pool. Any string is a key: PSR-6 refuses `{}()/\@:` and has a length
  limit, so a key that is not 1 to 64 of `[A-Za-z0-9_.-]` (or starts `_h_`) is replaced by `_h_` + its SHA-256. The
  pool is a closure, fetched at first use: `cache()` runs in `Idno::init()`, before the logger, the session and the
  language exist, and the order the legacy services are built in is pinned.
- The old `Idno\Caching\FilesystemCache`, `APCuCache`, `ArrayCache`, `StaticArrayCache` and `EphemeralCache` and the
  `cache` setting are gone. A site that still has a `cache` setting gets ONE warning, written the first time the
  cache is used (the logger does not exist when the adapter is built), and the setting is ignored. Entries the old
  `FilesystemCache` wrote under `uploadpath` are neither read nor deleted.
- `store()` also prunes the pool (`prune()`, expired files) in about one call in 500 (`random_int`, a seam in the
  constructor), so entries of hosts never asked again do not pile up. A stored `false`, `0`, `''`, `[]` come back
  as stored; a stored `null` is a miss.
- HSTS state comes only from the header block of the FINAL response of a `Webservice` request, only when its URL
  is https, and is recorded under THAT URL's host (RFC 6797): a header on a redirect hop, on a plain-http
  response, or from another host changes nothing. A header with no valid `max-age` is ignored; only an explicit
  `max-age=0` removes the entry. The state is kept, read and deleted under the LOWER-CASED host; a header from an
  IP literal (IPv4, or bracketed IPv6) is ignored (RFC 6797, 8.1). A known host's request is upgraded by replacing
  only the URL's own scheme (anchored, case-insensitive), never an `http://` inside the path or query.
  `isHSTS()` treats a stored value that is not an array with `stored_ts` and
  `max-age` as no entry and deletes it.
- The only thing Idno keeps in the cache is per-host HSTS state (`Webservice::isHSTS()` and `checkForHSTSHeader()`),
  keyed by the bare host name, the value `serialize(['stored_ts', 'max-age', 'includeSubDomains'])` as before. The
  entry is stored with a pool TTL of its `max-age` (at most a year), and `isHSTS()` still compares `stored_ts + max-age`
  on every read (an entry that outlives its max-age upgrades nothing). A header with `max-age=0` deletes the host's
  entry (RFC 6797, 6.1.1; before, it stored an entry that was valid for the rest of the second it was stored in).
  A known host's valid entry is otherwise not rewritten.
- Tests: `Tests/Unit/Cache/` (`CachePoolFactoryTest`, `PersistentCacheTest`, on a `MockClock` and temporary
  directories), `Tests/Kernel/CacheAdapterTest` (the container's pool, the adapter, the ignored setting, the
  disabled cache), `Tests/Kernel/WebserviceHstsTest` (what a response may teach), `Tests/API/HSTSTest`.

#### Translation

`Idno\Core\Language` (`Idno::site()->language()`) translates from gettext catalogues without the gettext
extension: `ext-gettext` is not required, no gettext function (`gettext`, `dgettext`, `ngettext`,
`bindtextdomain`, `textdomain`, ...) is called anywhere, and `Language` calls neither `putenv()` nor
`setlocale()`, so the process's locale is never changed (`Tests/Unit/Translation/NoGettextTest` finds a gettext
call by tokens (it does not see `call_user_func('gettext')` and the like, which a second check of string literals does, nor a `setlocale()` or `putenv()` call in the translation code, which a third forbids) in `Idno/`, `IdnoPlugins/`, `ConsolePlugins/`, `Themes/` and `templates/`, and reports a bare `_()`, which is
PHP's alias of `gettext()`, as well; `Idno\Core\Language::_()` is a method and is not one). Nothing in the repository
depended on the process locale: the one `strftime()` (IndiePub's account template) formats digits only. Code that
needs a locale sets it itself.

- `Idno\Translation\CatalogueLoader::load($directory, $domain, $locale)` (built with new; `Idno/Translation/` is NOT in
  `CodeFingerprint::SERVICE_DIRECTORIES` and `config/` references nothing in it: it is a helper of the legacy
  `Language`, so it is not a service; it depends on neither `Idno\Legacy` nor `Idno::site()`) reads
  `<directory>/<locale>/LC_MESSAGES/<domain>.po`, else `.mo`, into source string => translation, through
  `symfony/translation`'s `PoFileLoader` and `MoFileLoader` (no extension, no parser of our own). Which locale directory: the exact locale
  (`fr_CA`), then the bare language (`fr`), then the FIRST directory of that catalogue named `<language>_*` in sorted order
  that has the domain (`fr_FR`), so a visitor asking for `fr` or `fr_CA` gets `fr_FR` when that is all there is (a deliberate
  improvement over gettext). Those directory names come from the catalogue's own directory, each is checked against
  `LocaleNegotiator::PATTERN` before use, and the listing is made once per (directory, language) for the process;
  `has()` and `load()` use the same lookup. A missing directory or file is `[]`; an empty translation, a fuzzy entry and the
  header are left out. Plural entries are NOT supported (nothing in Idno has a plural lookup): Symfony's loaders store the forms of a
  plural entry joined with `|` under `singular|plural`, and nothing splits them, so a plural entry is shown with its forms joined
  by a bar if it is ever looked up by that key. A `.po` file that holds a `msgid_plural` line (a cheap match on the text) gets ONE warning
  per file per process saying so; a `.mo` is not scanned; a bar in an ordinary string is untouched. A file that cannot be read (bad magic, over 16 MiB, ...) never throws: `[]`
  and ONE warning that names the file as `<locale>/LC_MESSAGES/<file>` (never the server's path). The locale and the domain
  (`[A-Za-z0-9_-]{1,64}`) are validated (patterns end in `\z`, which `$` does not: `$` matches before a trailing newline)
  BEFORE a path is built from them. A catalogue is read the first time it is looked
  up and kept for the process (a static array keyed by the file, its size and its modification time;
  `CatalogueLoader::forget()` for tests); `has()` only looks for the file. Parsing 2,000 strings takes about 2 ms, so there is no disk cache; do not add one without a
  measurement.
- `Idno\Translation\LocaleNegotiator` (pure): `fromAcceptLanguage($header, $default = 'en_US')` takes the header up to
  its first comma (ignored when over 1000 bytes), removes `;q=`, an encoding (`.UTF-8`) and a modifier (`@latin`), turns `-` into
  `_`, lower-cases the language, upper-cases a two-letter region (`fr-fr` is `fr_FR`), title-cases a four-letter script
  (`zh-hant` is `zh_Hant`) and keeps any other subtag as given (`normalise()` is that rule for one value; an explicit locale
  given to `Language` and the console's environment go through it too); `fromEnvironment($env, $default)` reads
  `LANGUAGE` (its first entry) then `LANG` without `.encoding` or `@modifier`. The result matches
  `/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*\z/` (at most 35 bytes) or is the default (`*`, `C`, `POSIX`, a header
  with a line break or `../`, a 300-byte value). The locale comes from a request header, so this is what keeps it out of a path.
- `Language::__construct($language = null)`: an explicit locale, else the logged-in user's `language` attribute (nothing
  in Idno sets it; the early `return` that left the object half built is gone), else the browser's (the console's, under
  `IDNO_CONSOLE`), else `en_US`; always through `LocaleNegotiator::normalise()`. `_()`, `esc_()`, `get()`,
  `getTranslation()`, `register()`, `getLanguage()` and `detectBrowserLanguage()` keep their signatures; lookup is the same: registration order, the first translation
  whose string is non-empty and differs from the key, else the key. `register()` keeps a translation only if `canProvide()` is true for the locale.
- `Idno\Core\GetTextTranslation($domain = 'idno', $path = '')` keeps its name for third-party plugins: `canProvide($locale)` is
  true when `CatalogueLoader::has()` finds a catalogue for the locale (an `en_US` request reads a file only where
  one exists for it, as `IdnoPlugins/Status/languages/en_US/` does), and the catalogue is read at the first `getString()`, once per process. The core registration (`Idno::init()`) is domain `idno`, path
  `<root>/languages/`, matching `build-lang core`, which writes `languages/source/idno.pot` (`build-lang` and `PotGenerator` are
  unchanged). Plugins register `new GetTextTranslation('<plugin>', __DIR__ . '/languages/')`. `ArrayKeyTranslation` subclasses work as before.
- `Idno\Storage\QuoteNormaliser::normalise()` (static, pure, in `$excludes`) is what `Language::uncurlQuotes()` used to
  hold, byte for byte (the search text of every record goes through it); the record store factory and the MongoDB
  import call it directly.
- `symfony/translation` is a dependency for its loaders only. FrameworkBundle would enable its own translator once the
  package is there, so `config/packages/framework.php` switches `translator` off (`Idno\Core\Language` is the site's translator), pinned by `Tests/Kernel/TranslatorDisabledTest`.
- Tests: `Tests/Unit/Translation/` (`LocaleNegotiatorTest`, `CatalogueLoaderTest` with the fixtures in `fixtures/`, `NoGettextTest`),
  `Tests/Unit/Storage/QuoteNormaliserTest`, `Tests/Kernel/LanguageAdapterTest`, `Tests/Kernel/TranslatorDisabledTest`,
  `Tests/Characterisation/TranslationTest` (`Accept-Language` `fr-FR`, `fr`, `fr-fr` and `fr-CA` draw IdnoPlugins/Event's French string) and `Tests/Core/LanguageTest`.

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
  streams the range's bytes (both positions inclusive) to the output 8 KiB at a time with `View::streamRange()` (a range of any size is never held in memory: `Tests/Pages/File/LargeRangeStreamingTest`, 64 MiB under `memory_limit=32M`), which stops at the end of the stream or on an empty or
  failed read (a stream shorter than the stored size never makes the page wait). Both it and `streamAll()` (the
  whole file), when they echo, first end every active output buffer with `View::endOutputBuffers()` (flushing
  what was buffered, so earlier output keeps its place), which the store's `passThroughBytes()` also uses: with
  `output_buffering` on, a large file is never accumulated in memory (pinned in
  `Tests/Pages/File/LargeRangeStreamingTest` under two nested buffers and a 32M limit).
  The cache pool (see "The cache"), the Purifier cache and the Twig cache never use an `s3://` upload path as a
  directory: the Purifier and Twig caches use the kernel's cache directory instead.
- Deliberate differences from the old local filesystem: deleting a file removes its `.data` file too;
  an SQL site with an `uploadpath` and no `filesystem` setting stores files locally (it had no
  filesystem); `file_path_host` and the `file/path/host` event work for new uploads (the old code made
  the directories under the site's host and wrote under the configured name); a file id that is not 24
  or 32 hex digits is not found, whatever is on disk.
- Tests: `Tests/Kernel/LegacyFileSystemTest` (a temporary upload directory of its own),
  `Tests/Characterisation/FileServingTest` (the file page over HTTP, a range served with exactly its bytes),
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
  site's quote normaliser (`Idno\Storage\QuoteNormaliser::normalise()`, a static pure function that
  needs no legacy application; `Language::uncurlQuotes()` delegates to it), so
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
  idno:upgrade`; exit 0. For an SQLite target it then prints `Idno\Install\DatabaseChoice::sqliteOwnershipNotes()`.
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

#### Import and export

The admin pages `/admin/export/`, `/admin/import/` and the account export pages use bounded services in
`Idno/Portability/` (a scanned directory; public container services that need the legacy application, so they
are built only when a page, a job or the worker asks). The services depend on five small interfaces,
implemented over the legacy application by adapters in `Idno\Legacy`: `SiteFacts` (`LegacySiteFacts`: the
site's address, host, temporary directory, download limit, content types, who is an administrator),
`FeedShell` (`LegacyFeedShell`: the RSS template's document with no items), `ExportState`
(`LegacyExportState`), `ResultNotifier` (`LegacyResultNotifier`: the mails) and `PostWriter`
(`LegacyPostWriter`: articles and notes saved through `Entity::save()`, so slugs, tags, file links and events
work). `Idno\Core\Migration`'s static methods are deprecated delegates for one release.

- `ArchiveExporter::export($uuid)` (only for an administrator: a `\RuntimeException` otherwise) writes the
  archive's files to a private working directory (`<temporary>/idno-export-<32 hex>`, mode 0700, files 0600,
  removed on success and on failure), reads entities a few at a time (keyset paging on the store's own order, `created` then `_id` descending: never an
  offset, so an entity added or removed between two pages neither repeats nor skips one) through
  `EntityRepository::hydrate()`, copies
  attached files as streams (`FileStore`; an attachment the store does not have is fetched from its address
  through the site client only when the address is the site's own origin, else through the REMOTE client
  (`idno.http_client.remote`, a service closure: private and reserved addresses refused, redirects checked), at
  the size limit; one that fails is skipped with a log line), writes `exported_data.sql` through `CollectionDump`, builds
  the zip with `ZipArchive` (`ext-zip`, a Composer `suggest`) from the files, and stores it through
  `FileStore` with the metadata `restricted = admin`, `purpose = export`. Layout: `known.json`, `config.json`
  (`{"config": the STORED settings with `SettingsRedactor::redact($settings, null)`, and without any key
  `BootConfig::all()` holds (`Config::save()` copies those into the stored settings) or names in
  `ArchiveExporter::SERVER_SETTINGS`}`: never the boot
  configuration), `entities.json` (a list ending with `{}`), `json/<id>.json` (the entity's JSON plus its
  `access` and `publish_status`), `html/<id>.html` (the entity's drawn content inside an `<article class="h-entry">` (`h-event` for an event) with the
  author as an `h-card`, the address and the date: the page's shell, with its edit controls and tokens, is not drawn),
  `exported_data.sql` (the records as stored, password
  hashes and ActivityPub private keys included, because it is what restores the site), `files/readable/<id>.<ext>`
  and `files/uploads/<host>/<a>/<b>/<c>/<d>/<id>.file` (+ `.data`), to which an entity's attachment address
  (`../files/uploads/...`) points. `exportToFolder($parent)` and `zip($folder, $path)` are its halves.
- One entity that cannot be written (any `\Throwable`, an `\Error` too, from serialising or drawing it) never ends
  the export: it is counted (`ExportReport::$unrendered`), logged once with the exception's class and the
  entity's uuid (never its content), and stays in `exported_data.sql`; the result mail says "N entries could not
  be rendered and are included as data only".
- The archive is for administrators only, in ONE place: `Pages\File\View` (see Security). The export page says so
  next to the button, and says it must be kept as a database backup.
- `FeedExporter::rss($hidePrivate, $userUuid, $wxr)` yields the document in chunks (the shell, then each item,
  then the rest; nothing on disk); `stream()` copies it to a `php://temp` stream (memory up to 1 MiB, then a
  file PHP removes itself) for the pages, which send `Content-Length`. `$hidePrivate` builds the criteria
  explicitly (`access` PUBLIC, `publish_status` published or empty), so an administrator's export hides private
  posts and drafts too; with private posts included the viewer's own rules apply (an administrator reads every
  member's). `Entity::rssSerialise()` writes `wp:status` `publish`, `private` or `draft` as the entity is.
- `WordPressImporter::import($path, $ownerUuid)` and `BloggerImporter::import(...)` (Atom and RSS) return
  `['posts', 'comments', 'media', 'skipped']`; a file that cannot be read, is no XML or holds no post is zero
  posts, logged, never an exception. `SafeXml::load($path, $maxBytes)` (32 MiB for the importers) refuses a
  file that is too large, not well-formed, or has a document type declaration (looked for in the prolog only,
  so `<!DOCTYPE` in a post's CDATA is fine), with `LIBXML_NONET` and no entity substitution, and puts
  libxml's error handling back. WordPress: only `post` items; `publish` is public and published, `private` (or a
  password) private to the importing user, `draft`, `pending`, `future` and `auto-draft` drafts, anything else
  skipped; only approved comments of type comment; a comment author's address only when http or https;
  categories and tags as hashtags; an untitled post under 600 characters is a note. Blogger: `settings` and
  `template` entries skipped, drafts (`app:draft` yes, `blogger:status` DRAFT) imported as drafts, `SOFT_TRASHED`
  skipped, pages stay articles, comments become replies to the post their `thr:in-reply-to` names wherever it
  is in the file. An RSS item with `wp:status` private or draft is imported private or as a draft.
- An import goes on past an item that fails: each item (a post, its comments, a Blogger entry) is imported inside its
  own `\Throwable` catch, counted as skipped and logged with the exception's class and the item's title (control
  characters replaced, 80 characters), never the exception's message. A staged upload is marked `import_started`
  (time) in its metadata when its job begins; a job that finds the mark REFUSES to run it (the failure mail says it
  was started before and may have imported posts: a message put back by `--requeue-abandoned`, or a duplicated
  job for the SAME staged upload, never runs it twice. Uploading or submitting the same file twice is not caught:
  each submit stages a NEW upload, so its posts are imported twice). After `ImportResult::MAX_CONSECUTIVE_FAILURES` (25) items in a
  row fail (a success between resets the count) the import STOPS and is a failure: the importer's counts carry
  `stopped`, and the failure mail says it was stopped after repeated errors, how many posts were imported before
  that, and to check the site's log. The result mails carry the counts (`ResultNotifier::importFinished($user, $ok,
  $imported, $skipped, $startedBefore)`). A picture's temporary file goes in the job's own working directory
  (`ImportedMedia::inDirectory()`, only for an `idno-import-<32 hex>` directory).
- The full archive needs `ext-zip`: `ArchiveExporter::zipAvailable()` (with an injectable closure) decides, the export
  page shows a notice in place of the button when it is false (both templates), and the generate page refuses.
- Pictures: `ImportedMedia::forDomain($domain)->fetch($url)` / `import($url)`: only http or https on the
  scheme's own port, no user name, the domain or a subdomain, extension jpg jpeg png gif webp, through the REMOTE
  client (a closure fetched at each fetch), cut at `webservice_max_download` (a body of limit + 1 bytes is
  refused), and kept only when `getimagesize()` says the bytes are one of those types; else null and one log
  line (never the exception's message or the address's query). `ImportedImages::bring($html, $media, $siteUrl)`
  replaces each fetched address in the HTML (as written, and with `&amp;`), and the stored files are attached
  to the post (`attachFile()`), so the file route applies the post's access. The domains: `wordpress.com`
  and `blogspot.com` (Blogger's pictures on `blogger.googleusercontent.com` stay hot-linked).
- Nothing an import brings in is announced. `Idno\Portability\ImportMode` (a public service) holds a depth
  counter; the importers wrap their work in `during()` (reset in a `finally`: a worker lives on). The places
  that would announce ask `Idno::importInProgress()`: the ActivityPub and StandardSiteSync `saved`/`updated`
  hooks, `Entity::syndicate()`, the PubSubHubbub `published` hook and `Webmention::pingMentions()` (the
  `IDNO_NOMENTION` constant still works for scripts). Pinned by `ImportTest` (a follower's sink on the rig)
  and `QueuedPortabilityTest` (the worker).
- `PortabilityJobs`: `beginExport()` (one export at a time; `ExportState::begin()` refuses while one runs unless
  its flag is six hours old), `exportState()` and `removeExpiredArchive()` (an archive is removed when a new one
  is made and when it is a week old, counted from when it was MADE: the time is stored with its id,
  `export_file_created`, and is the "Generated" date the page shows, never `export_last_requested`, which a later
  request moves; checked whenever the export page is loaded or an export asked for: there is no scheduler),
  `markRecordedArchive()` (an archive recorded by an earlier release has no `restricted` mark: it is written on
  every page load and export request) and `sweep()` (at most hourly, on a page load and a job start: the
  working directories of this install in the temporary directory, exactly `idno-export-`, `idno-import-` or
  `idno-archive-` plus 32 hex digits, a real directory owned by the current user (the temporary directory is
  shared: nothing else is touched), older than 24 hours; and, from the list the jobs keep in the settings
  (`portability_tracked`: id, kind `upload` or `archive`, time; at most `TRACKED_LIMIT` (50), the oldest beyond it
  deleted when a new one is added), an upload older than 24 hours and an archive that is not the recorded one a
  week old, files already gone dropped from the list. THE FILE STORE IS NEVER LISTED: a page request must not
  read a whole upload tree (minutes on a large store or S3). A stored upload or archive is recorded when it is
  stored (`stageUpload()`, `runExport()`) and forgotten when it is removed; a job killed between storing an
  archive and returning its id leaves a file nothing knows about),
  `stageUpload()` (the upload waits in the file store, `restricted`,
  `purpose = import`, removed when the import ends), `runExport($uuid)` and `runImport($uuid, $fileId, $type)`.
  A job never throws: any `\Throwable` is logged, the flag cleared, the failure mail sent, and the event is
  not parked in the failure transport. `runImport` reads and removes only a file whose `purpose` is `import`
  (the event names a file by id), and only for an administrator. `LegacyExportState` re-reads the stored
  settings before every change and sets only its own keys (`export_last_requested`, `export_in_progress`,
  `export_file_id`, `export_filename`, `export_file_created`, `portability_last_sweep`, `portability_tracked`): a worker's
  configuration object is as old as the worker. JOB AGAINST JOB: the settings are ONE document and each change is a reload, then a save of the whole
  document, so two writers at once (an export and an import, a job and a page's sweep, two jobs of two workers)
  can lose one of the two changes: the one that saved first is overwritten by the one that read before it. What
  can be lost is a tracked file (nothing then sweeps it: it stays until someone deletes it), an `export_in_progress`
  flag or the recorded archive (the page then shows no archive; the file stays restricted). Nothing is corrupted,
  and a lost change is never a secret leaking. A SETTINGS SAVE elsewhere during an export (an administrator
  saving the site's configuration page, whose form was loaded before the export began) writes the whole
  settings document and can restore the old export state: the page then shows no archive or no running export,
  and `export_in_progress` clears by itself after six hours; the archive file stays restricted and the sweep
  removes it after a week.
- The pages: `Pages\Admin\Import` refuses (a session message, no job, no mail) a request with no source of
  `PortabilityJobs::IMPORT_TYPES`, no file, an empty or unreadable one, or one over 32 MiB; otherwise it stages
  the upload and, with `queue()->isAsynchronous()`, enqueues `portability/import` (data `user`, `file`, `type`;
  run as the user) or, without, runs the job through `Idno\Core\AfterResponse::run()` (redirect, close the
  connection, `set_time_limit(0)`, the job, exit: no sleep). `Pages\Admin\Export\Generate` does the same
  with `portability/export` (data `user`). The listeners are registered by `Idno\Core\Admin::registerEventHooks()`
  (the worker's boot has them). The "Idno" import form, which had no code behind it, is gone: an Idno RSS file
  imports through the WordPress form.
- The worker is a console process: `Config::loadIniFiles()` gives it the host of the boot `url` (its host
  was empty, so its file directory, named after the host, was not the pages'). Pinned by
  `Tests/Queue/QueueWorkerHostTest`.
- `SettingsRedactor::redact($settings, $replacement)` is the one rule for which settings are secrets
  (a password, secret, token, key, credential, signature, salt, DSN, JWT, HMAC, authorization, bearer, OAuth,
  IndieAuth, cookie, `private` or service user name, at any depth, and the names `dbstring` and `proxy_string`; an OAuth `client_id` is public, and a number under a
  key that names a duration (`form_token_expiry`) is no secret; a value that starts `sk_live_`, `sk_test_`,
  `rk_live_` or `rk_test_` is removed whatever its key). It is a DENYLIST of key names: a secret under another name is
  not found, so an archive's `config.json` is to be reviewed before it is shared; a string that is an address with a user name or
  password has them removed wherever it is; a key that names a `webhook` keeps its entries with each address
  reduced to scheme and host (the path is the secret). The diagnostics page (marker `** REDACTED **`, on a
  copy, for the configuration, `$_SESSION` and `$_SERVER`) and the archive's `config.json` (keys removed) use it.
- An import reads its upload from a file in a private directory this install makes
  (`<temporary>/idno-import-<32 hex>`, mode 0700, removed when the import ends).
- A command-line process (the queue worker, a console plugin) draws the core templates as a web request does: core's
  directory is the template cascade's last base (the cascade is built with it, nothing adds it at boot), so the export
  and import result mails have bodies in the worker. Pinned by
  `Tests/Kernel/ConsoleTemplatesTest`, `TemplateContractTest::testConsoleProcessFindsCoreTemplates` and `Tests/Acceptance/PhaseWalkthroughTest`. What the worker sends to other
  servers (ActivityPub activities, webmentions) is built by the request that queued it, not drawn in the worker
  (`QueuedDeliveryTest::testTheContentTheWorkerDeliversIsTheFormattedBodyOfThePost`).
- `Entity::getBySlug()` finds the stored (percent-encoded) slug of a path segment the web server decoded, and the
  `:slug` route token matches any segment without a slash or a dot: an article titled `Café notes` is served at
  `/2024/caf%C3%A9-notes` (`SlugRoutingTest`).
- `WordPressImporter` appends a category as a hashtag only when the body does not already show that tag (a
  re-import of an export of this site does not repeat them).
- The deprecated `Migration::archiveExportFolder()` makes its zip in a directory of its own in the temporary
  directory (`idno-archive-<32 hex>`, mode 0700) and returns the path inside it; the caller removes both.
- The console never creates a Site record: under `IDNO_CONSOLE`, `Idno::site_details()` uses the `Site` record
  whose domain is the host of the boot `url` (the worker's host), else the only `Site` record there is, else none
  (never more than one is looked at; a console process does not create one; a web request creates it, as
  before). Creating one in the worker made a second site per queue run. Pinned by `Tests/Queue/QueueWorkerHostTest`.

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
  steps and `idno:upgrade`) before any copy, on the still empty tables, held by the newest migration
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
   The times an activity carries are the post's own, so the same times are built every time (an `Update`'s `id` still ends in the build time,
   `#update-<time()>`; a `Create`'s is `#create`):
   a `Create`'s `published` is its object's `published` (the post's creation time, equal by
   construction, also in the outbox); an `Update`'s `published` is the object's `updated` (the
   time the post was last saved, which `Entity::save()` sets from the same clock reading as
   `created` on a first save, so a new post has no `updated`) and, for a post never edited, the
   time it was published; a `Delete` and the `Accept` of a quote request are events of the
   moment they are built and carry that time; the follow `Accept` carries none. The object has
   `updated` only when it is later than `created`. A test never compares two separately
   generated times (`ActivityTimesTest`, `QueuedDeliveryTest`).
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
| **Webmention** | `Idno\Core\Webmention` — sends and receives webmentions; endpoint at `/webmention/endpoint`; every bundled content type sends through the queue with `Webmention::queueMentions()` (the event `webmention/sendall`) |
| **Micropub** | `IdnoPlugins\IndiePub` — full Micropub server at `/micropub/endpoint` |
| **IndieAuth** | `IdnoPlugins\IndiePub` — authorization at `/indieauth/auth`, token at `/indieauth/token` |
| **Webfinger** | `Idno\Http\Controller\WebfingerController` and `Discovery\Webfinger` — `/.well-known/webfinger`; the links are the `webfinger` event's (`LegacyWebfingerDirectory`); `Idno\Core\Webfinger` only registers the legacy route's placeholder |
| **Microformats2** | Templates emit `h-entry`, `h-card`, `h-event` markup; `mf2/mf2` parser included |
| **PubSubHubbub** | `Idno\Core\PubSubHubbub` — real-time feed notification |
| **Markdown** | A post's permalink and the listing, profile, login and error pages are also served as Markdown with YAML front matter by `?_t=markdown` (or `md`); a settings page (`/account`, `/admin`, however its address is written: decided from how its route was registered) answers a title and one line, and the pages of the installation stay HTML; a post's permalink also by `<permalink>.md`, `?format=markdown` or `Accept: text/markdown` (see "Markdown output") |
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
  `Require all denied` `.htaccess` in the directory, as `var/`, `config/`, `bin/`, `frontend/` and
  `Tests/` have), plus a SAPI guard at the top of each command-line script: under any SAPI
  but `cli`/`phpdbg`, `http_response_code(404)` and `exit` before loading anything (see
  `bin/console`). Scripts for PHP's built-in web server (routers) allow `cli-server` only.
  `Tests/Kernel/SapiGuardTest` and `Tests/Characterisation/DeniedPathsTest` pin these. A rule
  for a directory matches it in any letter case (`[F,NC]` on Apache, `~*` on nginx, `(?i)` on Caddy,
  `#...#i` in the routers): on a file system that ignores case `uploads/` is `Uploads/`.
- New code logs by type-hinting `Psr\Log\LoggerInterface` in its constructor. Autowiring gives
  the site's logger (`Idno\Core\Logging`), which honours the configured log level and the line
  format. Never inject or fetch `monolog.logger.idno` (or any `monolog.logger.*` service)
  directly, and never call `error_log()`: both bypass the log level.
- The one exception: code in `Idno\Upgrade\` (and the web installer, `Idno\Install\WebInstaller`, wired the same way) runs while the legacy application is NOT booted, so
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
  class's constructor directly. The restriction is that the site's level filter is the legacy `Idno\Core\Logging`'s.
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

`BootConfig::isDefault()` (the site is not installed: see "Installation") is true when no
configuration file was read AND the merged configuration names no `database`.

`path` and `host` can never be set; `uploadpath` always ends in a single `/`. The
former per-host `configuration/{host}.ini` file is no longer read (silently: the upgrade
notes in `docs/install/upgrade.md` tell sites to move its settings into `config.ini`), and
no installer writes one.

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
- `url` is a boot setting (`config.ini`, `config.json` or `IDNO_URL`; never stored in the database). The queue
  worker requires an explicit absolute one and refuses to start without it. Both installers write it: the web
  installer from the domain the person confirmed on screen (never the Host header unseen), `idno:install` from
  `--url`. A configuration written by hand, or made by an older installer, adds it by hand
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
- Text linkers (`DefaultTemplate::parseURLs()`, `parseHashtags()`, `parseUsers()`, and the Twig filters over them): they link text only, never inside a tag,
  a link, a script, a style or a textarea, and `parseHashtags()` never decodes an entity of `< > & " '` or a backtick; an address in an attribute of sanitized HTML
  is therefore never made into a link. `Tests/Kernel/TemplateLinkersTest` and `Tests/Core/ParseLinksOutsideTagsTest.php` check this against an HTML parser (the one private method all three go through is `Parsing::mapText()`), and
  `IdnoPlugins/Event/Tests/RSVPOutputTest::testLinkingTheSanitizedBodyAddsNothingToTheMarkup` through a real template; `Tests/Core/ParseHashtagsTest.php`
  pins every spelling of those entities (named, decimal, hex, leading zeros, either case of `x`) while named letters such as `&auml;` still decode so that
  `#Äpfel` links. Don't `html_entity_decode()` text that is going to be printed as HTML.
- The session cookie is HttpOnly, Secure over https and `SameSite=Lax` (`Idno\Core\Session::init()`: the general
  defence for the handlers that skip the form token; the expiring cookie of a logoff carries the same attributes). `init()` also sets the session's cache
  limiter to `nocache` before the session starts (`session_cache_limiter('nocache')`), so the lines that keep every page out of shared caches (`Expires` in 1981,
  `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache`) are the site's own and a host whose php.ini says `public`, `private` or nothing still sends them
  (`Tests/Characterisation/SessionCacheLimiterTest`); a site that relied on its own `session.cache_limiter` no longer gets it. The limiter does not depend on the session handler. No
  `P3P` header is sent, and `Session::validate()` is gone. `session_hash_function` is read and ignored. A session is
  never empty: `init()` writes `$_SESSION['secure']` (whether the session began over https), because the database
  session handler (Symfony's PdoSessionHandler, MySQL and MariaDB) destroys an empty session when it writes, so a
  visitor with nothing else in the session would get a new id on the next request and the form token, which is kept in
  the session, would be lost with it (the token now makes the session non-empty by itself; `secure` stays) (every form a visitor posts, a password reset for one, would fail with
  "Invalid token"; SQLite's session files keep empty sessions, which is why only MySQL showed it:
  `MailFlowsTest::testThePasswordResetSentByTheWebServerIsRecordedAndReadOverHttp` proves it, as the test rig on
  MySQL). A signed API request's signature is compared with `hash_equals()`, and no log line at any level holds a
  signature, an expected HMAC, an API key or an access token (`Tests/Kernel/SessionSecretsNotLoggedTest`,
  `Tests/Characterisation/SessionCookieTest`). An `access_token` in a query string also stays out of the fatal-error
  page's log line and the oops mail: `Idno\Core\QueryRedactor` (pure; `query()` and `uri()`) replaces its value with
  `[redacted]`, matching the name as PHP registers it (URL-decoded once, cut at a NUL byte, leading spaces stripped, `.` and space read as `_`, a `[` with a `]` after it ends the name, one without becomes `_`; the unit test checks every case against `parse_str()`) (`Tests/Unit/Core/QueryRedactorTest`, `Tests/Core/FatalErrorPageTest`, `MailFlowsTest::testOopsAlert`).
- HTTP Signatures (draft-cavage-http-signatures) for all ActivityPub federation
- What an incoming signature must cover: `HTTPSignature::verify()` refuses a request
  unless its signature covers `(request-target)`, `host` and `date`, and, for any method
  but GET and HEAD, `digest`. Each of those headers must be present; the `Digest` must be
  the SHA-256 or SHA-512 of the body (RFC 3230: several digests and any letter case are
  accepted); and the `Date` must be no more than twelve hours old (`MAX_AGE`) and no more
  than an hour ahead (`MAX_CLOCK_SKEW`), the window Mastodon uses. Mastodon, GoToSocial,
  Pleroma, Akkoma and Misskey sign all four on a delivery. Don't relax this: a signature
  proves only the headers it lists, so without `digest` the body is unsigned. Deliveries
  are not remembered, so one can be replayed while its `Date` is in the window; handlers
  must be safe to run twice. Only draft-cavage signatures are understood, not RFC 9421.
  `IdnoPlugins/ActivityPub/Tests/HTTPSignatureCoverageTest.php` checks this.
- CSRF tokens on all form POSTs to legacy pages (inbox endpoints bypass via `post()` override).
  The token is ONE per session (`Idno\Security\FormToken`, token id `idno`): Symfony's `CsrfTokenManager`
  (`security.csrf.token_manager`, wired by hand in `config/services.php`, `framework.csrf_protection` stays off because it
  needs `framework.session`) on `Idno\Security\SessionTokenStorage`, which keeps it in `$_SESSION` through
  `NativeSessionTokenStorage` and, where no session is active (the console, the queue worker, a test in-process), in the
  process, so drawing a form never starts a session or sends a header. The value a form prints and a request posts is the
  STORED value, 64 lower-case hex characters made by `Idno\Security\HexTokenGenerator` (`bin2hex(random_bytes(32))`), not
  the manager's per-call randomised form: it is stable for the session, and `PageNormaliser` and the golden pages see the shape they always did. `Bonita\Forms::token($action, $time)` returns it and ignores both arguments;
  `Forms::validateToken()` is true for a stateless (API) request and otherwise checks `__bTk` alone (`hash_equals` through
  the manager; a missing, array or odd value is simply invalid, never an error); `__bTs` and `__bTa` are still printed and
  ignored, and a token is good for the session's lifetime (`form_token_expiry` is read by nothing). The session makes its token at its first
  request (`Session::init()`), because a page draws its forms after `session_write_close()` and a token made then cannot be stored
  (`SessionTokenStorage` reads a closed session's `$_SESSION`); `Session::logUserOn()` replaces the token at once when it
  regenerates the session id (`Forms::forgetToken()`), and `logUserOff()` empties the session,
  so a token never outlives the id it was made for; where no session is active the token is kept in the `SessionTokenStorage` object, which is right only because the container lives for one request on the web (a long-lived process, such as the queue worker, never draws a form for a person); the stored token also makes an anonymous session non-empty for the
  database session handler. A form opened before the upgrade (the old keyed hash) fails once with "Invalid token." and
  changes nothing. No log line holds a token or part of one (`Page::debugLogToken()` is gone). `site_secret` no longer
  makes form tokens (it still signs ActivityPub quote stamps). New-style controllers check the same token with
  `#[IsCsrfTokenValid]` (see "Adding a route"); the upgrade step `POST /_upgrade/step` changes state without either, under the conditions
  stated there (no input selects what it does, nothing while the schema gate is open, bounded and
  serialised work, `no-store`, a same-origin path redirect, encoded output)
- Pages that override `post()`: `Page::post()` is what checks the form token, so a page
  that overrides it has no CSRF protection of its own and must not act on a session
  cookie alone. The Micropub endpoint (`IdnoPlugins/IndiePub/Pages/MicroPub/Endpoint.php`)
  answers a POST only when `Session::isAPIRequest()` is true, which the IndiePub plugin
  makes so (`Session::authenticateStatelessly()`) after an access token (`Authorization: Bearer`
  or the `access_token` field) has matched, and core makes so after a signed API request; otherwise it answers 401 (a GET, for
  `q=config`, `q=syndicate-to` and `q=source`, is held to the same rule). The
  IndieAuth `Auth` and `Token` pages act on the authorization code in the request, not
  on the session. `IdnoPlugins/IndiePub/Tests/MicropubEndpointAuthTest.php` checks this
  over HTTP with a logged-in browser.
- Access tokens count at one page: an IndieAuth access token (and the first administrator's
  API key, which the plugin also takes as one) authenticates a request only where
  `\IdnoPlugins\IndiePub\Main::authenticate()` is called, and that is the Micropub endpoint's
  `get()` and `post()`. On every other page a request that carries a token is treated as
  if it carried none: it is anonymous, or it has its browser session and needs a form
  token. Don't call `authenticate()` from an event listener or anywhere else that runs for
  every request; a plugin that wants to accept tokens calls it in its own page. The token
  endpoint (`GET /indieauth/token`) looks a token up to describe it and logs nobody in.
  The scope stored with a token is not enforced. The core signed API (`X-IDNO-USERNAME` /
  `X-IDNO-SIGNATURE`, made with the user's API key) is a separate, general credential: it
  authenticates a request to any page, and `User::isAdmin()` is false for every API
  request (and `ROLE_ADMIN` is never granted to one), so neither credential passes `adminGatekeeper()`.
  `IdnoPlugins/IndiePub/Tests/AccessTokenReachTest.php` checks this over HTTP. A queue
  worker or console process has no request and so no token: events run as the user the
  message names (`LegacyUserContext`), and the `user/auth/request` event no longer has an
  IndiePub listener. The import and export pages, which an API request must not reach
  without a form token, are closed to a bearer token for the same reason
  (`Tests/Characterisation/AdminPortabilityPagesTest`).
- A page that must take POSTs without a form token (the ActivityPub inboxes, for example)
  overrides `post()` itself and authenticates the request another way. Never get the same
  effect by calling `Session::setIsAPIRequest(true)` (deprecated; it logs one line) or
  `authenticateStatelessly()` for a request that has not presented a credential (an API
  signature or an access token): the flag turns off the token check in `Page::post()` for
  whichever page the request reaches. In particular, don't decide what a request is
  for by matching `$_SERVER['REQUEST_URI']`, which includes the query string.
  `IdnoPlugins/ActivityPub/Tests/InboxRequestTest.php` checks this over HTTP.
- Command-line scripts, `var/`, `config/`, `bin/`, `frontend/`, `Tests/`, `docs/` and `warmup/CLI/` (which no longer
  ships; the rule stays for a site upgraded by overwriting files) are
  denied over HTTP, and the scripts refuse to run under a web SAPI (see "New code conventions")
- The site's configuration (`configuration/`) and the upload directory under the project (`Uploads/`, where the web
  installer always puts uploads and `idno:install` does by default) are denied over HTTP by every shipped configuration
  (the root `.htaccess`, both `.dist` files, `nginx.conf`, the Caddyfile, both routers), in any letter case, and
  `Uploads/.htaccess` refuses everything itself. A stored file reached by its path would skip `Pages\File\View` and so
  the post's `canRead()`, the `restricted` mark and the export archive. `Tests/Unit/Install/UploadsDeniedTest` reads
  every shipped configuration and runs its deny rules against upload paths in mixed case, and resolves each path through
  nginx's own choice of location (an exact location, else the longest prefix, which wins outright when it is `^~`, else the
  first matching regex in the file's order): the uploads are refused by `^~ /Uploads/` and `^~ /uploads/`, which no regex can
  override, and by the case-insensitive deny regex placed before the static-asset and `.php` locations, so a reordering that
  hands `/UPLOADS/x.png` to the static location fails it. Both test routers match their rules on the path as a web server does
  (read from the request URI, decoded once, repeated slashes made one, `.` segments removed and `..` segments resolved, one that would climb above the root staying at `/`: `//Uploads/x`, `/./Uploads/x`, `/x/../Uploads/x`; `Tests/build/router.php` no longer refuses a `..` inside a name once the segments are resolved).
  `DeniedPathsTest::testStoredFilesUnderTheUploadsDirectoryAreRefused` asks the rig for a stored `.file` and `.data`, in those
  spellings too (sent as written), and `testDotDotSegmentsAreResolvedBeforeTheDenyRules` the `..` ones.
  An upload path elsewhere in the document root needs a rule of its own (`docs/install/instructions.md`).
- Private sites (`walled_garden`) and pages that controllers serve: `PageRequestListener` applies `LegacyPageLifecycle::mayView()` (a public site, a logged-on visitor, or `#[SitePage(publicOnPrivateSite: true)]`, which
  the legacy `addRoute(..., true)` and `addPublicRoute()` of the page's class also set) after OPTIONS and before the page is entered; a refusal is the site's 403 page in the request's template type, fires no
  `page/head` or `page/get` and is logged as nothing. Public on a private site: the home page only where a plugin made its route public, the manifest, the service worker and WebFinger. Refused to a visitor: the
  feeds, `/search`, tags, `robots.txt`, the mention search and the form token endpoint (`PageContractTest::testWalledGardenAnswersForEachPage`, `PrivateSiteAccessTest`). The form token endpoint
  (`/service/security/csrftoken/`) answers only an XHR request, the session's own stored token, `Cache-Control: no-store`, and no CORS header. Caching of every site page is the session's `nocache` lines unless the page
  asks with `PageContext::setCaching()`.
- Account pages are closed to API requests: a page registered for a route under
  `/account/` (settings, password, API key, access tokens, the account's own export of its
  posts as a feed (the form `Idno\Pages\Account\Export`; the feeds `Idno\Pages\Account\Export\RSS` and
  `Idno\Pages\Account\Export\WXR`, served by `Idno\Portability\FeedExporter`) and
  what plugins put there) answers 403 to any request for which `Session::isAPIRequest()` is
  true by the time the page is dispatched, that is, one signed with `X-IDNO-USERNAME` /
  `X-IDNO-SIGNATURE`. An API key or token is a credential for publishing, and must not be
  enough to change the password or email address or to read the key. `PageHandler` records
  the page classes when `addRoute()` / `hijackRoute()` is called with such a pattern
  (`PageHandler::isAccountPage()`), and `PageHandler::serve()` refuses them in a
  `before_handler` hook; the decision is made from how the page was registered, never
  from the address of the request. For a page a controller serves the same refusal is `LegacyPageLifecycle::refusesApiRequest()` (the attribute's `accountPage`, or a registration of the page's class under
  `/account/`), a Security `AccessDeniedException` drawn as the page's 403 with the legacy refusal's session message, for every method. Put a page that manages credentials under `/account/`.
  Only a web request goes through `serve()`: the queue worker and the console have no request,
  no API signature and no router, so nothing there is affected (events run as the user a
  message names). Everywhere else a signed request acts as its user with no form token, and
  is never an admin (`User::isAdmin()`). `Tests/API/AccountPagesTest.php` checks this over HTTP.
- User search: `/search/users/` (`Idno\Pages\Search\User`) draws, for each match, the member's email address
  and the administrator's controls (make admin, block, delete), so it calls `adminGatekeeper()`, not
  `gatekeeper()`; its only caller is the admin Users page. A member gets 403 for every template type, for a
  search by email address and for a chosen `template`. Don't add a member-facing use (an @mention picker, say)
  to it: write a separate page that returns handle, name, avatar and profile address only.
  `Tests/Pages/Search/UserSearchAccessTest.php` checks this over HTTP.
- HTML sanitization via HTMLPurifier
- Template variables and the included file: `Idno\Templating\PhpTemplateEngine` keeps the located
  `.tpl.php` path on a private static stack (pushed before the render, popped in a `finally`) and reads
  it for the `include` after the variables are extracted, so no template variable (`path`, `vars`, `t`,
  `k`, `v`...) can change which file is included. A template still sees the caller's own `$path`
  variable. Never `include` a path held in a local variable of a scope that variables from outside are
  written into. `Tests/Core/TemplateIncludeTest.php` and the contract case in
  `Tests/Kernel/TemplateContractTest` check this.
- Output in templates: the templates of core and of the bundled plugins are Twig files, which autoescape (`.html.twig`: HTML;
  `.txt.twig`, a plain-text mail, is not escaped and must print text only). What still matters in them: every `|raw`,
  `autoescape false`, `filter raw`, `apply raw` and `source(` is on `Tests/Templating/fixtures/raw-allowlist.php` with its reason
  (`Tests/Unit/Templating/RawUsageTest`), and `|raw` is never for a value a person or another site supplied; an address that
  goes into `href` or `src` and came from a person or another site goes through `|http_url` (http or https, else an empty
  string), also where it was checked when it was stored, because values stored before a check are not rewritten; a value inside
  inline JavaScript uses `|e('js')`, or `js_string()` in an attribute; the first argument of `t_html()` is a string literal
  (translations are trusted markup). The old rule stays for the templates that are still PHP, the `classic` ones that a feed,
  JSON, Markdown, ActivityPub, KML or mail draws and a third party's `.tpl.php` files: a `.tpl.php` template escapes nothing by itself. (Markdown is
  the exception to the escaping rule in the other direction: its HTML is purified (`sanitize_html`) and then converted to Markdown text, so nothing is escaped at print time, and
  a value that is not HTML goes through the converter's own escaping.)
  Print every value
  that a person or another site supplied with `htmlspecialchars()`, in text and inside an
  attribute alike; `strip_tags()` and `FILTER_SANITIZE_URL` do not make a value safe
  inside an attribute, because both keep the double quote. An address that goes into
  `href` or `src` must also be an http or https one, or a browser runs it
  (`javascript:`): pass it through `\Idno\Core\UrlValidator::httpUrl()`, which returns
  the address or an empty string, and then through `htmlspecialchars()`. Do this where the
  value is printed even if it was checked when it was stored, because values stored
  before the check are not rewritten. `Tests/Core/UrlValidatorTest.php` checks `httpUrl()`.
  Its pattern is a nowdoc (`UrlValidator::HTTP_URL_PATTERN`). Write a regular expression
  that holds backslashes that way: in a quoted PHP string `\\` is one backslash, which
  once turned a `\s` into "a backslash or the letter s" and refused every host that
  begins with "s".
- Link previews: `LinkPreview::buildPreviewFromData()` keeps the link and the image
  (`og:image` of the fetched page) only when `UrlValidator::httpUrl()` accepts them, and
  `templates/default/entity/LinkPreviewCard.html.twig` (and its `classic` copy, `LinkPreviewCard.tpl.php`), checks them
  again and escapes every value of the preview. `Tests/Core/LinkPreviewCardTest.php`
  checks this.
- Following and the error mail: a feed's title and addresses
  (`templates/default/following/confirm.html.twig`, `templates/default/entity/Subscription.html.twig`),
  an h-card's name, photo and address
  (`templates/default/account/settings/following/mf2user.html.twig`) and the User-Agent,
  query string and referrer of a request (`templates/email/admin/oops.html.twig`) come from
  other sites or from the request, and are stored or passed on as they were sent, so the
  templates autoescape them and put an address into `href` or `src`
  only through `|http_url`. The text part of the mail
  (`templates/email-text/admin/oops.txt.twig`) is not HTML and is not escaped.
  `Tests/Core/RemoteFeedOutputTest.php` checks this.
- SSRF protection: the plain client (`Webservice::send()` and its wrappers, the `idno.http_client`
  service) is for the site's own URLs and for services the site's ADMIN configured (the PuSH hub, a
  webhook the admin configured in the site's `webhook_syndication` setting, an API endpoint; a webhook
  from a member's own `webhook_syndication` list goes through `safeSend()`). Every other URL goes through `Webservice::safeSend()` or
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
  (`Tests/Kernel/RemoteFetchCallSitesTest`; the first check's early refusal of a loopback or
  private address at the actor, link-preview and Micropub-media call sites is
  `Tests/Kernel/RemoteFetchRefusalTest`; the flows the rig can drive are in
  `Tests/Characterisation/RemoteFetchTest`). Deliberate difference: a member can no longer
  subscribe to, unfurl, share or bookmark a URL on the site's own private network or on localhost, and a
  site served from a loopback or private address (a development or intranet install) can no longer
  discover and subscribe to the hub of a profile or feed on that same address.
  Also moved, to the remote client, because every URL it requests comes from a member's input or from
  a document a remote server returned: `IdnoPlugins\StandardSiteSync\ATProtoClient` (below), and the
  pictures an import fetches (`Idno\Portability\ImportedMedia`, below). The export's fetch of an
  attachment the file store does not have goes through the site client only for the site's own origin and the
  remote client otherwise (`ArchiveExporter`). `File::getFileDataFromAttachment()` is gone (nothing called it).
- TLS: every HTTPS request `Webservice` makes verifies the certificate and host name, on both
  clients, unless the boot configuration's `disable_ssl_verify` is set (see "Webservice" above). Do not
  turn verification off anywhere else.
- `Actions::createLink()` (`forms/link`, the Idno 2026 template and the `classic` one a feed or JSON would draw; `action_link()` in Twig follows the same rule) prints the address (only if
  `UrlValidator::httpUrl()` accepts it), class, title and confirmation text escaped; the
  confirmation text goes into the handler as one JSON string. The label is markup by design and is
  printed as given, so a caller must escape any text it puts in the label.
  `Tests/Core/CreateLinkOutputTest.php` checks this.
- File access control: `Entity::save()` records the entity's UUID on each of its stored
  files (`attached_entity` in file metadata, via `FileSystem::updateMetadata()`), and
  `Idno\Pages\File\View` enforces the entity's `canRead()`. Entities that own extra
  files (e.g. Photo thumbnails) override `getAttachedFileIDs()`. Custom filesystems
  must implement `updateMetadata()` for this to apply. A file whose metadata has the key `restricted` (whatever
  it holds, even something that reads as no; the export archive and an import's upload carry `admin`) is served to administrators only:
  `Pages\File\View` answers anyone else 403 before it reads anything, fails closed for any value, and sends
  `Cache-Control: private, no-store`, on the 403 as well (see "Import and export"). A file with the key
  `site_export` (the mark an earlier 2.0 development build put on an archive) is restricted the same way. A file is also administrators-only when its id is
  the recorded export archive (`export_file_id`), marked or not (an archive an earlier release stored has no mark);
  HEAD and Range requests are refused the same way, before any header that describes the file.
- Showing or exporting the configuration: pass settings through
  `\Idno\Portability\SettingsRedactor` first (`redact($settings)` shows a marker, `redact($settings, null)`
  removes the key). It is the ONE rule for which settings are secrets: a name that says password, secret,
  token, key, credential, private, signature, salt, cookie and the like, `dbstring` and `proxy_string`, and the
  user name and password inside any address. The diagnostics report (the running configuration, `$_SESSION` and
  `$_SERVER`) and the archive's `config.json` both use it; never `json_encode()` the `Config` object itself, which
  holds the database password and everything else from `config.ini`. `config.json` holds the stored
  settings only (`ArchiveExporter`): minus the ones that describe the server
  (`ArchiveExporter::SERVER_SETTINGS`: database, paths, sessions, proxy) and whatever the boot
  configuration (`config.ini` or the environment) defines. A plugin that stores a secret in the configuration
  must give the setting a name the rule recognises. `Tests/Pages/Admin/ExportArchiveTest.php` and
  `Tests/Unit/Portability/SettingsRedactorTest` check this.
- Annotations (replies, likes, shares, mentions and RSVPs, most of which arrive as
  webmentions): `Entity::addAnnotation()` keeps the owner's address, the owner's image and
  the annotation's address only when `UrlValidator::httpUrl()` accepts them, and stores an
  empty string otherwise (for the annotation's address, an address of its own on this
  site). The annotation templates of the web page look (`templates/default/entity/annotations/`, Idno 2026) and of the
  `classic` one (`templates/classic/entity/annotations/`, which a feed or JSON would draw) check and escape them again.
  `Tests/Common/AnnotationOutputTest.php` checks this.
- Passwords: `Idno\Security\Passwords` (a public container service in a scanned directory, `Idno/Security/`; it needs nothing of the legacy
  application and is built with `new Passwords($cost = 12)` in a unit test) makes and checks the hashes on `symfony/password-hasher`.
  `hash()` and `verify()` trim the password (the forms trim it too); `hash()` throws `\InvalidArgumentException` for an empty or
  over-4096-byte password. New hashes are bcrypt from Symfony's `NativePasswordHasher` at cost 12 (what PHP's `password_hash()` always used
  here: Symfony's own default of 13 would rehash every hash for nothing), which pre-hashes a password longer than 72 bytes or holding a NUL
  byte, so every byte counts. Hashes PHP made directly from such a password do not verify that way, so a typed password of more than 72 bytes
  or holding a NUL byte that the native hasher refuses is also tried with `LegacyBcryptPasswordHasher` (plain `password_verify()`; it never
  makes a hash; for a typed password of at most 72 bytes with no NUL the native check IS `password_verify()`, so the second check is skipped
  and a wrong password costs one bcrypt). Both are `$2y$` hashes that the hash alone does not tell apart, so Symfony's
  `MigratingPasswordHasher` (which decides from `needsRehash()` of the new hasher) is not used: `verifyAndRehash($hash, $plain): ?string`
  returns null for a wrong password, the same hash when it is fine, and a new hash when the cost or algorithm differs and the typed password
  is covered in full by the old hash (at most 72 bytes, no NUL). The rule for the rest: an old bcrypt hash only ever covered the first 72
  bytes of a password (and the part before a NUL byte), so when ONLY the legacy check verifies a typed password over 72 bytes or holding a NUL
  byte, the typed tail is unverified: the login succeeds and the stored hash is returned UNCHANGED, now and on every later login (a mistyped
  tail never becomes the password and locks the owner out). Such a hash stays legacy until the owner sets the password again, and the
  legacy check keeps accepting, for any bcrypt hash made before this release of a password over 72 bytes, a typed password whose first 72
  bytes are the whole stored password (so that weakness does not end by an upgrade; it needs the password already). `needsRehash($hash)` is
  the cost check alone. `User::setPassword()` and `checkPassword()` keep their return values
  (false for an empty password) and call the service (the container's, else one built with `new` where there is no container);
  `checkPassword()` stores a replacement hash with `save(true)` (the `updated` hooks of ActivityPub and StandardSiteSync ignore a `User`), a
  failure to store it is logged and never fails the check, and a replaced hash is not replaced again. A third-party plugin that calls
  `setPassword()` or `checkPassword()` gets all of this unchanged; one that calls `password_hash()`/`password_verify()` on `$user->password`
  itself still verifies (bcrypt), but does not upgrade. `RemoteUser::checkPassword()` is false as before. Tests: `Tests/Unit/Security/`,
  `Tests/Kernel/PasswordCompatibilityTest`.
- Logging in (`Idno\Pages\Session\Login`). ONE failure message for an unknown login name and a wrong password: "Oops! That
  username or password isn't right. Please try again." (JSON login too); the legacy events still fire as before (`login/failure`,
  `login/failure/nouser`). For a login name nobody has, `Passwords::spendVerification()` does one bcrypt against a fixed cost-12
  hash of nobody's password (an empty password costs nothing, as for a member), so an unknown name costs what a wrong password costs
  and the time does not tell them apart (the remaining difference is a database lookup by email after the one by handle, which a
  member found by handle does not make: not hidden).
- Login throttling: `Idno\Security\LoginThrottle` (a public container service, built when first fetched; the login page fetches it
  through `Idno::container()`; it takes a key string and nothing of the request) on `symfony/rate-limiter`'s FIXED WINDOW policy with
  `CacheStorage` over the site's ONE cache pool (`Psr\Cache\CacheItemPoolInterface`; see "The cache"): 5 attempts (`LIMIT`) per 300
  seconds (`WINDOW_SECONDS`) for each ACCOUNT. `Login::postContent()` resolves the name typed FIRST (handle, then email: the
  database's own comparison, which on MySQL and MariaDB ignores case and accents, so `bén` and `BEN` are one handle) and counts
  under `LoginThrottle::keyForAccount($uuid)`: the handle and the email address of one account share a count, however they are
  spelled. A name no account has is counted under `keyForUnknownName($name)`: trimmed, NFKC-normalised (when `Normalizer` exists)
  and lower-cased with `mb_strtolower`. Keys are SHA-256 hashes (a name is never a cache key or file name). Accepted: because the count
  is for the account, someone can learn that a name exists by trying spellings (handles are public on profiles anyway). It is NOT per
  address: there are no trusted proxies yet, so behind a reverse proxy every client shares the proxy's address.
  `attempt($key)` RESERVES one attempt before the password is checked (so N parallel requests cannot get N guesses while the first
  is still in bcrypt) and is false when the window is full: that attempt is refused BEFORE the password is looked at, even a right
  one, with the same redirect as a failure and the message "Too many failed sign-in attempts ... Please wait a few minutes"; a
  successful login calls `reset()`, and so does a password reset (a member who was locked out can sign in at once). The window opens
  at the first attempt and its count is forgotten 300 seconds later; a refused attempt is not counted. Someone who knows a login
  name can therefore keep that account locked by repeating failures every five minutes; the member waits five minutes or resets
  the password. No lock is given to the limiter (a lock would need a store of its own), so concurrent cache writes can lose
  a few increments and a burst can get a few more than five attempts through. The limiter reads the real clock (it has no clock seam:
  `Tests/Unit/Security/LoginThrottleTest` moves the pool's clock, which decides when a window's state is forgotten, with a
  `MockClock` on an `ArrayAdapter`). With the cache disabled (a `NullAdapter`) nothing is stored and nothing is refused; a throttle
  that cannot be built or fails never refuses a login (one warning). The pool is pruned about once in every 300 attempts when it
  can be (`PruneableInterface`; an injectable random seam), so the entries of names never tried again do not pile up. An operator
  clears every count by deleting the pool directory (`cachepath`, else `<cache base>/pool`). Two installs with the same host name on
  one cache base share counts (see "The cache"). Every `PrivateSiteTestCase` site has a `cachepath` of its own (`IDNO_CACHEPATH`, in its
  temporary directory): the pool's namespace is the host WITHOUT the port, so sites on one loopback address would otherwise share their
  counts. Tests: `Tests/Unit/Security/LoginThrottleTest`, `Tests/Characterisation/LoginThrottlingTest`.
- Account settings (`Idno\Pages\Account\Settings`) ask for the member's CURRENT password (`current_password`) whenever the email address
  submitted differs from the stored one or a new password is given: it is checked with `checkPassword()` under the same throttle key as a
  login for the account (an attempt is reserved; a wrong one is a failed attempt and says the current password isn't right; the right one
  resets the count), and when it is not right NOTHING is changed (the name too). A request that changes neither needs no password. The form
  labels it and has `autocomplete="current-password"` (the new password `new-password`). A stolen session therefore cannot take the account
  over. Tests: `Tests/Characterisation/AccountSettingsCurrentPasswordTest`.
- A change of password ends the user's OTHER sessions. `User::setPassword()` (a real change) writes a random `password_generation`
  marker on the user; `Session::logUserOn()` and `refreshSessionUser()` (whenever it puts a different user's uuid into the session: a plugin's
  login) store in `$_SESSION['password_fingerprint']` the SHA-256 of that marker (nothing of
  the password or its hash is in the session); where the browser session's user is loaded (`Session::refreshCurrentSessionuser()`, once
  per request, from the user already loaded: no database statement is added, pinned by `UpgradeEndToEndTest::testTheHomepageStatementsArePinned`)
  the fingerprint is compared with the user's current marker and a mismatch logs the session off as a logout does (`logUserOff()`) and starts
  a fresh session as logout does (`startNewSession()`: a new id, no user, a new form token, the `secure` marker) with the message "Your
  password was changed, so you have been signed out here. Please sign in again." (the blocked-email logoff next to it starts a fresh session too,
  with no message). The marker is NOT the hash: `checkPassword()` replaces a hash of the same password at login (a cost
  upgrade) and leaves the marker alone, so that never ends another session. A save of a `User` writes `password` and the marker ONLY when
  `setPassword()` or `setPasswordHash()` or the hash upgrade changed them ON THAT OBJECT (private flags); otherwise an existing user takes both
  from the stored record just before it is written (one read per user save, never per request; a new user has no record), because `Entity::save()`
  writes the whole document and a `User` loaded before another request changed the password (an autosave) would write the old hash and marker
  back. So code that writes `$user->password` directly (not through `setPassword()` or `setPasswordHash($hash)`, which stores a ready hash and writes no
  marker) ends no sessions and is not stored by a save; the session's `save` listener stamps the new fingerprint only for a save
  that carried a password change (`hasUnsavedPasswordChange()`), so a stale object's save does not keep its session alive. Adoption:
  a session with no fingerprint (made before this release) is given the current one and stays ONLY when the user has no marker (no
  real change since markers began); when the user has a marker a session that cannot say it came after the change is logged off. The
  session that changes the password takes the new fingerprint in the same request and stays logged in; a user who has never
  changed their password since the marker was introduced has none (the fingerprint of an empty marker), so every session stays valid
  until the first real change; a password reset ends every session of the user (the reset does not log anybody on). Stateless (API)
  requests have no session fingerprint and are unaffected (the API key is the credential, and changing the password does not change
  it); the queue worker and `logUserOn()` in a test work as before. Tests: `Tests/Characterisation/PasswordChangeEndsOtherSessionsTest`,
  `Tests/Kernel/UserPasswordSaveTest`.
- Logging out (`Idno\Pages\Session\Logout`, form token required, POST only): the redirect goes through `Login::sanitizeRedirectUrl()`
  (the `Referer`, same-origin only, else the site's home page). `logUserOff()` destroys the session, then `Session::startNewSession()`
  begins another (a new id, nothing of the old session's data, user or form token: the token is replaced and the session seeded
  again) and the "You've signed out" message is added to THAT session, so the page the visitor lands on shows it once. A logout by a
  signed (API) request adds no message (it could land in a browser session the request carried). The answer
  sets the new session's cookie, which PHP uses in place of the expiring cookie `logUserOff()` sent (same name and attributes), and
  the old id is dead. Tests: `LoginFlowTest`.
- Redirect targets taken from user input must go through
  `\Idno\Pages\Session\Login::sanitizeRedirectUrl()` (same-origin only)
- Error page: an exception thrown while a page is being served goes to
  `Page::exception()`, which draws `templates/default/pages/500.html.twig`. The page shows
  the exception's message, and the whole exception (message and stack trace) when `debug`
  is set in the configuration. Both are passed through `htmlspecialchars()`, because a
  message can contain text taken from the request (the template autoescapes it). So an exception message is plain text:
  HTML in it is displayed, not rendered. `Tests/Core/ErrorPageTest.php` checks this.
- Exception messages, friendly and technical: `\Idno\Core\ExceptionMessage::forVisitor()`
  decides what a visitor is told about an exception, and the error page and the JSON shells
  both call it. A message is taken to be written for the visitor, and is shown, unless the
  exception's type says it is a technical one. A technical message is logged, and shown
  only when `debug` is set; otherwise the visitor is told only that something went wrong.
  A type is technical when it implements `\Idno\Exceptions\Technical`; when it is a PHP
  error (`\Error`) or a PHP warning turned into an exception (`\ErrorException`); when it,
  or a class it extends, belongs to a PHP extension or to a library in a `vendor/`
  directory (`\PDOException`, the MongoDB driver's exceptions, Twig's errors); or when it
  was thrown by a library's code, whatever its type. A type that implements
  `\Idno\Exceptions\Friendly` is never technical. So:
  - throw `\Idno\Exceptions\TechnicalException` (it extends `\RuntimeException`) for a
    message written for whoever maintains the site: one that names classes, identifiers,
    queries or paths, or repeats what another server or a driver said;
  - throw anything else (`\RuntimeException`, `SecurityException`,
    `ConfigurationException`) only with a message that any visitor may read. This is the
    default, so a message that should have been technical and is not marked is shown;
  - to show a message whose type would be taken as technical, throw
    `\Idno\Exceptions\FriendlyException` or implement `\Idno\Exceptions\Friendly`.

  Code that catches an exception and shows its message itself is not affected, except that
  a page which puts a caught exception's message in a session message (the visitor sees
  it) passes it through `ExceptionMessage::forVisitor()` first, and escapes the result (`htmlspecialchars()`: a session message is printed as markup, so any text from a member, the request, a remote server or an exception is escaped by whoever adds the message): the admin's test mail page
  and the StandardSiteSync pages (the AT Protocol client's exceptions are all
  `TechnicalException`s). `Tests/Core/ExceptionMessageCallSitesTest` pins those call sites.
  The core classes the rule marks as technical are the timers, `Service`, entity mutation
  and `GenericDataItem`, `GetTextTranslation` and the AT Protocol client; the old
  asynchronous queue and cache classes that were marked in 1.x no longer exist (the queue
  is on Messenger, the cache is a PSR-6 pool). Messages of this branch's own
  exceptions (`FileStoreException`, the portability services, the queue) are a fixed
  vocabulary with no path, identifier or driver text, so they stay plain exceptions that a
  visitor may read; keep it so. The installation (`Idno\Install`) runs
  before the site exists and does not use the rule: its messages are its own fixed sentences
  (a failed database connection is one of `DatabaseChoice::connectionProblem()`'s, never the driver's text).
  `Tests/Core/ExceptionMessageTest.php` checks the rule.
- Error responses in JSON: for the `json` and `jsonp` template types, `Page::exception()`
  answers with the page shell (`templates/json/shell.tpl.php`,
  `templates/jsonp/shell.tpl.php`), which adds an `exception` object to the response. It
  holds a `message` only: the exception's message, or, when that is a technical one, that
  something went wrong. The exception's `class`, `file` and `line` describe the server:
  `Page::exception()` logs them, and the shells add them to the response only when `debug`
  is set in the configuration. `Tests/Core/ExceptionResponseTest.php` checks this by running
  `Tests/Core/fixtures/exception_response.php` in its own PHP process.
- JSONP is readable by other sites: a page drawn with the `jsonp` template type
  (`templates/jsonp/shell.tpl.php`) is a script that other pages can load. The session cookie is `SameSite=Lax`, so a browser sends it with a script
  request only from a page of the same site (a sibling subdomain, say), not from another site; but a script from a same-site page, or a cookie
  with another policy, is enough to be careful. The rule stays: never put a secret in the template variables
  of a page unless the template type is exactly `json`. `Idno\Pages\Session\Login` gives a
  signed-in API client its API key that way (`api-token`, JSON over HTTPS only).
  `Tests/Pages/Session/LoginApiKeyTest.php` checks this. The form token is a secret too, and is never a template variable of a page
  nor a value of one (`FormToken::scrub()` in both shells; `SettingsRedactor` calls a key naming `csrf` or `xsrf` a secret, so the diagnostics
  report's `$_SESSION` has no token): `Tests/Characterisation/FormTokenNotPrintedTest` takes a session's real token from its HTML form and finds it in no JSON, JSONP,
  Markdown or diagnostics output. An `/account` or `/admin` page (with or without a trailing slash) asked for as JSONP answers the HTML settings shell with `Content-Type: text/html; charset=utf-8` and `X-Content-Type-Options: nosniff`: it is never a script.
- `FILTER_VALIDATE_URL` does not check the scheme: it accepts `javascript://...`. A plugin
  that stores an address a member typed (the Like plugin's bookmark, for example) keeps it
  only when `UrlValidator::httpUrl()` accepts it. `Tests/Core/MemberContentOutputTest.php`
  checks this for bookmarks, check-in place names and images embedded from a post's text.
- RSVPs (`IdnoPlugins/Event/RSVP.php`): the text is passed through `sanitize_html()` and
  the address of the event through `UrlValidator::httpUrl()` when an RSVP is saved, and
  again where they are printed (the `entity/RSVP` templates and `RSVP::getDescription()`,
  which is HTML given to feeds), because RSVPs stored earlier are not rewritten.
  `IdnoPlugins/Event/Tests/RSVPOutputTest.php` checks this.
- IndieAuth codes and tokens (`IdnoPlugins/IndiePub`): both come from
  `Pages\IndieAuth\Auth::generateSecret()` (`random_bytes()`, 64 hex characters); never
  build one from `rand()`, `mt_rand()`, `uniqid()` or the time. Authorization codes are
  issued by `Auth::issueCode()` and redeemed by `Auth::verifyCode()`, which accepts a code
  once and for `Auth::CODE_LIFETIME` (ten minutes, the most the IndieAuth specification
  allows), and spends it even when the rest of the request is wrong. Access tokens are
  issued by `Pages\IndieAuth\Token::issueToken()` and looked up by
  `Token::findUserForToken()`. Both are stored as the keys of `indieauth_codes` and
  `indieauth_tokens` on the user, in plain text, and are matched with `hash_equals()`
  (`Auth::matchingKey()`). Tokens do not expire, and tokens issued by earlier versions
  (32 hex characters) remain valid.
- IndieAuth PKCE and scope: a client may send `code_challenge` with
  `code_challenge_method=S256` (`Auth::challengeError()` refuses `plain` and malformed
  challenges). The challenge is stored with the code, and `Auth::verifyCode()` then
  requires the matching `code_verifier`. A client that sends no challenge is still
  accepted, but a `code_verifier` for a code issued without a challenge is refused. The
  token endpoint issues no token for a code granted with an empty scope; such a code can
  only be confirmed at the authorization endpoint. Scope is otherwise not enforced: the
  Micropub endpoint does not read it.
  `IdnoPlugins/IndiePub/Tests/IndieAuthCodeAndTokenTest.php` checks this.
- A file served in parts: `Idno\Pages\File\View::parseRange()` reads a `Range` header
  (one range of bytes: `first-last`, `first-` or `-suffix`, both positions inclusive; any
  other header is ignored and the whole file is sent; a range with no byte in the file is
  answered 416 with `Content-Range: bytes */<size>`), and the answer holds exactly the bytes
  its `Content-Length` and `Content-Range` name (`View::seekTo()`, then `View::streamRange()`).
  `Tests/Pages/File/RangeRequestTest.php` and `Tests/Characterisation/FileServingTest` check this.
- WebSub: `Pages\Pubsubhubbub\Callback` answers the hub's challenge, which comes from the
  request, as `text/plain` with `X-Content-Type-Options: nosniff`, so it is never run as HTML.
  `Tests/Pages/Pubsubhubbub/CallbackTest.php` checks this.
- No Content Security Policy header is sent by the site: only the upgrade page has one (a strict per-response
  nonce policy, `UpgradePage::contentSecurityPolicy()`), and no template sends one. Output escaping, above, is what
  keeps a value from becoming markup.
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
- No vendor messages: a site fetches no text from a third party to show its administrator. The
  `Idno\Core\Vendor` client, which POSTed the Idno version to idno.co and whose reply onboarding
  added to the session messages and the admin home page appended to the page as markup, is gone,
  with its `/service/vendor/messages` page and the `noping` setting. Don't add a notice, news or
  update feed from a fixed host; if one is ever wanted, it must be off until the site's owner
  configures it, fetched with `Webservice::safeSend()`, and escaped where it is printed.
  `Tests/Pages/Admin/VendorMessagesTest.php` checks this (the page a new administrator lands on, `/admin/welcome/`, makes no request and adds no message).
- Known limits of the security model (stated plainly; none is fixed by the authentication changes):
  - The API signature (`X-IDNO-SIGNATURE`) covers only the request URI (no method, body or time), so a signed request can be replayed.
  - The form token is accepted from the query string as well as the body, and lasts as long as the session.
  - Five POST handlers skip the form token check (`Idno/Pages/File/Upload.php`, `Idno/Pages/File/Picker.php`, `Idno/Pages/Entity/Autosave.php`,
    `IdnoPlugins/StaticPages/Pages/Admin/ReorderCategory.php`, `ReorderPage.php`) and rely on `SameSite=Lax` on the session cookie.
  - IndieAuth access tokens are stored in plain text, never expire, and their scope is not enforced by the Micropub endpoint.
  - `Page::isSSL()` trusts `X-Forwarded-Proto` from any client (there are no trusted proxies), and the login throttle counts by account
    for that reason.
  - Registration and the password reset request let someone learn whether a handle or an email address has an account.
  - A page served through a deprecated shell ends the request when it answers (`exit`), so code after `parent::getContent()` and `kernel.terminate` do not run for it; a listener of yours that `exit`s during a
    controller's page skips the work the page asked to be done after its response.
  - `framework.handle_all_throwables` is off: an `\Error` is the site's 500 page only inside a site page's controller or its own events; anywhere else it ends at `FatalErrorPage`.
  - The legacy route table decides what a plugin's registration does for a moved path, so a plugin's page registered after core's with another pattern does not take it, and a plugin that extends a deleted page
    class stops the site booting until it is disabled.
  - A site page does not answer a conditional request (`If-Modified-Since`) with a 304: `lastModifiedGatekeeper()` does nothing, and the `Last-Modified` a
    page asks for is added to the response after the controller returns. Its answer is always the whole page.
  - Every state-changing method of a site page needs the session's form token (or a signed API request): a site page cannot take a POST from another
    server (a webmention, a WebSub callback, an inbox), so such an endpoint stays a legacy page or a plain controller.
- The first administrator: an uninstalled site as shipped can be installed by whoever reaches it first (no code to
  paste); where the server shows an earlier site (`EarlierSite::signs()`), any web install, and adopting a database that
  already holds a site, needs the code `Idno\Install\InstallCode` writes on the server, but the site's first administrator can only be created by the browser that wrote the configuration
  (the `idno_setup` cookie against the hash in `configuration/setup-pending`: `Idno\Install\SetupToken`,
  `SetupGateListener`), by the one-time link `idno:setup:link` prints, or by `idno:admin:create` on the server.
  Registration never grants administrator, and a site with no user lets nobody register. Don't add another way
  to make an administrator on a site that has none, and never one that a request alone can reach. The installer
  takes no disk path from a request, writes its configuration file only if none exists, prints no password and
  no driver message, and its forms need the token of the cookie it gave the browser. See "Installation";
  `Tests/Unit/Install/WebInstallerTest` and `Tests/Install/WebInstallFlowTest` check this.
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
