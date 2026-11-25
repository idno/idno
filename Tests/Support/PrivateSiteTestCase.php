<?php

declare(strict_types=1);

namespace Tests\Support;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Platform\SqliteConnectionSetup;
use PHPUnit\Framework\TestCase;
use Tests\Characterisation\HttpSiteClient;
use Tests\Characterisation\SiteClient;
use Tests\Storage\PrivateDatabase;

/**
 * A site of the test's own: the test starts PHP's built-in web server (`php -S`, four workers: the site calls
 * itself, for the sink and for the worker's service calls) on a free port, never the rig's 8089, on a private
 * database (a private database of the rig's MySQL or MariaDB server, or a file in a temporary directory on an
 * SQLite rig) whose schema `idno:upgrade` creates, with an upload directory of its own. The site is
 * SYNCHRONOUS (the rig's queue) unless a subclass says otherwise: Tests\Queue\AsyncSiteTestCase adds the
 * asynchronous event queue and what a test of it needs.
 *
 * The site's environment is the rig's kernel settings, the private database, its own IDNO_URL and
 * IDNO_TEST_HTTP_ALLOW naming its own origin, plus what siteEnvironment() says (a theme, a mail server). The
 * helper scripts that boot the site (runInSite(), console()) get the same environment. The server is stopped (a
 * bounded wait, then SIGKILL) and the database dropped in tearDown(), whatever the outcome.
 *
 * A class whose tests only READ the site may share one (`protected const SHARED_SITE = true`): the first test starts it,
 * seedSharedSite() writes what every test reads, and the site, its extra servers and its database stay until
 * tearDownAfterClass(). After every test the site's tables, settings and upload directory are compared with what they were
 * after seeding: a test that changed them fails with a message saying so and the site is rebuilt for the next test; the site's
 * cache pool is emptied; and every test of the class must ask for the same environment (siteEnvironment(), additionalEnvironment(),
 * siteIni()). A class that is not shared is as it always was.
 */
abstract class PrivateSiteTestCase extends TestCase
{
    use LogsIn;

    /** Whether the tests of the class share one site (they only read it): see the class's comment. */
    protected const SHARED_SITE = false;

    /**
     * The shared sites, by test class: directory, uploadPath, url, dbname, serverLog, server, connection, environment (the key the
     * class asked for), fingerprint and extra (key => [server, url, log]).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $shared = [];

    /** @var string the key of the environment this test asked for, when the site is shared */
    private string $environmentKey = '';

    /** Whether this test's site is shared: it is stopped by tearDownAfterClass(), not by tearDown(). */
    private bool $usesSharedSite = false;

    /** Whether setUp() finished with a shared site in hand (one that failed part-way is cleaned up as a site of its own). */
    private bool $sharedReady = false;

    /** Seconds to wait for the web server to listen. */
    private const START_SECONDS = 20;

    /** Seconds a console command may run before it is killed. */
    private const COMMAND_SECONDS = 120;

    protected ?Connection $connection = null;

    private string $directory = '';

    /** The site's own upload directory (under $directory): the rig's shared one is never written to. */
    private string $uploadPath = '';

    /** @var list<string> the files in the rig's own upload directory when the test began */
    private array $rigUploadsBefore = [];

    private string $url = '';

    private string $dbname = '';

    private string $serverLog = '';

    /** @var resource|null */
    private $server = null;

    /** @var list<resource> the servers startExtraServer() made */
    private array $extraServers = [];

