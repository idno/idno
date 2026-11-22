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
 */
abstract class PrivateSiteTestCase extends TestCase
{
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

    private ?SiteClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/idno-site-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->uploadPath = $this->directory . '/uploads';
        mkdir($this->uploadPath, 0700);
        $this->rigUploadsBefore = self::filesUnder((string) Idno::site()->config()->uploadpath);

        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        $dbname = '';
        if ($family === 'mysql') {
            $this->connection = PrivateDatabase::mysql('site', migrate: false);
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
        $this->stopServer();
        if ($this->connection !== null) {
            if (($this->connection->getParams()['driver'] ?? '') === 'pdo_sqlite') {
                $this->connection->close();
            } else {
                PrivateDatabase::drop($this->connection);
            }
            $this->connection = null;
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            self::removeTree($this->directory);
        }
        $this->directory = '';
        $this->uploadPath = '';
        $leaked = array_values(array_diff(self::filesUnder((string) Idno::site()->config()->uploadpath), $this->rigUploadsBefore));
        parent::tearDown();
        $this->assertSame([], $leaked, 'the site of the test wrote into the rig\'s shared upload directory');
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
     * Registers a user through the registration form over HTTP (the first user of a site becomes its
     * administrator; the ActivityPub plugin gives every new user a key pair when it is saved) and
     * returns the session cookie (`name=value`) of the logged-in user.
     */
    protected function registerUser(string $handle, string $password = 'correct-horse-battery-9'): string
    {
        $form = $this->site()->request('GET', 'begin/register');
        $this->assertSame(200, $form->status, $this->serverLog());
        $response = $this->site()->request('POST', 'begin/register', ['Cookie' => $this->sessionCookie($form)], $this->formToken($form->body, 'begin/register') + [
            'name' => ucfirst($handle),
            'handle' => $handle,
            'email' => $handle . '@example.com',
            'password' => $password,
            'password2' => $password,
        ]);
        $this->assertSame(302, $response->status, substr($response->body, 0, 1000) . $this->serverLog());
        $cookie = $this->sessionCookie($response);
        $check = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
        $this->assertSame(200, $check->status, 'registered, and so logged in');

        return $cookie;
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
     * The session cookie a response sets, as `name=value`.
     */
    protected function sessionCookie(\Tests\Characterisation\SiteResponse $response): string
    {
        $this->assertGreaterThan(0, preg_match_all('/(?:^|[,;]\s*)(known_unittest)=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m), 'a session cookie: ' . $response->header('Set-Cookie'));

        return $m[1][0] . '=' . end($m[2]);
    }

    /**
     * The CSRF token fields of the form in $html whose action ends with $action.
     *
     * @return array<string, string>
     */
    protected function formToken(string $html, string $action): array
    {
        foreach (preg_split('/<form\b/i', $html) ?: [] as $form) {
            if (preg_match('/^[^>]*action="[^"]*' . preg_quote($action, '/') . '"/', $form) !== 1) {
                continue;
            }
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                if (preg_match('/name="' . $field . '" value="([^"]*)"/', $form, $m) === 1) {
                    $fields[$field] = html_entity_decode($m[1], ENT_QUOTES);
                }
            }
            if (count($fields) === 3) {
                return $fields;
            }
        }
        $this->fail('No signed form posting to ' . $action . ' in the page.');
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
        $root = dirname(__DIR__, 2);
        $router = $this->directory . '/router.php';
        // The built-in server does not put the environment into $_SERVER, where the configuration
        // and the test HTTP client read it from: this router passes on the IDNO_* settings, then
        // runs the rig's.
        $script = '<?php' . "\n"
            . "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }\n"
            . "foreach (array_keys(getenv()) as \$name) { if (str_starts_with(\$name, 'IDNO_')) { \$_SERVER[\$name] = getenv(\$name); } }\n"
            . "if (getenv('IDNO_TEST_SERVER_HTTPS') === '1') { \$_SERVER['HTTPS'] = 'on'; }\n"
            . 'return require ' . $this->literal($root . '/Tests/rig/router.php') . ";\n";
        file_put_contents($router, $script);
        $this->serverLog = $this->directory . '/server.log';
        $server = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->serverLog, 'a'], 2 => ['file', $this->serverLog, 'a']],
            $pipes,
            $root,
            $this->environment() + ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        $this->assertIsResource($server);
        $this->server = $server;
        $deadline = microtime(true) + self::START_SECONDS;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->fail('The web server did not start.' . $this->serverLog());
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }
        $pid = (int) proc_get_status($this->server)['pid'];
        // The workers PHP_CLI_SERVER_WORKERS forks are the server's children.
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
        proc_terminate($this->server);
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->server)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($this->server)['running']) {
            exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
            proc_terminate($this->server, 9);
        }
        proc_close($this->server);
        $this->server = null;
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
