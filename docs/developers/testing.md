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

A test never compares two separately generated timestamps for equality (a time the code under test made against `time()` or `date()` in the test, or two times made at two moments): it asserts against a stored value, an injected clock, or a range.

`composer test` runs PHPUnit with `IDNO_DOMAIN=127.0.0.1` and `IDNO_PORT=8089`. If you run `vendor/bin/phpunit` yourself, set those two environment variables to the same values, and run PHP with `-d error_reporting=22527` (everything except deprecation and strict notices, as in a stock production `php.ini`). Idno's `pedantic_mode`, which the test configuration turns on, throws an exception for every reported error, so reported deprecations would otherwise stop the suite. Extra PHPUnit arguments can be passed after `--`, for example `composer test -- IdnoPlugins/ActivityPub/Tests/`.

## The two rig modes

Both modes run the same site: the same web server (PHP's built-in server with four workers, because the site makes requests to itself while it serves another, which would wait for ever with one), port and router, `APP_ENV=test` and `APP_DEBUG=1`, `pedantic_mode` and the same plugins. Only the database settings differ (`Tests/rig/config.sqlite.ini` and `Tests/rig/config.ini`).

- **SQLite** (`composer test:up`, the default) needs no Docker and no database server. The database file (`site.sqlite`, with its `-wal` and `-shm` files) and the upload directory (`uploads/`) live in a directory under the system temporary directory that is specific to the checkout: `idno-test-rig-` followed by the first 12 hexadecimal digits of the SHA-256 hash of the checkout's real path. Two checkouts or git worktrees of the repository therefore never share a database. `composer test:up` creates the database file (running it twice keeps the file and your test data).
- **MySQL** (`composer test:up:mysql`) runs MySQL 8 in a Docker container on 127.0.0.1:33306 with the database `known_unittest`, created empty. Uploads go to the same per-checkout temporary directory. `IDNO_RIG_MYSQL_IMAGE` selects another image of MySQL or MariaDB, for example `IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql`; starting the rig on another image than the running one stops the running one first. Running `composer test:up:mysql` again keeps your test data only while the container is not recreated: a new image, or any change to the service in `Tests/rig/docker-compose.yml`, starts on an empty database.

In both modes, `composer test:up` creates the schema (the tables and their indexes) with `bin/console idno:upgrade` before it starts the web server, in the rig's environment; when the database has the schema already, the command does nothing.

Use the MySQL rig for anything that touches the SQL dialect, search (MySQL's full-text index, which SQLite does not have: on SQLite a search term matches as a substring), the schema migrations, the upgrade of a 1.x database, or how MySQL refuses data, and run the whole suite on it, and on MariaDB (`IDNO_RIG_MYSQL_IMAGE=mariadb:10.6 composer test:up:mysql`), before you submit such a change. MySQL and MariaDB differ in their JSON functions, which the new storage uses. Continuous integration runs SQLite, MySQL and MariaDB. On SQLite the tests that need MySQL or MariaDB are skipped, each with its reason (run `composer test -- -v` to see them): the tests on private MySQL databases of the rig's server (`Tests/Storage/JsonMySqlRecordStoreTest`, `SettingsRecordStoreMySqlTest`, `IndexedFieldsMySqlTest`, `SchemaMigratorMySqlTest`, `Tests/Kernel/InstallerSchemaTest`), the tests of the MySQL connection (`Tests/Storage/DatabaseConnectionTest`), the parts of `Tests/Kernel/RepositoryDataConciergeTest` that need MySQL, and the tests of the upgrade from Idno 1.x (`Tests/Upgrade/LegacyDataCopierTest`, `LegacyTablesTest`, and `UpgradeEndToEndTest` except its empty-database case): no 1.x site runs on SQLite. Everything else, the characterisation suite included, runs on every rig.

## Test routes for outbound requests

In the test environment the site also serves `/_test/` routes (the controllers in `Tests/Fixtures/`) that tests use as the far end of the site's own outbound requests: `/_test/echo` answers with what the request carried (method, query, headers, body, form fields and uploaded files), `/_test/redirect/{status}` redirects, `/_test/slow`, `/_test/stream` and `/_test/sized` give slow and large bodies, `/_test/gzip?bytes=N` a gzip body of N zero bytes that is small on the wire, `/_test/sink/{name}` records every request it receives and `/_test/sink/{name}/log` returns and empties the record, and `/_test/page/{name}` is a page that advertises the sink as its webmention endpoint. `Tests/Characterisation/WebserviceWireTest` pins what `Idno\Core\Webservice` sends and returns, and `OutboundFlowsTest` what the site sends on its own (a webmention, a hub ping, a webhook). A test takes the site's URL from the configuration and asserts the headers it cares about by name, never the whole set. The routes check their input (a redirect only to `/_test/` paths, a sink name of plain characters, bounded sleeps and bodies), and `TestRoutesTest` pins that.

## No network in tests

No test may make a request to a real server. In the test environment (`APP_ENV=test`, the rig and PHPUnit) the site's
HTTP clients refuse every host but the site's own origin, with `No network in tests: <host>`, so a forgotten request fails
at once instead of reaching the internet. A test that needs another origin lists it in the `IDNO_TEST_HTTP_ALLOW`
environment variable (`host:port`, comma separated). To stand in for a remote server, use the `/_test/` routes above, or
pass a `Symfony\Component\HttpClient\MockHttpClient` to the code under test: `Webservice::useClient()` for
`Idno\Core\Webservice`, a constructor argument for a service, or the setter a class offers
(`IdnoPlugins\StandardSiteSync\ATProtoClient::useClient()`). A mock client records the method, URL, headers and body of each
request, and a test asserts the headers it cares about by name; for a token or a signature it asserts the shape, never the
value. A test must not look up DNS either: a class that does (`ATProtoClient` resolves `_atproto.<handle>` TXT records)
offers a seam for that. Tests may keep their own curl clients where they must talk to the rig from outside the site (the
characterisation suite). See [Making outbound HTTP requests](plugins/http.md).

## Private test databases

A test never writes to the rig site's own tables. A test that needs a database of its own makes one with `Tests\Storage\PrivateDatabase`: `sqliteMemory()` (SQLite in memory) and `sqliteFile($path)` (a file in a temporary directory the test makes and removes) need nothing but PHP; `mysql($purpose)` creates the database `<site database>_<purpose>` on the rig's MySQL or MariaDB server, with the schema unless `migrate: false`, and skips the test on an SQLite rig. Drop it in `tearDown()` with `PrivateDatabase::drop()`, which refuses to drop the site's own database.

## The upgrade fixtures

The tests of the upgrade from Idno 1.x build a 1.x database on a private MySQL or MariaDB database with `Tests\Upgrade\LegacyFixture`: `create($purpose)` loads the 1.x schema (`Tests/Upgrade/fixtures/mysql-schema-2020120301.sql`, Idno 1.6.4; with the schema version `2026111401`, also `mysql-upgrade-2026111401.sql`; optionally every table converted to a utf8mb4 collation), `insert()` writes a record laid out as Idno 1.x wrote it, `insertMany()` many small ones, `insertSite()` a site the web server can serve once upgraded (its settings, an admin user and a post), and `prepareNewSchema()` creates the new tables as the upgrade does. `Tests/Upgrade/UpgradeEndToEndTest` runs PHP's built-in web server on a free port (never 8089) on such a database, and drives the upgrade page, the admin pages and the console over HTTP and in subprocesses. Run these on MySQL and on MariaDB after changing the upgrade.

## A site of the test's own

A test that needs a site it controls (a theme, content it wrote, a worker) extends `Tests\Support\PrivateSiteTestCase`: each test starts PHP's built-in web server (four workers, on a free port, never 8089) on a private database of its own (a private database of the rig's MySQL or MariaDB server, or a file in a temporary directory on an SQLite rig; the schema comes from `idno:upgrade`) and an upload directory of its own, with its own `IDNO_URL` and `IDNO_TEST_HTTP_ALLOW` naming its own origin, and stops the server and drops the database afterwards, whatever the outcome. The site is SYNCHRONOUS, as the rig's is. A subclass adds to the site's environment by overriding `siteEnvironment()` (a theme: `['IDNO_THEME' => 'Twenty26']`; a mail server). The test reaches the site with `site()` (the characterisation client) and `baseUrl()`, registers and logs in users over HTTP (`registerUser()`, `publishStatus()`), runs a command (`console()`) and runs PHP code with the site booted (`runInSite()`: for what only the entity classes can do, such as writing entities with fixed ids and times).

## A site with the asynchronous event queue

The rig's site runs the synchronous queue, so nothing queued is left for a worker. A test that needs the asynchronous queue extends `Tests\Queue\AsyncSiteTestCase`, a `PrivateSiteTestCase` with `IDNO_EVENT_QUEUE=AsynchronousQueue`: each test starts PHP's built-in web server (four workers, on a free port, never 8089) on a private database of its own (a private database of the rig's MySQL or MariaDB server, or a file in a temporary directory on an SQLite rig; the schema comes from `idno:upgrade`), with the site as above. The test runs the worker (`runWorkerOnce()`: `bin/console service-event-queue --once` with the same environment), reads the requests the site sent to a sink (`sink()`) and the messages waiting in the queue or failed (`queuedEvents()`, `failedEvents()`). `Tests/Queue/QueuedDeliveryTest` pins what the queue delivers: a signed ActivityPub delivery to a follower's inbox, a webmention, who an event runs as, what a failing event does, and the worker's output and exit codes; `QueuedDeliveryBehindTlsTest` pins that the URLs of a delivery are those of a web request presented as TLS.

## Template rendering

What a site draws is pinned before an engine changes it, in three layers.

- `Tests/Templating/TemplateTypeRequestTest` pins which `?_t=` types a request may choose (every type directory, a missing type, a path), and that a `?_t[]=` value, which is not a string, asks for no type and is not a server error.
- `Tests/Kernel/TemplateContractTest` is the contract of the template cascade as `Idno\Core\DefaultTemplate` keeps it: which file a draw finds (theme, then plugins, the later registered first, then core; the type before the base; `default` as the fallback type), the order of prepends, extensions, replacements and pre-rendered content, what a missing or refused name draws, what variables a template sees, `theme.ini`'s `[extensions]` and `[prepend extensions]`, and the template bases of a command-line process. The fixtures are `Tests/Fixtures/Templates/`; each case runs in a subprocess (`Tests/Kernel/template_contract.php`) because the paths `Bonita\Main` keeps are process-static. A case changes only on purpose.
- The golden pages, `Tests/Templating/fixtures/pages/`: what a site renders for a visitor (`twenty26/`: Idno 2026, the look of a site with no theme; a site that names the `Twenty26` theme draws the same), what the logged-in administrator sees (`logged-in/`), and the output that is not a web page (`non-html/`: RSS, JSON Feed, JSON, JSONP, the ActivityPub actor document, KML and the password reset mail). `RenderedPagesNoThemeTest` and `RenderedPagesTest` start a site each (`RenderedSiteTestCase`: fixed handles, ids, titles and times, written by one script), fetch the pages and compare them, after `Tests\Templating\PageNormaliser` (which replaces only what is random, made from the time or from the site's address; `Tests/Unit/Templating/PageNormaliserTest` lists each replacement), with the golden files. The theme of a site is `IDNO_THEME`: `Twenty26` for `RenderedPagesTest` (the name Idno 2026 had as a theme, resolved to the built-in look); no theme, the rig's default, is the variable unset (set empty it is the same), and `RenderedPagesNoThemeTest` compares it with the same goldens. `Tests/Kernel/BuiltInLookTest` is the rest of the built-in look: a stored `Twenty26` or retired theme, `?_t=modern` and `?_t=classic`, a third-party `modern` template and theme, the drafts page, and a hostile webmention author.
- `Tests/Templating/fixtures/fallback-trace.php`: `traced`, for each type that is not a web page, the `classic` template files a real run drew (the entity templates of the look Idno 2026 replaced, which feeds and mail still draw), and `classic`, every file under `templates/classic/` of core and the bundled plugins (computed from the file system). A site started with `IDNO_TEST_TEMPLATE_TRACE=<file>` appends one line to the file for every template draw (type, name and the file found, or `-`, separated by tabs; `Tests\Fixtures\TemplateTrace`, a hook that `config/services_test.php` registers and that does nothing when the variable is unset).
- The mails, `non-html/mail-<name>.html` and `.txt`: every template of `templates/email` and `templates/email-text`, drawn in the site by `RenderedSiteTestCase::drawMails()` with the fixed variables of `MAIL_CASES` (the email shell reads the request's path for its link, and a console process has none, so the link is the same every time). The JSON goldens `json-account-settings-logged-in` and `json-status-edit-logged-in` pin the `csrf` and `formFields` of a form page.
- `PageNormaliser` makes an HTML page comparable between template engines as well as between runs: white space that touches a tag is one line break; `&amp;` and `&` are one character (but not where what follows could begin an entity: `&amp;lt;` and `&amp;copy` stay different from `&lt;` and `&copy`); `&#039;`, `&#39;`, `&apos;` and `'` are one in text and in a double-quoted attribute value only (in a single-quoted or unquoted value the entity keeps the attribute whole); in text, never inside a tag, `&quot;`, `&#34;` and `"` are one; the contents of `<script>`, `<style>`, `<pre>` and `<textarea>` are untouched.
- The equivalence of a Twig port with its original: `Tests/Templating/TwigPortEquivalenceTest` draws the `.tpl.php` that sits beside each Twig file in the same request as the Twig file (`Tests\Fixtures\TwigPortCheck`, enabled by `IDNO_TEST_TWIG_PORT_REPORT` in the site's environment), on the golden pages, the pages of its `EXTRA_PAGES` (and a page after a failed login, so that the message area has something in it), every mail and the templates of its `DRAWS`, and compares the normalised outputs (a newline at the very end of either is not a difference). While the original renders, every file it draws that has an original beside it is drawn from that original too, so the comparison is the whole old tree against the whole new one: a variable an old parent leaked to a child through `__()` and the ports drop is a difference. The shadow render is not isolated: it shares the session, the site and `header()` with the real render, and an original that exits ends the request. A difference must be listed in `Tests/Templating/fixtures/port-exceptions.php` by its hash (the failure prints the hash, with both outputs), with a reason and the test that pins the new output; an entry covers exactly the differences it lists and its own file only: the difference of a child that is listed is substituted (its original output replaced by its port's, when it is at least 16 bytes) in the parents' comparisons of the same request, so a parent needs no entry for what its child changed. A Twig file with an original beside it that no page or draw reached fails the test, as does a file in `PortReportChecker::MUST_VARY` whose original never drew different output; add a page to `EXTRA_PAGES`, or a draw to `DRAWS`, that reaches it. `Tests/Unit/Templating/TwigPortCheckTest` and `PortReportCheckerTest` show that the checks can fail. `Tests/Templating/fixtures/unported.php` lists the templates with no Twig file yet (`PortProgressTest`), and `raw-allowlist.php` every `|raw`, `autoescape false`, `filter raw`, `apply raw` and `source()` of a Twig file with its reason (`RawUsageTest`).
- `Tests/Templating/PagePerformanceTest` is a budget for pages, measured against the old engine in one run: `IDNO_TEST_PERFORMANCE=compare composer test -- Tests/Templating/PagePerformanceTest.php` starts two servers of the site (test environment, debug off, opcache on) that differ only in `IDNO_TEST_TWIG_BYPASS` (which draws each `.tpl.php` beside a Twig file in place of the Twig file), interleaves blocks of 40 requests of the home page and of a note's permalink five times, takes each server's smallest block median, and asserts the Twig server is at most 1.15 times the bypass server (both numbers and the ratio are printed). `ratio` is the same run whatever is ported; with nothing ported both servers draw the same files, so it shows the method's own noise (about 5%). The two servers run in the test environment with the debug flag off, not in production; that is acceptable because both sides are equal. Once the originals are deleted there is nothing to bypass, and a comparison with a stored baseline (`IDNO_TEST_PERFORMANCE=record` writes the Twig server's two numbers to a file in the system's temporary directory) is a number from another moment and not a reliable gate. So: run `ratio` one last time while the originals still exist and keep that ratio in the commit message of the deleting change; after the deletion compare only against a baseline recorded on that same commit in the same session (the minimum of several block medians), never the first baseline. Without the variable the test is skipped.

A golden is captured from the code it pins and does not change by itself. When a change is meant to alter one, run `IDNO_TEST_CAPTURE_GOLDENS=1 composer test -- Tests/Templating/`: it writes the files instead of comparing, then FAILS naming the files it wrote (a capture run is never green), fails when two looks render a shared golden differently, and is refused when `CI` is set. Review `git diff` of the files, run the tests again without the variable, and say in the commit which pages changed and why. The goldens are the same on SQLite, MySQL and MariaDB: capture on one and run the others.

## Testing mail

Mail is tested on the wire. `Tests\Fixtures\Smtp\ScriptedSmtpServer` is a real SMTP server in a subprocess on
127.0.0.1 and a free port (plain, STARTTLS, implicit TLS, logins, and chosen failures, including a server that
hangs); it records every line, and every message it accepts. Certificates are made per test class with the
openssl extension (`TestPki`); the client trusts the test authority only where a test says so, and verifies
certificates as it always does. In the test container a switch delivers mail to such a server instead of
recording it (`IDNO_TEST_MAIL_DELIVER=host:port`, and `IDNO_TEST_MAIL_CAFILE`); it replaces only the address the
production transport connects to, because the ports a site names (25, 465, 587) cannot be bound in a test. Which
port a setting MEANS is read from the transport the production factory built.

`Tests/Mail/SwiftMailerParityTest` proves that existing mail settings connect as they did under SwiftMailer. The
reference, `Tests/Mail/fixtures/swiftmailer-transcripts.php`, was captured once with SwiftMailer 6.3.0 against the
same server by `Tests/Mail/fixtures/capture-swiftmailer.php` (which installs SwiftMailer with Composer into a
temporary directory; it needs network access once, and the revision `9c7f3f8c2` of the repository); the test suite
only reads the fixture. `composer test:mail` delivers the site's mails to three Mailpit servers in Docker (plain,
STARTTLS, implicit TLS) and reads them back through Mailpit's API. Before a release, also send the admin's test
mail (Admin, Email) with the site's real provider's settings once.

## Acceptance tests

`Tests/Acceptance/` tests what a site owner does, from the first mail setting to a full export, with the pieces working together, where the other tests check each piece. Each walkthrough is one ordered scenario on one site, so what the owner has done (settings saved, a member invited, posts made, an export run) is still there at the next step. `Tests\Acceptance\Browser` drives the site over HTTP as a person does: it reads a form, posts it with its signed token and the person's session cookie, uploads a file and logs in. The queue worker runs as a subprocess, mail is read from a `ScriptedSmtpServer` the site really delivers to, and the archive is downloaded by its address and unzipped.

- `PhaseWalkthroughTest` is a new site: the administrator saves the mail settings on the admin page (a wrong password fails, the corrected one works), invites a member, the member resets a password and publishes, the site is read in French and in English, the administrator exports the whole site and imports a WordPress file, and the HSTS state of a host is shared between processes.
- `UpgradedSiteWalkthroughTest` upgrades a 1.x database over HTTP (MySQL and MariaDB only) and checks that the mail settings, the retired `cache` setting and the translations behave.
- `RoundTripTest` exports a site as WordPress (WXR) and imports the file into a second, empty site.

A walkthrough that finds a defect in production code does not hide it: the test asserts the behaviour that is wanted and calls `markTestIncomplete()` with the file and the cause when it sees the defect. Run `composer test -- Tests/Acceptance` on SQLite, MySQL and MariaDB. The checks that no test replaces (the real mail provider, a real WordPress export, a very large site, a non-English browser, the real worker supervisor, a real 1.x database) are listed in [the release checklist](release-checklist.md).

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

## Testing code that fetches URLs

Tests must not make requests to the public internet (see "No network in tests" above). A test of code that fetches a URL supplied by a remote party installs two mock clients with `Idno\Core\Webservice::useClient($siteClient, $remoteClient)`, built with Symfony's `MockHttpClient`, and asserts which of them received the request. Call `Webservice::useClient(null)` in `tearDown()`. The mock does not refuse private addresses, so a test can show that a call site sends its request through the remote client (the one that refuses private networks on every hop) and that its own first check (`UrlValidator::isSafeUrl()`) refuses an address on the server itself before any request is made. `Tests/Kernel/RemoteFetchCallSitesTest.php` and `Tests/Kernel/RemoteFetchRefusalTest.php` show how; what the remote client itself refuses is tested in `Tests/Kernel/WebserviceAdapterTest.php`.

# Code style testing

Idno includes a PHP Codesniffer ruleset.

## Running style tests on your local machine

The code style rules are defined in `phpcs.xml` in the project root. It checks the `Idno`, `IdnoPlugins` and `Tests` folders. To run the style tests, from the Idno root directory simply run:

```vendor/bin/phpcs```

GitHub runs `vendor/bin/phpcs -n`, which reports errors only and ignores warnings.

PHP Code Sniffer works with individual folders, also. For example, to test the core `Idno` engine folder, you can run:

```vendor/bin/phpcs Idno```

Contents of the `external` and `vendor` folders (relative to the project root) are exempt from these rules and will not be tested.
