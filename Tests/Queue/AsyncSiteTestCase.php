<?php

declare(strict_types=1);

namespace Tests\Queue;

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
 * A site of the test's own with the asynchronous event queue, for tests of what queued events do.
 * The rig's site runs the synchronous queue, so nothing queued is ever left for a worker there.
 *
 * Each test starts PHP's built-in web server (`php -S`, four workers: the site calls itself, for
 * the sink and for the worker's service calls) on a free port, never the rig's 8089, on a private
 * database (a private database of the rig's MySQL or MariaDB server, or a file in a temporary
 * directory on an SQLite rig) whose schema `idno:upgrade` creates. The site's environment is
 * IDNO_EVENT_QUEUE=AsynchronousQueue, its own IDNO_URL and IDNO_TEST_HTTP_ALLOW naming its own
 * origin; the worker (`bin/console service-event-queue --once`) and the helper scripts that boot
 * the site get the same environment. The server is stopped (a bounded wait, then SIGKILL) and the
 * database dropped in tearDown(), whatever the outcome.
 */
abstract class AsyncSiteTestCase extends TestCase
{
    /** Seconds to wait for the web server to listen. */
    private const START_SECONDS = 20;

    /** Seconds a console command may run before it is killed. */
    private const COMMAND_SECONDS = 120;

    private ?Connection $connection = null;

    private string $directory = '';

    private string $url = '';

    private string $dbname = '';

    private string $serverLog = '';

    /** @var resource|null */
    private $server = null;

    private ?SiteClient $client = null;

