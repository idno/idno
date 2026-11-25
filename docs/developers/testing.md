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

Logging in, reading the session cookie, reading a form's token and signing a request are the trait `Tests\Support\LogsIn` (`PrivateSiteTestCase` and `Tests\Acceptance\Browser` use it; a characterisation test adds `use \Tests\Support\LogsIn;`): `logIn($user)` (sets a new password for a test user and logs in through the login form), `loginCookie($handle, $password)`, `sessionCookie($response)` (the cookie's name is read from the site's configuration), `formToken($html, $action)` and `signedHeaders($user, $requestUri)` (the `X-IDNO-USERNAME` and `X-IDNO-SIGNATURE` headers; `signedHeadersFor()` for the user of a private site, with `PrivateSiteTestCase::apiKeyOf()`). A private site can add a user with `addUser()` (the registration form is closed once a site has its first user). A response that starts a session and ends it in the same request (every API request) sets two session cookies, the new id and its deletion: `sessionCookie()` returns the last one.

Authentication is pinned over HTTP, as the site answers today, by `Tests/Characterisation/AuthMatrixTest` (each page by each kind of caller, in one table), `LoginFlowTest`, `CsrfTest`, `SessionCookieTest` and `SessionDataAcrossLoginTest`. `LoginFlowTest` records the events of authentication (`login/success`, `login/failure`, `user/logon`, `user/auth/success`, ...) with `Tests\Fixtures\EventRecorder`: in the test environment, when `IDNO_TEST_RECORD_EVENTS` names a file in the site's environment, the container configures the legacy event dispatcher (built before the request is authenticated) to append a line for each of them. The page events `page/ready`, `page/head` and `page/get` are recorded the same way by `Tests\Fixtures\PageEventRecorder` when `IDNO_TEST_RECORD_PAGES` names a file (one JSON line each: the page's class, the body's first CSS class, the template type, `xhr`, `permalink`, the owner's handle; on `page/head` it also sends the header `X-Idno-Test-Page-Head: 1`, as a plugin's listener may); `Tests/Characterisation/PageContractTest` reads it, and `PortedPagesTest` and `StaticPagesHomepageTest` pin the answers of the home page, the streams, the feeds, search, tags and the small public endpoints.

Symfony Security's knowledge of who is making a request is pinned by `Tests/Characterisation/StatelessAuthenticationTest` (a signed request, a bearer token and a plugin's `user/auth/request` hook never write the session, end the session they carry or grant admin) and `TokenAgreementTest` (`/_test/whoami` agrees with `/currentUser` for every caller, in the worker and in-process), `Tests/Kernel/ControllerAuthorizationTest` (`#[IsGranted]` and the 403 answers, with and without the legacy application), `SecurityTrimTest` (what the bundle registers) and `SessionStatelessTest`. `Tests\Fixtures\AuthHook` stands in for a plugin that authenticates on `user/auth/request` (active when `IDNO_TEST_AUTH_HOOK=1`; the header `X-Test-Plugin-Auth: <return|call|legacy>:<handle>` picks the user and the way).