    private ?SiteClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rigUploadsBefore = self::filesUnder((string) Idno::site()->config()->uploadpath);
        $this->usesSharedSite = static::SHARED_SITE;
        if ($this->usesSharedSite) {
            $this->environmentKey = $this->environmentKey();
            if (isset(self::$shared[static::class])) {
                $this->adopt(self::$shared[static::class]);
                $this->sharedReady = true;

                return;
            }
        }
        $this->start();
        if ($this->usesSharedSite) {
            // A request makes the site's own record when it is the first one a site with a user answers: it is made before the fingerprint
            // (before the seed, and again after it, for a seed that adds the first user), which must not change when a test asks.
            $this->site()->request('GET', '');
            $this->seedSharedSite();
            $this->site()->request('GET', '');
            self::$shared[static::class] = [
                'directory' => $this->directory, 'uploadPath' => $this->uploadPath, 'url' => $this->url, 'dbname' => $this->dbname,
                'serverLog' => $this->serverLog, 'server' => $this->server, 'connection' => $this->connection,
                'environment' => $this->environmentKey, 'fingerprint' => $this->siteFingerprint(), 'extra' => [],
            ];
            $this->server = null;
            $this->sharedReady = true;
        }
    }

    /**
     * Writes what every test of a class that shares its site reads (SHARED_SITE): called once, on the first test's instance, after the site
     * has started and answered one request. A test class overrides it; the default writes nothing.
     */
    protected function seedSharedSite(): void
    {
    }

    /**
     * A hash of what a test can change of the site: every row of the three collections, the settings and the names and sizes of the files in
     * the upload directory (never the sessions, the queue, the migrations or the upgrade's bookkeeping, which a request writes by itself).
     */
    protected function siteFingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach (['idno_entities', 'idno_reader', 'idno_site'] as $table) {
            hash_update($hash, $table . "\n" . json_encode($this->siteConnection()->fetchAllNumeric('select id, data from ' . $table . ' order by id')) . "\n");
        }
        hash_update($hash, json_encode($this->siteConnection()->fetchAllNumeric('select name, value, position from idno_settings order by position')) . "\n");
        $files = [];
        $root = $this->uploadPath;
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($root)) . ':' . $file->getSize();
                }
            }
        }
        sort($files);
        hash_update($hash, implode("\n", $files));

        return hash_final($hash);
    }

    /**
     * The site's own upload directory (IDNO_UPLOADPATH).
     */
    protected function uploadDirectory(): string
    {
        return $this->uploadPath;
    }

    /**
     * The directory of the site's cache pool (IDNO_CACHEPATH): emptied after every test of a shared site.
     */
    protected function cachePoolDirectory(): string
    {
        return $this->directory . '/cache';
    }

    private function environmentKey(): string
    {
        return hash('sha256', json_encode([$this->siteEnvironment(), $this->additionalEnvironment(), $this->siteIni(), $this->configuredScheme()], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $site an entry of self::$shared
     */
    private function adopt(array $site): void
    {
        if ($site['environment'] !== $this->environmentKey) {
            $this->fail('A shared site has one environment: this test asks for another than the first test of ' . static::class . ' did.');
        }
        $this->directory = $site['directory'];
        $this->uploadPath = $site['uploadPath'];
        $this->url = $site['url'];
        $this->dbname = $site['dbname'];
        $this->serverLog = $site['serverLog'];
        $this->connection = $site['connection'];
        $this->client = new HttpSiteClient($this->url);
    }

    private function start(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-site-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->uploadPath = $this->directory . '/uploads';
        mkdir($this->uploadPath, 0700);

        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        $dbname = '';
        if ($family === 'mysql') {
            $this->connection = PrivateDatabase::mysql((string) (getenv('IDNO_TEST_SITE_PURPOSE') ?: 'site'), migrate: false);
            $dbname = (string) $this->connection->getDatabase();
        } elseif ($family === 'sqlite') {
            $dbname = $this->directory . '/site.sqlite';
            $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbname], (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]));
        } else {
            $this->markTestSkipped('The site\'s database is not SQL: there is no schema to set up.');
        }
        $this->dbname = $dbname;

        $port = self::freePort();
        $this->url = 'http://127.0.0.1:' . $port . '/';
        $this->client = new HttpSiteClient($this->url);

        [$exit, , $stderr] = $this->console('idno:upgrade');
        $this->assertSame(0, $exit, 'idno:upgrade: ' . $stderr);

        $this->startServer($port);
    }

    protected function tearDown(): void
    {
        $changed = false;
        if ($this->usesSharedSite && $this->sharedReady) {
            $changed = $this->sharedSiteChanged();
            if ($changed) {
                self::release(static::class);
            } else {
                self::removeTree($this->cachePoolDirectory());
            }
        } else {
            $this->stopServer();
            if ($this->connection !== null) {
                if (($this->connection->getParams()['driver'] ?? '') === 'pdo_sqlite') {
                    $this->connection->close();
                } else {
                    PrivateDatabase::drop($this->connection);
                }
            }
            if ($this->directory !== '' && is_dir($this->directory)) {
                self::removeTree($this->directory);
            }
        }
        $this->connection = null;
        $this->directory = '';
        $this->uploadPath = '';
        $leaked = array_values(array_diff(self::filesUnder((string) Idno::site()->config()->uploadpath), $this->rigUploadsBefore));
        parent::tearDown();
        $this->assertSame([], $leaked, 'the site of the test wrote into the rig\'s shared upload directory');
        $this->assertFalse($changed, 'The test changed the shared site; a test that changes the site cannot share it.');
    }

    /**
     * A shared site is removed after the last test of its class: its servers stopped, its database dropped, its directory removed.
     */
    public static function tearDownAfterClass(): void
    {
        self::release(static::class);
        parent::tearDownAfterClass();
    }

    private function sharedSiteChanged(): bool
    {
        $site = self::$shared[static::class] ?? null;

        return $site === null || $this->siteFingerprint() !== $site['fingerprint'];
    }

    private static function release(string $class): void
    {
        $site = self::$shared[$class] ?? null;
        unset(self::$shared[$class]);
        if ($site === null) {
            return;
        }
        $servers = [$site['server'], ...array_map(static fn (array $extra) => $extra['server'], array_values($site['extra']))];
        self::stop(array_values(array_filter($servers)));
        $connection = $site['connection'];
        if ($connection instanceof Connection) {
            if (($connection->getParams()['driver'] ?? '') === 'pdo_sqlite') {
                $connection->close();
            } else {
                PrivateDatabase::drop($connection);
            }
        }
        if (is_dir($site['directory'])) {
            self::removeTree($site['directory']);
        }
    }

    /**
     * The black-box client of the test site: the characterisation harness's, pointed at it.
     */
    protected function site(): SiteClient
    {
        $this->assertNotNull($this->client);

        return $this->client;
    }

    /**
     * Starts another web server on the test site's database and upload directory (a free port, never 8089; stopped in
     * tearDown()) and returns its base URL with the trailing slash: for a test that needs the site served with other
     * settings than the test's own server has (the production environment, an opcache). Both servers are the site: a
     * write through one is read through the other.
     *
     * @param array<string, string> $environment variables over the site's own environment (APP_ENV, IDNO_PROXY_STRING, ...);
     *                                           IDNO_URL and IDNO_TEST_HTTP_ALLOW name the new server unless given
     * @param array<string, string> $ini         PHP settings (`-d name=value`) of the server's process
     */
    protected function startExtraServer(array $environment, array $ini = []): string
    {
        $port = self::freePort();
        $url = 'http://127.0.0.1:' . $port . '/';
        // a site in a subdirectory (IDNO_TEST_SUBDIRECTORY=blog: the rig's router serves the front controller as /blog/index.php) is configured at it
        $subdirectory = isset($environment['IDNO_TEST_SUBDIRECTORY']) ? $environment['IDNO_TEST_SUBDIRECTORY'] . '/' : '';
        if ($this->usesSharedSite) {
            // a shared site keeps the server for the class, by what it was asked for
            $key = hash('sha256', json_encode([$environment, $ini], JSON_THROW_ON_ERROR));
            if (isset(self::$shared[static::class]['extra'][$key])) {
                return self::$shared[static::class]['extra'][$key]['url'];
            }
        }
        $environment += ['IDNO_URL' => $this->configuredScheme() . substr($url, strlen('http')) . $subdirectory, 'IDNO_TEST_HTTP_ALLOW' => '127.0.0.1:' . $port];
        $server = $this->launch($port, $environment, $ini, $this->directory . '/extra-' . $port . '.log');
        if ($this->usesSharedSite) {
            self::$shared[static::class]['extra'][$key] = ['server' => $server, 'url' => $url];
        } else {
            $this->extraServers[] = $server;
        }

        return $url;
    }

    /**
     * The log of an extra server, by the address startExtraServer() returned.
     */
    protected function extraServerLog(string $url): string
    {
        return $this->directory . '/extra-' . parse_url($url, PHP_URL_PORT) . '.log';
    }

    /**
     * The test site's own origin and path, with the trailing slash.
     */
    protected function baseUrl(): string
    {
        return $this->url;
    }

    /**
     * Runs bin/console with the arguments on the test site's database.
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    protected function console(string ...$arguments): array
    {
        return $this->consoleWith([], ...$arguments);
    }

    /**
     * @param array<string, string|null> $environment variables to set over the site's, null to remove one
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected function consoleWith(array $environment, string ...$arguments): array
    {
        $root = dirname(__DIR__, 2);

        return $this->execute([PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/bin/console', ...$arguments], $environment);
    }

    /**
     * Runs PHP code with the legacy application booted on the test site (`Idno/start.php`, the
     * site's environment): for what only the entity classes can do (a follower record, a queued event
     * written as the queue writes it). The code's last statement is `return`ed as JSON. $asConsole
     * defines IDNO_CONSOLE first, as bin/console does: the process is then as the queue worker is, with no request.
     *
     * @return mixed the JSON the code returned, decoded
     */
    protected function runInSite(string $code, bool $asConsole = false): mixed
    {
        $root = dirname(__DIR__, 2);
        $script = $this->directory . '/script-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . "if (PHP_SAPI !== 'cli') { exit(1); }\n"
            . ($asConsole ? "define('IDNO_CONSOLE', true);\n" : '')
            . 'require ' . $this->literal($root . '/Idno/start.php') . ";\n"
            . "\$result = (function () {\n" . $code . "\n})();\n"
        . "fwrite(STDOUT, \"\\n#RESULT#\" . json_encode(\$result, JSON_UNESCAPED_SLASHES));\n");
        [$exit, $stdout, $stderr] = $this->execute([PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $script]);
        unlink($script);
        $this->assertSame(0, $exit, 'script failed: ' . $stdout . $stderr);
        $position = strrpos($stdout, "\n#RESULT#");
        $this->assertNotFalse($position, 'script printed no result: ' . $stdout . $stderr);

        return json_decode(substr($stdout, $position + 9), true, 512, JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ the site's users and content

    /**
     * Makes the site's first administrator, as the installation's last step does (the site gets its
     * name, its sender address and closed registration; the ActivityPub plugin gives every new user a
     * key pair when it is saved), logs in over HTTP and returns the session cookie (`name=value`) of
     * the logged-in user. On a site that has an administrator it adds a member instead.
     */
    protected function registerUser(string $handle, string $password = 'correct-horse-battery-9'): string
    {
        $created = $this->runInSite(
            '$first = \Idno\Core\Idno::container()->get(\Idno\Install\FirstAdministrator::class);'
            . ' if ($first->administratorExists()) { return false; }'
            . ' return $first->create(new \Idno\Install\AdministratorDetails(' . $this->literal(ucfirst($handle)) . ', ' . $this->literal($handle) . ', ' . $this->literal($handle . '@example.com')
            . ', ' . $this->literal($password) . ', ' . $this->literal($password) . '), "");'
        );
        if ($created === false) {
            $this->addUser($handle, $password);
        } else {
            $this->assertIsString($created, $this->serverLog());
        }
        $cookie = $this->loginCookie($handle, $password);
        $check = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
        $this->assertSame(200, $check->status, 'registered, and so logged in');

        return $cookie;
    }

    /**
     * Adds a user to the site (the registration form is closed once the site has its first user) with the
     * password, and returns the user's uuid. The email address is `<handle>@example.com` unless given.
     */
    protected function addUser(string $handle, string $password = 'correct-horse-battery-9', ?string $email = null, bool $admin = false): string
    {
        $uuid = $this->runInSite(
            '$u = new \Idno\Entities\User(); $u->handle = ' . $this->literal($handle) . '; $u->email = ' . $this->literal($email ?? $handle . '@example.com')
            . '; $u->setTitle(' . $this->literal(ucfirst($handle)) . '); $u->setPassword(' . $this->literal($password) . '); ' . ($admin ? '$u->setAdmin(true); ' : '')
            . '$u->save(true); return $u->getUUID();'
        );
        $this->assertIsString($uuid);

        return $uuid;
    }

    /**
     * The API key of the user of the site with the handle (made and stored when the user has none).
     */
    protected function apiKeyOf(string $handle): string
    {
        $key = $this->runInSite('$u = \\Idno\\Entities\\User::getByHandle(' . $this->literal($handle) . '); return $u->getAPIkey();');
        $this->assertIsString($key);

        return $key;
    }

    /**
     * Publishes a public status through the status form over HTTP, as the logged-in user, and returns
     * its permalink (the Location of the redirect).
     *
     * @param array<string, string> $headers sent with the post
     */
    protected function publishStatus(string $cookie, string $body, array $headers = [], string $access = 'PUBLIC'): string
    {
        // The form's page reads `body` without a default, which the rig's pedantic_mode makes an exception.
        $form = $this->site()->request('GET', 'status/edit?body=x', ['Cookie' => $cookie]);
        $this->assertSame(200, $form->status, $this->serverLog());
        $response = $this->site()->request('POST', 'status/edit/', ['Cookie' => $cookie] + $headers, $this->formToken($form->body, 'status/edit/') + [
            'body' => $body,
            'access' => $access,
        ]);
        $this->assertSame(302, $response->status, substr($response->body, 0, 1000) . $this->serverLog());
        $location = (string) $response->header('Location');
        $this->assertNotSame('', $location);

        return $location;
    }

    /**
     * A PHP single-quoted string literal (or `null`) for a value written into a script.
     */
    protected function literal(?string $value): string
    {
        return $value === null ? 'null' : "'" . addcslashes($value, "'\\") . "'";
    }

    /**
     * Everything the web server (and so the site's requests, the worker's service calls included)
     * has written to its log since it started.
     */
    protected function serverLogContents(): string
    {
        return is_file($this->serverLog) ? (string) file_get_contents($this->serverLog) : '';
    }

    /**
     * How long a server's log is now: the main server's, or the extra server's that startExtraServer() returned the address of. A test of a
     * shared site takes it before its request and reads only what the request wrote with logSince(): the log holds every test of the class.
     */
    protected function logOffset(?string $extraServerUrl = null): int
    {
        $log = $extraServerUrl === null ? $this->serverLog : $this->extraServerLog($extraServerUrl);

        return is_file($log) ? (int) filesize($log) : 0;
    }

    /**
     * What a server's log gained since logOffset() said how long it was.
     */
    protected function logSince(int $offset, ?string $extraServerUrl = null): string
    {
        $log = $extraServerUrl === null ? $this->serverLog : $this->extraServerLog($extraServerUrl);

        return is_file($log) ? (string) substr((string) file_get_contents($log), $offset) : '';
    }

    /**
     * The end of the server's log, for failure messages.
     */
    protected function serverLog(): string
    {
        $log = is_file($this->serverLog) ? (string) file_get_contents($this->serverLog) : '';

        return "\nserver log:\n" . substr($log, -4000);
    }

    /**
     * The environment of every subprocess: the rig's kernel settings, the private database, the
     * site's URL, the asynchronous queue and the test client's allowance for the site's own origin,
     * with siteEnvironment() and additionalEnvironment() over them. Nothing else is inherited but PATH.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $origin = (string) parse_url($this->url, PHP_URL_HOST) . ':' . (string) parse_url($this->url, PHP_URL_PORT);

        return $this->siteEnvironment() + $this->additionalEnvironment() + [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'IDNO_DBNAME' => $this->dbname,
            'IDNO_URL' => $this->configuredScheme() . substr($this->url, strlen('http')),
            // The site's files go to a directory of the test's own (removed in tearDown): an archive or an upload made
            // by a test, with its test password hashes, never stays in the rig's shared upload directory.
            'IDNO_UPLOADPATH' => $this->uploadPath,
            // The site's cache pool (the HSTS memory, the login throttle's counts) is a directory of its own, removed in
            // tearDown: the pool's namespace is the host name without the port, so sites on one loopback address would share
            // one pool, and a count made by one test would be read by another, or by the rig's site.
            'IDNO_CACHEPATH' => $this->directory . '/cache',
            'IDNO_TEST_HTTP_ALLOW' => $origin,
            'PATH' => (string) getenv('PATH'),
        ];
    }

    /**
     * The scheme of the site's configured URL (`url` in its boot configuration): `http` or `https`.
     * The server itself always listens in plain http; a test class overrides this (read when the site
     * starts) to configure an https URL for a site whose internal requests are plain.
     */
    protected function configuredScheme(): string
    {
        return 'http';
    }

    /**
     * Settings a base class adds to every site's environment (the queue's, for the sites with the asynchronous
     * queue), under siteEnvironment()'s; a test class overrides siteEnvironment() instead.
     *
     * @return array<string, string>
     */
    protected function additionalEnvironment(): array
    {
        return [];
    }

    /**
     * Settings of the test site's environment beyond the standard ones: a test class overrides this
     * (it is read when the site starts). IDNO_TEST_SERVER_HTTPS=1 makes the web server present every
     * request as made over TLS (HTTPS=on in $_SERVER), as a server behind a TLS terminator does; the
     * worker, a command-line process, never is.
     *
     * @return array<string, string>
     */
    protected function siteEnvironment(): array
    {
        return [];
    }

    /**
     * @param list<string>               $command
     * @param array<string, string|null> $environment variables to set over the site's, null to remove one
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function execute(array $command, array $environment = []): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_filter($environment + $this->environment(), static fn (?string $value): bool => $value !== null));
        $this->assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::COMMAND_SECONDS;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $this->fail('A command took over ' . self::COMMAND_SECONDS . ' seconds: ' . implode(' ', array_slice($command, -2)) . $this->serverLog());
            }
            usleep(20_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_get_status($process);
        $exit = proc_close($process);

        // proc_close() reports -1 when proc_get_status() already reaped the process.
        return [$status['exitcode'] >= 0 ? $status['exitcode'] : $exit, $stdout, $stderr];
    }

    private function startServer(int $port): void
    {
        $this->serverLog = $this->directory . '/server.log';
        $this->server = $this->launch($port, [], $this->siteIni(), $this->serverLog);
    }

    /**
     * A connection to the test site's own database (a private MySQL or MariaDB database, or the SQLite file): for a test that reads the
     * tables the site writes.
     */
    protected function siteConnection(): Connection
    {
        $this->assertNotNull($this->connection);

        return $this->connection;
    }

    /**
     * PHP settings (`-d name=value`) of the test site's own web server, read when it starts: a test class overrides this for a
     * site that needs one (startExtraServer() takes its own).
     *
     * @return array<string, string>
     */
    protected function siteIni(): array
    {
        return [];
    }

    /**
     * A directory of the test's own for the site's session files (removed with the site's directory in tearDown()): a test that
     * counts or reads session files makes `session.save_path` this one in siteIni(), because the system's shared directory is written
     * by every other process of the machine.
     */
    protected function sessionDirectory(): string
    {
        $directory = $this->directory . '/sessions';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    /**
     * Starts `php -S` on the port with the site's environment (and $environment over it) and waits until it listens.
     *
     * @param array<string, string> $environment
     * @param array<string, string> $ini
     *
     * @return resource
     */
    private function launch(int $port, array $environment, array $ini, string $log)
    {
        $root = dirname(__DIR__, 2);
        $router = $this->directory . '/router.php';
        // The built-in server does not put the environment into $_SERVER, where the configuration
        // and the test HTTP client read it from: this router passes on the IDNO_* settings, then
        // runs the rig's.
        $script = '<?php' . "\n"
            . "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }\n"
            . "foreach (array_keys(getenv()) as \$name) { if (str_starts_with(\$name, 'IDNO_')) { \$_SERVER[\$name] = getenv(\$name); } }\n"
            . "if (getenv('IDNO_TEST_SERVER_HTTPS') === '1') { \$_SERVER['HTTPS'] = 'on'; }\n"
            // Apache and nginx leave PATH_INFO unset for a front controller's request, and the legacy router then routes the raw REQUEST_URI;
            // the built-in server sets it, decoded and with dot segments removed: IDNO_TEST_SERVER_NO_PATH_INFO=1 makes this server the first
            . "if (getenv('IDNO_TEST_SERVER_NO_PATH_INFO') === '1') { unset(\$_SERVER['PATH_INFO'], \$_SERVER['ORIG_PATH_INFO']); }\n"
            . 'return require ' . $this->literal($root . '/Tests/rig/router.php') . ";\n";
        file_put_contents($router, $script);
        $command = [PHP_BINARY, '-d', 'error_reporting=22527'];
        foreach ($ini as $name => $value) {
            $command[] = '-d';
            $command[] = $name . '=' . $value;
        }
        array_push($command, '-S', '127.0.0.1:' . $port, $router);
        $server = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            $environment + $this->environment() + ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        $this->assertIsResource($server);
        $deadline = microtime(true) + self::START_SECONDS;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            usleep(50_000);
        }
        $pid = (int) proc_get_status($server)['pid'];
        exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
        proc_terminate($server, 9);
        proc_close($server);
        $this->fail('The web server did not start.' . (is_file($log) ? "\n" . (string) file_get_contents($log) : ''));
    }

    private function stopServer(): void
    {
        $servers = $this->extraServers;
        $this->extraServers = [];
        if ($this->server !== null) {
            $servers[] = $this->server;
            $this->server = null;
        }
        self::stop($servers);
    }

    /**
     * @param list<resource> $servers
     */
    private static function stop(array $servers): void
    {
        foreach ($servers as $server) {
            $pid = (int) proc_get_status($server)['pid'];
            // The workers PHP_CLI_SERVER_WORKERS forks are the server's children.
            exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
            proc_terminate($server);
        }
        foreach ($servers as $server) {
            $deadline = microtime(true) + 5;
            while (proc_get_status($server)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if (proc_get_status($server)['running']) {
                $pid = (int) proc_get_status($server)['pid'];
                exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
                proc_terminate($server, 9);
            }
            proc_close($server);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::assertNotSame(8089, $port);

        return $port;
    }

    /**
     * @return list<string> the files below a directory (none when it is not there), sorted
     */
    private static function filesUnder(string $directory): array
    {
        if ($directory === '' || !is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
