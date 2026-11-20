# Unit Testing

Idno has a number of PHP unit tests that can be run in order to check that things are working correctly, and to protect against any code regression.

## Running from Github

Unit tests will automatically be run when you submit a pull request on GitHub. You should rectify any errors which are reported, as pull requests with errors will not be merged.

Continuous integration (`.github/workflows/tests.yml`) runs, on PHP 8.4 and 8.5: the coding standard; the dependency-free unit tier (`composer test:unit`) as its own job; the whole suite on SQLite, through the test rig exactly as described below (`composer test:up`, then `composer test`, with no database server and no Docker); and the whole suite on MySQL 8.0 and on MariaDB 10.6, each with its database server as a service; and, on PHP 8.4 only, the MongoDB import's end-to-end test (`Tests/Import`) against a MongoDB server, which is the only test that needs one.

## Running unit tests on your local machine

The tests need a database and a running copy of the site, because some of them make real HTTP requests to it. The repository includes a test rig that provides both: a database, and PHP's built-in web server on http://127.0.0.1:8089/. By default the database is SQLite, so you need nothing but PHP 8.4 or later with the extensions listed in `composer.json` (`pdo_sqlite` included). A MySQL mode, which needs Docker (or a compatible runtime such as OrbStack), runs MySQL 8.0 or, with one environment variable, MariaDB 10.6, for changes that depend on them.

```bash
composer install
composer test:up           # start the rig on SQLite and the site (http://127.0.0.1:8089/)
composer test              # run the whole suite
composer test:down         # stop the rig and delete its database
composer console -- list   # run bin/console against the rig with the same PHP flags

composer test:up:mysql     # the same rig on MySQL 8 in Docker (127.0.0.1:33306)
IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql   # the same, on MariaDB 10.6
```

The Composer scripts that run PHPUnit or the rig (`test`, `test:unit`, `test:up`, `test:up:mysql`, `test:down`) have no time limit (`Composer\Config::disableProcessTimeout`): the whole suite on MySQL takes longer than Composer's default of 300 seconds.

How long it takes, roughly, on a laptop: `composer test:unit` a few seconds; `composer test` about a minute on SQLite and two to four minutes on MySQL or MariaDB (the upgrade tests build private 1.x databases and run web servers of their own); `composer test:up:mysql` from nothing ten to thirty seconds, most of it waiting for the database server to start (longer the first time, while Docker downloads the image).

`composer test` runs PHPUnit with `IDNO_DOMAIN=127.0.0.1` and `IDNO_PORT=8089`. If you run `vendor/bin/phpunit` yourself, set those two environment variables to the same values, and run PHP with `-d error_reporting=22527` (everything except deprecation and strict notices, as in a stock production `php.ini`). Idno's `pedantic_mode`, which the test configuration turns on, throws an exception for every reported error, so reported deprecations would otherwise stop the suite. Extra PHPUnit arguments can be passed after `--`, for example `composer test -- IdnoPlugins/ActivityPub/Tests/`.

## The two rig modes

Both modes run the same site: the same web server, port and router, `APP_ENV=test` and `APP_DEBUG=1`, `pedantic_mode` and the same plugins. Only the database settings differ (`Tests/rig/config.sqlite.ini` and `Tests/rig/config.ini`).

- **SQLite** (`composer test:up`, the default) needs no Docker and no database server. The database file (`site.sqlite`, with its `-wal` and `-shm` files) and the upload directory (`uploads/`) live in a directory under the system temporary directory that is specific to the checkout: `idno-test-rig-` followed by the first 12 hexadecimal digits of the SHA-256 hash of the checkout's real path. Two checkouts or git worktrees of the repository therefore never share a database. `composer test:up` creates the database file (running it twice keeps the file and your test data).
- **MySQL** (`composer test:up:mysql`) runs MySQL 8 in a Docker container on 127.0.0.1:33306 with the database `known_unittest`, created empty. Uploads go to the same per-checkout temporary directory. `IDNO_RIG_MYSQL_IMAGE` selects another image of MySQL or MariaDB, for example `IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql`; starting the rig on another image than the running one stops the running one first. Running `composer test:up:mysql` again keeps your test data only while the container is not recreated: a new image, or any change to the service in `Tests/rig/docker-compose.yml`, starts on an empty database.

In both modes, `composer test:up` creates the schema (the tables and their indexes) with `bin/console idno:upgrade` before it starts the web server, in the rig's environment; when the database has the schema already, the command does nothing.