    /** @var list<string> sinks read by sink() or named by newSink(), emptied again in tearDown() */
    private array $sinks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/idno-queue-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);

        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        $dbname = '';
        if ($family === 'mysql') {
            $this->connection = PrivateDatabase::mysql('queue', migrate: false);
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
        try {
            foreach ($this->sinks as $name) {
                try {
                    $this->site()->request('GET', '_test/sink/' . $name . '/log');
                } catch (\Throwable) {
                    // The server may already be gone.
                }
            }
        } finally {
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
                foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($this->directory);
            }
            $this->directory = '';
            parent::tearDown();
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
     * The test site's own origin and path, with the trailing slash.
     */
    protected function baseUrl(): string
    {
        return $this->url;
    }

    /**
     * A fresh sink name, emptied again in tearDown().
     */
    protected function newSink(string $prefix): string
    {
        $name = $prefix . '-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;

        return $name;
    }

    /**
     * The requests /_test/sink/<name> recorded since the last call, in order; the sink is emptied.
     *
     * @return list<array<string, mixed>>
     */
    protected function sink(string $name): array
    {
        $response = $this->site()->request('GET', '_test/sink/' . $name . '/log');
        $this->assertSame(200, $response->status, $response->body);
        if (!in_array($name, $this->sinks, true)) {
            $this->sinks[] = $name;
        }

        return $response->json();
    }

    /**
     * Runs `bin/console service-event-queue --once` in a subprocess with the site's environment.
     *
     * @param array<string, string|null> $environment variables to set over the site's, null to remove one
     *
     * @return array{exit: int, output: string} the exit code, and stdout then stderr
     */
    protected function runWorkerOnce(array $environment = []): array
    {
        [$exit, $stdout, $stderr] = $this->consoleWith($environment, 'service-event-queue', '--once');

        return ['exit' => $exit, 'output' => $stdout . $stderr];
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
     * written as the queue writes it). The code's last statement is `return`ed as JSON.
     *
     * @return mixed the JSON the code returned, decoded
     */
    protected function runInSite(string $code): mixed
    {
        $root = dirname(__DIR__, 2);
        $script = $this->directory . '/script-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . "if (PHP_SAPI !== 'cli') { exit(1); }\n"
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
     * Stores an ActivityPub follower record of a local user (what a signed Follow to the inbox
     * would, which the test does not send: the inbox fetches the remote actor), with the inbox URL
     * the deliveries go to; returns the user's UUID.
     */
    protected function addFollower(string $handle, string $inbox, string $actorUri = 'https://remote.example/users/bob'): string
    {
        return $this->runInSite(
            '$user = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');' . "\n"
            . '$follower = new \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower();' . "\n"
            . '$follower->user_uuid = $user->getUUID();' . "\n"
            . '$follower->actor_uri = ' . $this->literal($actorUri) . ';' . "\n"
            . '$follower->actor_inbox = ' . $this->literal($inbox) . ';' . "\n"
            . 'if (!$follower->save(true)) { throw new \RuntimeException("follower not saved"); }' . "\n"
            . 'return $user->getUUID();'
        );
    }

    /**
     * The queue's events as the database holds them, oldest first by queuedTs then id.
     *
     * @return list<array{id: string, event: string, queue: string, runAsContext: mixed, complete: bool, error: mixed, queuedTs: mixed, completedTs: mixed, data: mixed}>
     */
    protected function queuedEvents(): array
    {
        $events = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true);' . "\n"
            . '$rows = [];' . "\n"
            . 'foreach (\Idno\Entities\AsynchronousQueuedEvent::get([], [], 1000) ?: [] as $e) {' . "\n"
            . '    $rows[] = ["id" => $e->getID(), "event" => $e->event, "queue" => $e->queue, "runAsContext" => $e->runAsContext, "complete" => (bool) $e->complete, "error" => $e->error, "queuedTs" => $e->queuedTs, "completedTs" => $e->completedTs, "data" => unserialize($e->eventData)];' . "\n"
            . '}' . "\n"
            . 'usort($rows, static fn ($a, $b) => [$a["queuedTs"], $a["id"]] <=> [$b["queuedTs"], $b["id"]]);' . "\n"
            . 'return $rows;'
        );
        $this->assertIsArray($events);

        return $events;
    }

    /**
     * Writes a queued event exactly as AsynchronousQueue::enqueue() does, but with the event data
     * given as the text stored (so that data enqueue() would refuse can be stored), and returns the
     * stored event's id.
     */
    protected function storeQueuedEvent(string $event, string $serializedData, ?string $runAsContext): string
    {
        return $this->runInSite(
            '$q = new \Idno\Entities\AsynchronousQueuedEvent();' . "\n"
            . '$q->queue = "default";' . "\n"
            . '$q->event = ' . $this->literal($event) . ';' . "\n"
            . '$q->eventData = ' . $this->literal($serializedData) . ';' . "\n"
            . '$q->runAsContext = ' . $this->literal($runAsContext) . ';' . "\n"
            . '$q->complete = false;' . "\n"
            . '$q->queuedTs = time();' . "\n"
            . 'if (!$q->save(true)) { throw new \RuntimeException("event not stored"); }' . "\n"
            . 'return $q->getID();'
        );
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
     * with $extraEnvironment over them. Nothing else is inherited but PATH.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $origin = (string) parse_url($this->url, PHP_URL_HOST) . ':' . (string) parse_url($this->url, PHP_URL_PORT);

        return $this->siteEnvironment() + [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'IDNO_DBNAME' => $this->dbname,
            'IDNO_URL' => $this->configuredScheme() . substr($this->url, strlen('http')),
            'IDNO_EVENT_QUEUE' => 'AsynchronousQueue',
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
     * Registers a user, gives them a follower whose inbox is a fresh sink, publishes a status (with
     * $publishHeaders on the post, as a reverse proxy would add them), runs the worker once and returns
     * the URLs the delivered request carried: the post's permalink (the Location of the publish),
     * the activity's `actor`, `object.id` (its UUID replaced by `<uuid>`), `object.url` and the signature's `keyId`.
     *
     * @param array<string, string> $publishHeaders
     *
     * @return array{permalink: string, actor: string, objectId: string, objectUrl: string, keyId: string}
     */
    protected function deliveredUrls(array $publishHeaders = []): array
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);
        $permalink = $this->publishStatus($cookie, 'Hello queue', $publishHeaders);
        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);
        $requests = $this->sink($sink);
        $this->assertCount(1, $requests, 'one delivery' . $this->serverLog());
        $this->assertSame(1, preg_match('/^keyId="([^"]+)",/', $requests[0]['headers']['signature'], $m));
        $activity = json_decode((string) base64_decode($requests[0]['body'], true), true, 512, JSON_THROW_ON_ERROR);

        $objectId = $activity['object']['id'];
        $this->assertSame(1, preg_match('#/view/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $objectId), $objectId);

        return [
            'permalink' => $permalink,
            'actor' => $activity['actor'],
            'objectId' => substr($objectId, 0, -36) . '<uuid>',
            'objectUrl' => $activity['object']['url'],
            'keyId' => $m[1],
        ];
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
}