The rig's site runs the synchronous queue, so nothing queued is left for a worker. A test that needs the asynchronous queue extends `Tests\Queue\AsyncSiteTestCase`, a `PrivateSiteTestCase` with `IDNO_EVENT_QUEUE=AsynchronousQueue`: each test starts PHP's built-in web server (four workers, on a free port, never 8089) on a private database of its own (a private database of the rig's MySQL or MariaDB server, or a file in a temporary directory on an SQLite rig; the schema comes from `idno:upgrade`), with the site as above. The test runs the worker (`runWorkerOnce()`: `bin/console service-event-queue --once` with the same environment), reads the requests the site sent to a sink (`sink()`) and the messages waiting in the queue or failed (`queuedEvents()`, `failedEvents()`). `Tests/Queue/QueuedDeliveryTest` pins what the queue delivers: a signed ActivityPub delivery to a follower's inbox, a webmention, who an event runs as, what a failing event does, and the worker's output and exit codes; `QueuedDeliveryBehindTlsTest` pins that the URLs of a delivery are those of a web request presented as TLS.

## Template rendering

What a site draws is pinned before an engine changes it, in three layers.

- `Tests/Templating/TemplateTypeRequestTest` pins which `?_t=` types a request may choose (every type directory, a missing type, a path), and that a `?_t[]=` value, which is not a string, asks for no type and is not a server error.
- `Tests/Kernel/TemplateContractTest` is the contract of the template cascade as `Idno\Core\DefaultTemplate` keeps it: which file a draw finds (theme, then plugins, the later registered first, then core; the type before the base; `default` as the fallback type), the order of prepends, extensions, replacements and pre-rendered content, what a missing or refused name draws, what variables a template sees, `theme.ini`'s `[extensions]` and `[prepend extensions]`, and the template bases of a command-line process. The fixtures are `Tests/Fixtures/Templates/`; each case runs in a subprocess (`Tests/Kernel/template_contract.php`) because the paths `Bonita\Main` keeps are process-static. A case changes only on purpose.
- The golden pages, `Tests/Templating/fixtures/pages/`: what a site renders for a visitor (`twenty26/`: Idno 2026, the look of a site with no theme; a site that names the `Twenty26` theme draws the same), what the logged-in administrator sees (`logged-in/`), and the output that is not a web page (`non-html/`: RSS, JSON Feed, JSON, JSONP, the ActivityPub actor document, KML and the password reset mail). `RenderedPagesNoThemeTest` and `RenderedPagesTest` start a site each (`RenderedSiteTestCase`: fixed handles, ids, titles and times, written by one script), fetch the pages and compare them, after `Tests\Templating\PageNormaliser` (which replaces only what is random, made from the time or from the site's address; `Tests/Unit/Templating/PageNormaliserTest` lists each replacement), with the golden files. The theme of a site is `IDNO_THEME`: `Twenty26` for `RenderedPagesTest` (the name Idno 2026 had as a theme, resolved to the built-in look); no theme, the rig's default, is the variable unset (set empty it is the same), and `RenderedPagesNoThemeTest` compares it with the same goldens. `Tests/Kernel/BuiltInLookTest` is the rest of the built-in look: a stored `Twenty26` or retired theme, `?_t=modern` and `?_t=classic`, a third-party `modern` template and theme, the drafts page, and a hostile webmention author.
- `Tests/Templating/fixtures/fallback-trace.php`: `traced`, for each type that is not a web page, the `classic` template files a real run drew (the entity templates of the look Idno 2026 replaced, which feeds and mail still draw), and `classic`, every file under `templates/classic/` of core and the bundled plugins (computed from the file system). A site started with `IDNO_TEST_TEMPLATE_TRACE=<file>` appends one line to the file for every template draw (type, name and the file found, or `-`, separated by tabs; `Tests\Fixtures\TemplateTrace`, a hook that `config/services_test.php` registers and that does nothing when the variable is unset).
- The mails, `non-html/mail-<name>.html` and `.txt`: every template of `templates/email` and `templates/email-text`, drawn in the site by `RenderedSiteTestCase::drawMails()` with the fixed variables of `MAIL_CASES` (the email shell reads the request's path for its link, and a console process has none, so the link is the same every time). The JSON goldens `json-account-settings-logged-in` and `json-status-edit-logged-in` pin the `csrf` and `formFields` of a form page.
- `PageNormaliser` makes a page comparable between runs and databases: it replaces what is random or made from the time or the site's address, and, in an HTML page, white space that touches a tag is one line break and the contents of `<script>`, `<style>`, `<pre>` and `<textarea>` are untouched. It does NOT fold the several spellings of a character (`&amp;` and `&`, `&#039;` and `'`, `&quot;` and `"`): a golden records how a template escapes, so a change in escaping is a difference.
- The draw cases are goldens too: `TemplateDrawCasesTest` saves the normalised output of each labelled case (every `draw*Cases()` group, the mails that have no golden page, and `DRAWS`) as a file in `Tests/Templating/fixtures/draws/` (`<group>--<case label>.html`, `.txt` for the plain text of a mail; `*.json` is ignored by git, so never that extension) and compares it on every run; `IDNO_TEST_CAPTURE_GOLDENS=1` writes them with the same rules as the pages (the run fails, naming the files; refused when `CI` is set).
- `Tests/Templating/JsonKeysTest` requests `?_t=json` of every page of the visitor, member and administrator lists the templating tests use and pins the sorted top-level keys of each answer in `Tests/Templating/fixtures/json-keys.php` (a page that is not JSON is recorded by its status); it also fails for a key, at any depth, that `SettingsRedactor` calls a secret, except the form token every form page carries (`SECRET_NAMED_KEYS_ALLOWED` lists exceptions with a reason). A new key on a page is a decision: capture the file and review the diff.
- `Tests/Templating/CanarySweepTest` creates each bundled content type and an annotation of each kind with `"><x-canary a='1` in every string a member or a remote party can set, requests the streams, the permalinks and the owner's edit pages, and fails when the parsed page (`Dom\HTMLDocument`) holds an `x-canary` element or an attribute that the canary started.
- The Twig templates of core and the bundled plugins are proven by the golden pages and by tests that pin what a golden does not draw; nothing compares a template with another rendering. `Tests/Unit/Templating/CoreTemplatesAreTwigTest` fails for a `.tpl.php` left under a `default`, `email` or `email-text` directory of core or a bundled plugin, and checks that every name with a Twig file resolves to it. `Tests/Templating/TemplateDrawCasesTest` is a smoke test of what the golden pages do not reach: it visits further pages (the `EXTRA_PAGES`: admin, account, onboarding, error and empty pages, as a visitor, the administrator, a member and a new user) and draws in the site every mail and the cases for hostile values, empty states and plugin states (the `DRAWS` and the `draw*Cases()` methods). Every page must answer below 500, every case must draw without an error, and every Twig file of core and the bundled plugins must have been drawn at least once (read from the template trace, `IDNO_TEST_TEMPLATE_TRACE`): add a page to `EXTRA_PAGES`, or a case, that reaches a template it does not. The deliberate differences of the Twig look from the look it replaced, and the hostile values every template must escape, are asserted directly, mostly in `TemplateEscapingTest`, `FormControlsTest` and `UserSearchActionLinksTest`. `raw-allowlist.php` lists every `|raw`, `autoescape false`, `filter raw`, `apply raw` and `source()` of a Twig file with its reason (`RawUsageTest`).
- `Tests/Templating/PagePerformanceTest` is a tool for a change that may make pages slower, not a gate. It starts a server of the site (test environment, debug off, opcache on), warms the home page and a note's permalink, times each in five blocks of 40 requests and takes the smallest block median. `IDNO_TEST_PERFORMANCE=record composer test -- Tests/Templating/PagePerformanceTest.php` writes the two numbers to a file in the system's temporary directory; `IDNO_TEST_PERFORMANCE=compare` asserts that each is at most 1.20 times the recorded one and prints both numbers and the ratio. A baseline is a number from one moment of one machine: record it on the commit before your change, in the same session, compare after it, and repeat a failing comparison before believing it. When the home page and a note's permalink were last measured against the old engine, before its templates were deleted, the medians were 1.181 times (the home page) and 1.143 times (a note's permalink) the old engine's, and single runs between 1.136 and 1.202. Without the variable the test is skipped.

A golden is captured from the code it pins and does not change by itself. When a change is meant to alter one, run `IDNO_TEST_CAPTURE_GOLDENS=1 composer test -- Tests/Templating/`: it writes the files instead of comparing, then FAILS naming the files it wrote (a capture run is never green), fails when two looks render a shared golden differently, and is refused when `CI` is set. Review `git diff` of the files, run the tests again without the variable, and say in the commit which pages changed and why. The goldens are the same on SQLite, MySQL and MariaDB: capture on one and run the others.

- Recorded answers (`Tests\Support\RecordedAnswers`, a trait): the status, headers and body of a page, normalised and kept as a text file in `Tests/Characterisation/fixtures/answers/<group>/<case>.txt`, so that a controller that replaces a legacy page is held to what the legacy page answered. A test calls `assertRecordedAnswer($group, $case, $response)` and `assertRecordedAnswersComplete()` from `tearDownAfterClass()`; a case named in the class's `CHANGED` constant (case => reason) is neither compared nor written, and the test asserts it itself. A file is `status <n>`, each header by lower-case name (sorted, values in the order received; `date`, `set-cookie`, `x-idno-build-fingerprint`, `connection`, `keep-alive`, `host` and a foreign `x-powered-by` left out; an `expires` is `<past>` or `<future: N days from the request>`), a blank line and the body through `PageNormaliser`. The rule for a recording: seed every entity, file, annotation and setting it shows with fixed `_id`, uuid, slug, `created` and `updated`; capture on the commit before the change (`IDNO_TEST_CAPTURE_GOLDENS=1`: the run writes and then FAILS naming the files; refused when `CI` is set); capture twice and require the second to equal the first (`IDNO_TEST_CAPTURE_GOLDENS=verify` compares two captures in one run and writes only when they match); commit the recordings in a commit of their own.

## Testing pages that controllers serve

A site page (a controller marked `#[SitePage]`) is tested against a real legacy page. `Tests\Fixtures\SitePageFixtureController` is a controller page and `Tests\Fixtures\LegacyDrawPage`, `LegacyPlainPage`, `LegacyAccountDrawPage` and `LegacyAdminDrawPage` are legacy pages that do the same (the fixtures are routed only in the test environment); `SitePageLifecycleEquivalenceTest` and `SitePageDrawingTest` ask both the same question and compare the answers whole. `Tests\Fixtures\TestEventHooks` registers the rest, switched by the environment of the site a test starts: `IDNO_TEST_CLAIM_ROUTES` (a plugin's page on a moved path: `identical`, `hijackbroad`, `moved-exact` and the others the tests name), `IDNO_TEST_HOMEPAGE_LISTENERS`, `IDNO_TEST_RECORD_PAGES` (records the page events) and `IDNO_TEST_DRAW_HEADERS`.

A test that needs a site of its own extends `Tests\Support\PrivateSiteTestCase`. Beyond `siteEnvironment()` it overrides `siteIni()` for the site's PHP settings (a test that counts session files points `session.save_path` at its own `sessionDirectory()`, because the system's directory is shared by every process; on MySQL and MariaDB the sessions are rows of `idno_sessions` in the site's database, which `siteConnection()` opens). `startExtraServer()` starts a second server on the same database: `IDNO_TEST_SERVER_NO_PATH_INFO=1` makes it give the legacy router only the raw request path, as Apache and nginx do, and `IDNO_TEST_SUBDIRECTORY=blog` serves the front controller as `/blog/index.php`.

The whole suite runs in one PHP process. `Tests\Support\MemoryGuard` fails the run when its peak passes 380 MiB and names the five test classes that left the most memory behind: release a kernel, a server or a large value in `tearDown()`. To measure, run `IDNO_TEST_MEMORY_REPORT=/tmp/memory.json composer test`: the file holds the peak and, for each class, the memory at its start and what it kept. `IDNO_TEST_MEMORY_LIMIT_MB=<n>` changes the limit (0 turns the failure off).

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
