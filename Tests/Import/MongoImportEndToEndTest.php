<?php

declare(strict_types=1);

namespace Tests\Import;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Files\FilesystemFactory;
use Idno\Storage\Files\FileStore;
use Idno\Storage\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * `idno:import:mongo` end to end, against a real MongoDB server: the site captured from the old
 * MongoDB backend (fixtures/mongo-site.ndjson, by fixtures/capture.php) is loaded into a private
 * MongoDB database, and the command runs in a subprocess configured for MongoDB as that site was
 * (IDNO_DATABASE=mongo, its dbstring and dbname) against a private target (an SQLite file on an
 * SQLite rig, a private database of the rig's server on a MySQL or MariaDB rig) and a temporary
 * upload directory. What it imported is compared with what the old backend returned
 * (fixtures/expected.php), and the imported site is served (`php -S` on a free port, never 8089).
 *
 * It needs the mongodb extension and a MongoDB server at IDNO_TEST_MONGO_URL (Tests/rig/mongo-import.sh
 * provides both, in Docker), and skips without that variable, saying why; with the variable set and
 * no extension it fails. Everything it creates (the MongoDB database, the target, the directories,
 * the web server) is removed in tearDown().
 */
final class MongoImportEndToEndTest extends TestCase
{
    /** The site's URL when it was captured: the import's subprocess is configured with it. */
    private const CAPTURED_URL = 'http://capture.example/';

    private const FILE_HOST = '127.0.0.1';

    private string $mongoUrl = '';

    private string $mongoDatabase = '';

    private ?\MongoDB\Driver\Manager $manager = null;

    private string $directory = '';

    private ?Connection $target = null;

    private string $targetUrl = '';

    /** @var array<string, string> the served site's database settings */
    private array $targetEnvironment = [];

    /** @var resource|null */
    private $server = null;

    private string $url = '';

    private string $serverLog = '';

    /** @var array{records: array<string, array<string, array<string, mixed>>>, config: array<string, mixed>, files: array<string, array{metadata: array<string, mixed>, length: int, md5: string}>} */
    private array $expected = [];

    protected function setUp(): void
    {
        $url = getenv('IDNO_TEST_MONGO_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('IDNO_TEST_MONGO_URL names no MongoDB server: run composer test:mongo-import (Tests/rig/mongo-import.sh), which provides one with the mongodb extension.');
        }
        $this->assertTrue(extension_loaded('mongodb'), 'IDNO_TEST_MONGO_URL is set, but this PHP has no mongodb extension.');
        $this->mongoUrl = $url;
        $this->mongoDatabase = 'idno_import_e2e_' . bin2hex(random_bytes(4));
        $this->manager = new \MongoDB\Driver\Manager($url);
        $this->load(__DIR__ . '/fixtures/mongo-site.ndjson');
        $this->expected = require __DIR__ . '/fixtures/expected.php';

        $this->directory = sys_get_temp_dir() . '/idno-mongo-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/uploads', 0700, true);
        $this->url = 'http://127.0.0.1:' . self::freePort() . '/';
        $this->createTarget();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->target !== null) {
            if (($this->target->getParams()['driver'] ?? '') === 'pdo_sqlite') {
                $this->target->close();
            } else {
                PrivateDatabase::drop($this->target);
            }
            $this->target = null;
        }
        if ($this->manager !== null) {
            $this->manager->executeCommand($this->mongoDatabase, new \MongoDB\Driver\Command(['dropDatabase' => 1]));
            $this->manager = null;
        }
        if ($this->directory !== '') {
            self::remove($this->directory);
        }
    }

    // ------------------------------------------------------------------ the cases

    public function testEveryRecordEqualsWhatTheOldBackendReturned(): void
    {
        $this->importSite();
        $store = PrivateDatabase::recordStore($this->target);

        $count = 0;
        foreach ($this->expected['records'] as $collection => $records) {
            if ($collection === 'config') {
                continue;
            }
            foreach ($records as $id => $record) {
                $this->assertSame(self::asSaved(self::plain($record)), $store->findById($collection, (string) $id), $collection . ' ' . $id);
                ++$count;
            }
            $this->assertSame(count($records), (int) $this->target->fetchOne('select count(*) from idno_' . $collection));
        }
        $this->assertGreaterThanOrEqual(8, $count, 'the fixture\'s records were all compared');
        $this->assertSame(
            json_decode((string) json_encode(self::plain($this->expected['config'])), true),
            $store->findLatest('config'),
            'the settings are the configuration the old backend loaded',
        );
        $this->assertSame(0, (int) $this->target->fetchOne('select count(*) from idno_upgrade_rejects'));

        $status = $store->findByUuid('entities', $this->recordWith('body', 'Hello from MongoDB, the status of the import fixture')['uuid']);
        $this->assertSame('a field name with a dot and a dollar sign', $status['odd.field$name'] ?? null);
        $this->assertSame('status', $this->target->fetchOne('select type from idno_entities where uuid = ?', [$status['uuid']]));
    }

    public function testEveryFileHasItsBytesAndMetadata(): void
    {
        $this->importSite();
        $files = new FileStore(FilesystemFactory::fromConfig(['uploadpath' => $this->directory . '/uploads/']), self::FILE_HOST);

        $this->assertCount(2, $this->expected['files']);
        foreach ($this->expected['files'] as $id => $file) {
            $stored = $files->find((string) $id);
            $this->assertNotNull($stored, (string) $id);
            $this->assertSame($file['metadata'], $stored->metadata, (string) $id);
            $this->assertSame($file['length'], $stored->size);
            $this->assertSame($file['md5'], md5((string) stream_get_contents($files->readStream((string) $id))));
        }
    }

    public function testTheImportedSiteIsServed(): void
    {
        $this->importSite();
        [$code, $stdout, $stderr] = $this->console($this->siteEnvironment(), 'idno:upgrade');
        $this->assertSame(0, $code, $stdout . $stderr);
        $this->startServer();

        $home = $this->request('/');
        $this->assertSame(200, $home['status'], substr($home['body'], 0, 2000) . $this->log());
        $this->assertStringContainsString('Hello from MongoDB, the status of the import fixture', $home['body']);
        $this->assertStringNotContainsString('A draft nobody else may see', $home['body']);

        $article = $this->recordWith('title', 'An article from MongoDB');
        $permalink = $this->request('/' . date('Y', (int) $article['created']) . '/' . $article['slug']);
        $this->assertSame(200, $permalink['status'], substr($permalink['body'], 0, 2000) . $this->log());
        $this->assertStringContainsString('An article from MongoDB', $permalink['body']);

        $photo = $this->recordWith('title', 'A photo in GridFS');
        $fileId = (string) self::plain($photo['attachments'][0]['_id']);
        $served = $this->request('/file/' . $fileId . '/pixel.png');
        $this->assertSame(200, $served['status'], $this->log());
        $this->assertSame($this->expected['files'][$fileId]['md5'], md5($served['body']));
        $this->assertStringStartsWith('image/png', $served['headers']['content-type'] ?? '');

        $draft = $this->recordWith('body', 'A draft nobody else may see');
        foreach (['/' . date('Y', (int) $draft['created']) . '/' . $draft['slug'], '/view/' . self::plain($draft['_id'])] as $path) {
            $answer = $this->request($path);
            $this->assertNotSame(200, $answer['status'], $path);
            $this->assertStringNotContainsString('A draft nobody else may see', $answer['body'], $path);
        }
    }

    public function testASecondRunOnTheSameTargetIsRefused(): void
    {
        $this->importSite();
        $before = (int) $this->target->fetchOne('select count(*) from idno_entities');

        [$code, $stdout, $stderr] = $this->import();

        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertStringContainsString('new, empty database', $stderr);
        $this->assertSame($before, (int) $this->target->fetchOne('select count(*) from idno_entities'));
        $this->assertSame(0, (int) $this->target->fetchOne('select count(*) from idno_upgrade_rejects'));
    }

    public function testTheCommandNeverPrintsThePassword(): void
    {
        // A failed connection: the target's password in the URL.
        $password = 'Imp0rt-Secret/p@ss:' . bin2hex(random_bytes(4));
        $unreachable = 'mysql://importer:' . rawurlencode($password) . '@127.0.0.1:9/never';
        [$code, $stdout, $stderr] = $this->import(['IMPORT_TARGET_URL' => $unreachable], withTarget: false);
        $this->assertSame(1, $code, $stdout . $stderr);
        $this->assertStringContainsString('could not connect to the new database', $stderr);
        self::assertNoSecret($password, $stdout . $stderr);

        // A run that succeeds: the source's password (and the target's, when the rig's server has one).
        [$code, $stdout, $stderr] = $this->import();
        $this->assertSame(0, $code, $stdout . $stderr);
        $this->assertStringContainsString('Now run php bin/console idno:upgrade', $stdout);
        if (str_starts_with($this->targetUrl, 'mysql:')) {
            $this->assertStringContainsString('dbpass = "<the password you gave>"', $stdout);
        }
        foreach ([parse_url($this->mongoUrl, PHP_URL_PASS), parse_url($this->targetUrl, PHP_URL_PASS)] as $secret) {
            if (is_string($secret) && $secret !== '') {
                self::assertNoSecret(rawurldecode($secret), $stdout . $stderr);
            }
        }
        $this->assertNotFalse(parse_url($this->mongoUrl, PHP_URL_PASS), 'the MongoDB server of the test has a password, so a password is there to leak');
    }

    // ------------------------------------------------------------------ helpers

    private function importSite(): void
    {
        [$code, $stdout, $stderr] = $this->import();
        $this->assertSame(0, $code, $stdout . $stderr);
        $this->assertStringContainsString('Now run php bin/console idno:upgrade', $stdout);
    }

    /**
     * Runs the import command as the MongoDB site would.
     *
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function import(array $environment = [], bool $withTarget = true): array
    {
        $arguments = ['idno:import:mongo', '--upload-path=' . $this->directory . '/uploads', '--file-host=' . self::FILE_HOST, '--no-interaction'];
        if ($withTarget) {
            $arguments[] = '--target=' . $this->targetUrl;
        }

        return $this->console($environment + [
            'IDNO_DATABASE' => 'mongo',
            'IDNO_DBSTRING' => $this->mongoUrl,
            'IDNO_DBNAME' => $this->mongoDatabase,
            'IDNO_URL' => self::CAPTURED_URL,
        ], ...$arguments);
    }

    /**
     * The served site's environment: the target as its database, the upload directory, the test server's URL.
     *
     * @return array<string, string>
     */
    private function siteEnvironment(): array
    {
        return $this->targetEnvironment + [
            'IDNO_URL' => $this->url,
            'IDNO_UPLOADPATH' => $this->directory . '/uploads/',
            'IDNO_FILESYSTEM' => 'local',
        ];
    }

    private function createTarget(): void
    {
        $config = \Idno\Core\Idno::container()->get(BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) === 'mysql') {
            $this->target = PrivateDatabase::mysql('mongoe2e', migrate: false);
            $params = $this->target->getParams();
            $this->targetUrl = sprintf(
                'mysql://%s:%s@%s:%d/%s',
                rawurlencode((string) ($params['user'] ?? '')),
                rawurlencode((string) ($params['password'] ?? '')),
                $params['host'] ?? '127.0.0.1',
                (int) ($params['port'] ?? 3306),
                $params['dbname'],
            );
            $this->targetEnvironment = ['IDNO_DBNAME' => (string) $params['dbname']];

            return;
        }
        $path = $this->directory . '/target.sqlite';
        $this->targetUrl = 'sqlite:///' . $path;
        $this->targetEnvironment = ['IDNO_DATABASE' => 'sqlite', 'IDNO_DBNAME' => $path];
        $this->target = PrivateDatabase::sqliteFile($path, migrate: false);
        $this->assertSame([], (new SchemaCatalog($this->target))->tables());
    }

    /**
     * Loads the captured dump into the private MongoDB database, byte for byte (Extended JSON to BSON).
     */
    private function load(string $file): void
    {
        $handle = fopen($file, 'rb');
        $this->assertIsResource($handle);
        $writes = [];
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\n");
            if ($line === '') {
                continue;
            }
            $this->assertSame(1, preg_match('/^\{"collection":("(?:[^"\\\\]|\\\\.)*"),"document":(.*)\}$/s', $line, $m));
            $collection = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
            $writes[$collection] ??= new \MongoDB\Driver\BulkWrite(['ordered' => true]);
            $writes[$collection]->insert(\MongoDB\BSON\Document::fromJSON($m[2]));
        }
        fclose($handle);
        foreach ($writes as $collection => $bulk) {
            $this->manager?->executeBulkWrite($this->mongoDatabase . '.' . $collection, $bulk);
        }
    }

    /**
     * The captured record (of any collection) whose field holds the value.
     *
     * @return array<string, mixed>
     */
    private function recordWith(string $field, string $value): array
    {
        foreach ($this->expected['records'] as $records) {
            foreach ($records as $record) {
                if (($record[$field] ?? null) === $value) {
                    return $record;
                }
            }
        }
        $this->fail('No captured record has ' . $field . ' ' . $value);
    }

    /**
     * A value the old backend returned, as the import makes it: an ObjectId its hex digits, a date its
     * epoch seconds, a 64-bit integer an int.
     */
    private static function plain(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return match (true) {
            $value instanceof \MongoDB\BSON\ObjectId => (string) $value,
            $value instanceof \MongoDB\BSON\UTCDateTime => intdiv((int) (string) $value, 1000),
            $value instanceof \MongoDB\BSON\Int64 => (int) (string) $value,
            default => $value,
        };
    }

    /**
     * A record as the site's record stores save it: `siteid` null and `owner` '' when empty.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function asSaved(array $record): array
    {
        if (empty($record['siteid'])) {
            $record['siteid'] = null;
        }
        if (empty($record['owner'])) {
            $record['owner'] = '';
        }

        return json_decode((string) json_encode($record), true);
    }

    private static function assertNoSecret(string $secret, string $output): void
    {
        foreach ([$secret, rawurlencode($secret), urlencode($secret)] as $form) {
            self::assertStringNotContainsString($form, $output, 'the password was printed');
        }
    }

    /**
     * Runs bin/console with the arguments, in an environment of the test's own.
     *
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    private function console(array $environment, string ...$arguments): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/bin/console', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment + ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'IDNO_PROXY_STRING' => '127.0.0.1:9', 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function startServer(): void
    {
        $root = dirname(__DIR__, 2);
        $router = $this->directory . '/router.php';
        $names = ['APP_ENV', 'APP_DEBUG', 'IDNO_DATABASE', 'IDNO_DBNAME', 'IDNO_URL', 'IDNO_PROXY_STRING', 'IDNO_UPLOADPATH', 'IDNO_FILESYSTEM'];
        $literal = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // The built-in server does not put the environment into $_SERVER, where the configuration is
        // read from: this router passes on the test's settings, then runs the rig's.
        file_put_contents(
            $router,
            '<?php' . "\n"
            . "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }\n"
            . 'foreach (' . $literal($names) . ' as $name) { if (getenv($name) !== false) { $_SERVER[$name] = getenv($name); } }' . "\n"
            . 'return require ' . $literal($root . '/Tests/rig/router.php') . ";\n",
        );
        $this->serverLog = $this->directory . '/server.log';
        $port = (string) parse_url($this->url, PHP_URL_PORT);
        $server = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->serverLog, 'a'], 2 => ['file', $this->serverLog, 'a']],
            $pipes,
            $root,
            $this->siteEnvironment() + ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'IDNO_PROXY_STRING' => '127.0.0.1:9', 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($server);
        $this->server = $server;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->fail('The web server did not start.' . $this->log());
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }
        proc_terminate($this->server);
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->server)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($this->server)['running']) {
            proc_terminate($this->server, 9);
        }
        proc_close($this->server);
        $this->server = null;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $path): array
    {
        $handle = curl_init(rtrim($this->url, '/') . $path);
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $raw = (string) curl_exec($handle);
        $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => (string) substr($raw, $size)];
    }

    private function log(): string
    {
        $log = is_file($this->serverLog) ? (string) file_get_contents($this->serverLog) : '';

        return "\nserver log:\n" . substr($log, -4000);
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

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
