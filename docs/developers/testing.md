# Unit Testing

Idno has a number of PHP unit tests that can be run in order to check that things are working correctly, and to protect against any code regression.

## Running from Github

Unit tests will automatically be run when you submit a pull request on GitHub. You should rectify any errors which are reported, as pull requests with errors will not be merged.

## Running unit tests on your local machine

PHPUnit is installed by `composer install`. The tests need a configured database and a web server serving your checkout; `.github/workflows/tests.yml` shows how GitHub sets these up (it copies `Tests/build/config_mysql.ini` or `Tests/build/config_default.ini` to `configuration/config.ini`, and serves the checkout on port 80 with `Tests/build/router.php`).

To run all the tests (the core tests in `Tests/` and each plugin's tests in `IdnoPlugins/*/Tests/`), from the Idno root directory run ```vendor/bin/phpunit```. To run one plugin's tests, pass its folder, e.g. ```vendor/bin/phpunit IdnoPlugins/ActivityPub/Tests/```.

Some tests require a connection to your local Idno instance, so if this is anything other than *http://localhost* on port 80, you need to set the ```IDNO_DOMAIN``` and ```IDNO_PORT``` environment variables (the older ```KNOWN_DOMAIN``` and ```KNOWN_PORT``` still work).

Before running the unit tests, set these by running, for example, ```export IDNO_DOMAIN='my.idno.domain'```

## Testing code that fetches URLs

Tests must not make requests to the public internet. A test of code that fetches a URL should extend `Tests\OutboundHttpTestCase`, which starts a small web server on 127.0.0.1 for the test class (`Tests\LocalHttpServer`; the pages it serves are listed in `Tests/Core/fixtures/http_server.php`).

Idno refuses to fetch addresses on the server itself on behalf of a remote party, so a test that needs a "public" site calls `$this->useLocalServerAsProxy()` and uses addresses in `203.0.113.0/24`, a range reserved for documentation. Every request Idno makes is then answered by the local server, and `static::$server->requests()` lists what it was asked for. `Tests/Core/WebserviceTest.php` shows how.

# Code style testing

Idno includes a PHP Codesniffer ruleset.

## Running style tests on your local machine

The code style rules are defined in `phpcs.xml` in the project root. It checks the `Idno`, `IdnoPlugins` and `Tests` folders. To run the style tests, from the Idno root directory simply run:

```vendor/bin/phpcs```

GitHub runs `vendor/bin/phpcs -n`, which reports errors only and ignores warnings.

PHP Code Sniffer works with individual folders, also. For example, to test the core `Idno` engine folder, you can run:

```vendor/bin/phpcs Idno```

Contents of the `external` and `vendor` folders (relative to the project root) are exempt from these rules and will not be tested.