Use the MySQL rig for anything that touches the SQL dialect, search (MySQL's full-text index, which SQLite does not have: on SQLite a search term matches as a substring), the schema migrations, the upgrade of a 1.x database, or how MySQL refuses data, and run the whole suite on it, and on MariaDB (`IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql`), before you submit such a change. MySQL and MariaDB differ in their JSON functions, which the new storage uses. Continuous integration runs SQLite, MySQL and MariaDB. On SQLite the tests that need MySQL or MariaDB are skipped, each with its reason (run `composer test -- -v` to see them): the tests on private MySQL databases of the rig's server (`Tests/Storage/JsonMySqlRecordStoreTest`, `SettingsRecordStoreMySqlTest`, `IndexedFieldsMySqlTest`, `SchemaMigratorMySqlTest`, `Tests/Kernel/InstallerSchemaTest`), the tests of the MySQL connection (`Tests/Storage/DatabaseConnectionTest`), the parts of `Tests/Kernel/RepositoryDataConciergeTest` that need MySQL, and the tests of the upgrade from Idno 1.x (`Tests/Upgrade/LegacyDataCopierTest`, `LegacyTablesTest`, and `UpgradeEndToEndTest` except its empty-database case): no 1.x site runs on SQLite. Everything else, the characterisation suite included, runs on every rig.

## Private test databases

A test never writes to the rig site's own tables. A test that needs a database of its own makes one with `Tests\Storage\PrivateDatabase`: `sqliteMemory()` (SQLite in memory) and `sqliteFile($path)` (a file in a temporary directory the test makes and removes) need nothing but PHP; `mysql($purpose)` creates the database `<site database>_<purpose>` on the rig's MySQL or MariaDB server, with the schema unless `migrate: false`, and skips the test on an SQLite rig. Drop it in `tearDown()` with `PrivateDatabase::drop()`, which refuses to drop the site's own database.

## The upgrade fixtures

The tests of the upgrade from Idno 1.x build a 1.x database on a private MySQL or MariaDB database with `Tests\Upgrade\LegacyFixture`: `create($purpose)` loads the 1.x schema (`Tests/Upgrade/fixtures/mysql-schema-2020120301.sql`, Idno 1.6.4; with the schema version `2026111401`, also `mysql-upgrade-2026111401.sql`; optionally every table converted to a utf8mb4 collation), `insert()` writes a record laid out as Idno 1.x wrote it, `insertMany()` many small ones, `insertSite()` a site the web server can serve once upgraded (its settings, an admin user and a post), and `prepareNewSchema()` creates the new tables as the upgrade does. `Tests/Upgrade/UpgradeEndToEndTest` runs PHP's built-in web server on a free port (never 8089) on such a database, and drives the upgrade page, the admin pages and the console over HTTP and in subprocesses. Run these on MySQL and on MariaDB after changing the upgrade.

## The unit tier

`composer test:unit` runs `Tests/Unit/` alone (`phpunit.unit.xml`), with nothing but PHP and the extensions `composer.json` requires: no rig, no database server, no configuration. It includes the record storage contract against the in-memory store and against SQLite in memory.

SQLite is also a supported database for small production sites (see `docs/install/instructions.md`). One thing to know about your rig: on SQLite, PHP sessions are files (SQLite cannot lock a session row), where on MySQL and MariaDB they are rows of `idno_sessions`. The database file uses built-in collations and functions only, so the `sqlite3` command line can read it.

Starting one mode while the other is running stops the other first, which deletes its database. `composer test:down` stops whichever mode is running, and is safe to run when nothing is. It deletes the per-checkout temporary directory (and, in MySQL mode, the container) and leaves `configuration/config.ini` in place. If the container cannot be stopped (Docker is not running), it says so and cleans up the rest anyway. A `composer test:up:mysql` that fails because Docker is not running leaves nothing behind that stops a plain `composer test:up` from working.

## configuration/config.ini

`composer test:up` writes `configuration/config.ini` for the mode you start. Every file the rig writes begins with this line:

```
; idno-test-rig: generated by the local test rig (Tests/rig/); composer test:up may replace this file.
```

The rig replaces a `configuration/config.ini` that begins with that line (or with `; Idno configuration for the local test rig (see Tests/rig/up.sh).`, which older versions of the rig wrote); that is how it switches modes. It never touches a `configuration/config.ini` without the marker, such as your own site's: it stops with an error before starting or stopping anything. Move your file aside, and put it back when you have finished testing.

## Test suites

The suite is split into three test suites in `phpunit.xml`: `Idno Basic Unit Tests` (`Tests/`), `Characterisation` (`Tests/Characterisation/`) and `Plugins` (`IdnoPlugins/*/Tests/`). Use `--testsuite` to run one of them.

You can also run the tests against your own Idno instance. In that case, set `IDNO_DOMAIN` (and `IDNO_PORT`) to point at it, for example ```export IDNO_DOMAIN='MY.IDNO.DOMAIN'```.

# Code style testing

Idno includes a PHP Codesniffer ruleset.

## Running style tests on your local machine

The code style rules are defined in `phpcs.xml` in the project root. To run the style tests, from the Idno root directory simply run:

```vendor/bin/phpcs```

PHP Code Sniffer works with individual folders, also. For example, to test the core `Idno` engine folder, you can run:

```vendor/bin/phpcs Idno```

Contents of the `external` and `vendor` folders are exempt from these rules and will not be tested.
