# Unit Testing

Idno has a number of PHP unit tests that can be run in order to check that things are working correctly, and to protect against any code regression.

## Running from Github

Unit tests will automatically be run when you submit a pull request on GitHub. You should rectify any errors which are reported, as pull requests with errors will not be merged.

## Running unit tests on your local machine

The tests need a MySQL database and a running copy of the site, because some of them make real HTTP requests to it. The repository includes a test rig that provides both: MySQL in a Docker container, and PHP's built-in web server. You need Docker (or a compatible runtime such as OrbStack) and PHP 8.3 or later with the extensions listed in `composer.json`.

```bash
composer install
composer test:up     # start MySQL (127.0.0.1:33306) and the site (http://127.0.0.1:8089/)
composer test        # run the whole suite
composer test:down   # stop both
```

`composer test` runs PHPUnit with `IDNO_DOMAIN=127.0.0.1` and `IDNO_PORT=8089`. If you run `vendor/bin/phpunit` yourself, set those two environment variables to the same values, and run PHP with `-d error_reporting=22527` (everything except deprecation and strict notices, as in a stock production `php.ini`). Idno's `pedantic_mode`, which the test configuration turns on, throws an exception for every reported error, so reported deprecations would otherwise stop the suite. Extra PHPUnit arguments can be passed after `--`, for example `composer test -- IdnoPlugins/ActivityPub/Tests/`.

`composer test:up` creates the `known_unittest` database, loads the schema, and writes `configuration/config.ini` from `Tests/rig/config.ini`. It never overwrites an existing `configuration/config.ini`: if yours differs, it stops and tells you. Running it twice is safe.

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
