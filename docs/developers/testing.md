# Unit Testing

Idno has a number of PHP unit tests that can be run in order to check that things are working correctly, and to protect against any code regression.

## Running from Github

Unit tests will automatically be run when you submit a pull request on GitHub. You should rectify any errors which are reported, as pull requests with errors will not be merged.

Continuous integration (`.github/workflows/tests.yml`) runs, on PHP 8.4 and 8.5: the coding standard; the dependency-free unit tier (`composer test:unit`) as its own job; the whole suite on SQLite, through the test rig exactly as described below (`composer test:up`, then `composer test`, with no database server and no Docker); and the whole suite on MySQL and on MongoDB, each with its database server as a service.

## Running unit tests on your local machine

The tests need a database and a running copy of the site, because some of them make real HTTP requests to it. The repository includes a test rig that provides both: a database, and PHP's built-in web server on http://127.0.0.1:8089/. By default the database is SQLite, so you need nothing but PHP 8.4 or later with the extensions listed in `composer.json` (`pdo_sqlite` included). A MySQL mode, which needs Docker (or a compatible runtime such as OrbStack), is there for changes that depend on MySQL.

```bash
composer install
composer test:up           # start the rig on SQLite and the site (http://127.0.0.1:8089/)
composer test              # run the whole suite
composer test:down         # stop the rig and delete its database
composer console -- list   # run bin/console against the rig with the same PHP flags

composer test:up:mysql     # the same rig on MySQL 8 in Docker (127.0.0.1:33306)
```

`composer test` runs PHPUnit with `IDNO_DOMAIN=127.0.0.1` and `IDNO_PORT=8089`. If you run `vendor/bin/phpunit` yourself, set those two environment variables to the same values, and run PHP with `-d error_reporting=22527` (everything except deprecation and strict notices, as in a stock production `php.ini`). Idno's `pedantic_mode`, which the test configuration turns on, throws an exception for every reported error, so reported deprecations would otherwise stop the suite. Extra PHPUnit arguments can be passed after `--`, for example `composer test -- IdnoPlugins/ActivityPub/Tests/`.

## The two rig modes

Both modes run the same site: the same web server, port and router, `APP_ENV=test` and `APP_DEBUG=1`, `pedantic_mode` and the same plugins. Only the database settings differ (`Tests/rig/config.sqlite.ini` and `Tests/rig/config.ini`).

- **SQLite** (`composer test:up`, the default) needs no Docker and no database server. The database file (`site.sqlite`, with its `-wal` and `-shm` files) and the upload directory (`uploads/`) live in a directory under the system temporary directory that is specific to the checkout: `idno-test-rig-` followed by the first 12 hexadecimal digits of the SHA-256 hash of the checkout's real path. Two checkouts or git worktrees of the repository therefore never share a database. `composer test:up` creates the database and loads `warmup/schemas/sqlite/sqlite.sql` into it (`Tests/rig/sqlite_schema.php`), unless it already has tables, so running it twice keeps your test data.
- **MySQL** (`composer test:up:mysql`) runs MySQL 8 in a Docker container on 127.0.0.1:33306 with the database `known_unittest`, and loads `warmup/schemas/mysql/mysql.sql` into an empty database. Uploads go to the same per-checkout temporary directory.

Use the MySQL rig for anything that touches the SQL dialect, search (MySQL's full-text index, which SQLite does not have: on SQLite a search term matches as a substring), the schema upgrader, or how MySQL refuses data (for example four-byte characters in its `utf8mb3` tables), and run the whole suite on it before you submit such a change. Continuous integration runs SQLite, MySQL and MongoDB. On SQLite the tests that need MySQL are skipped, each with its reason (run `composer test -- -v` to see them): the record storage contract bound to the rig's MySQL database (`Tests/Storage/DbalMySqlRecordStoreTest`), and the parts of `Tests/Kernel/RepositoryDataConciergeTest` that need MySQL. Everything else, the characterisation suite included, runs on both.

## The unit tier

`composer test:unit` runs `Tests/Unit/` alone (`phpunit.unit.xml`), with nothing but PHP and the extensions `composer.json` requires: no rig, no database server, no configuration. It includes the record storage contract against the in-memory store and against SQLite in memory.

SQLite is supported for tests and development now; it is to become a supported production database in a later release. Two limits to know about while it runs your rig: PHP sessions on SQLite are not locked (two simultaneous requests of one session can overwrite each other's session data), and the database file can only be read through a connection Idno has set up (it uses a collation the `sqlite3` command line does not have).

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
