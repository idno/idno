# Unit Testing

Idno has a number of PHP unit tests that can be run in order to check that things are working correctly, and to protect against any code regression.

## Running from Github

Unit tests will automatically be run when you submit a pull request on GitHub. You should rectify any errors which are reported, as pull requests with errors will not be merged.

## Running unit tests on your local machine

First, ensure [PHPUnit](https://phpunit.de/) is installed. (Installation varies by system and is beyond the scope of this documentation.)

To run all the tests, from the Idno root directory simply run ```phpunit```.

Some tests require a connection to your local Idno instance, so if this is anything other than *http://localhost*, you need to set the ```KNOWN_DOMAIN``` environment variable.

Before running the unit test, set this by running ```export KNOWN_DOMAIN='MY.KNOWN.DOMAIN'```

## Testing code that fetches URLs

Tests must not make requests to the public internet. A test of code that fetches a URL should extend `Tests\OutboundHttpTestCase`, which starts a small web server on 127.0.0.1 for the test class (`Tests\LocalHttpServer`; the pages it serves are listed in `Tests/Core/fixtures/http_server.php`).

Idno refuses to fetch addresses on the server itself on behalf of a remote party, so a test that needs a "public" site calls `$this->useLocalServerAsProxy()` and uses addresses in `203.0.113.0/24`, a range reserved for documentation. Every request Idno makes is then answered by the local server, and `static::$server->requests()` lists what it was asked for. `Tests/Core/WebserviceTest.php` shows how.

# Code style testing

Idno includes a PHP Codesniffer ruleset.

## Running style tests on your local machine

The code style rules are defined in `phpcs.xml` in the project root. To run the style tests, from the Idno root directory simply run:

```vendor/bin/phpcs```

PHP Code Sniffer works with individual folders, also. For example, to test the core `Idno` engine folder, you can run:

```vendor/bin/phpcs Idno```

Contents of the `external` and `vendor` folders are exempt from these rules and will not be tested.
