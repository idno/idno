<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;
use Tests\Support\WebServer;

/**
 * A site that is not installed, served by `php -S` on a free port (never the rig's), for the tests of
 * the installation. What an installation writes (the configuration, the setup marker, the SQLite
 * database, uploads) goes to a temporary directory of the test's own, which the test environment's
 * IDNO_TEST_INSTALL_ROOT names (see Tests\Fixtures\InstallRoot): the checkout's own configuration
 * is neither read nor written. The server and the directory are removed in tearDown().
 */
abstract class UninstalledSiteTestCase extends TestCase
{
    protected string $root = '';
    protected string $base = '';

    private ?WebServer $server = null;
    private string $log = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl')) {
            self::markTestSkipped('pdo_sqlite and curl are needed.');
        }
        $checkout = dirname(__DIR__, 2);
        $this->root = (string) realpath(sys_get_temp_dir()) . '/idno-install-site-' . bin2hex(random_bytes(6));
        foreach (['configuration', 'data', 'Uploads', 'warmup/webserver-configs'] as $directory) {
            mkdir($this->root . '/' . $directory, 0755, true);
        }
        copy($checkout . '/warmup/webserver-configs/config.ini.template', $this->root . '/warmup/webserver-configs/config.ini.template');
        $this->log = $this->root . '/server.log';
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->root !== '' && is_dir($this->root)) {
            exec('chmod -R u+rwX ' . escapeshellarg($this->root) . ' && rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * The environment of the site's processes: the test kernel, the root, and nothing that names a database.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'IDNO_TEST_INSTALL_ROOT' => $this->root,
            // the temporary directory of the test's own process, so that what the server writes under it is where the test looks
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => (string) getenv('PATH'),
        ];
    }

    protected function startServer(): void
    {
        $checkout = dirname(__DIR__, 2);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::assertNotSame(8089, $port);
        $this->base = 'http://127.0.0.1:' . $port;

        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php' . "\n"
            . "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }\n"
            . "foreach (array_keys(getenv()) as \$name) { if (str_starts_with(\$name, 'IDNO_') || str_starts_with(\$name, 'APP_')) { \$_SERVER[\$name] = getenv(\$name); } }\n"
        . 'return require ' . json_encode($checkout . '/Tests/rig/router.php', JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n");
        try {
            // sessions are files in the site's own directory (removed with its root), never the system's shared one
            $this->server = WebServer::launch($router, $port, $this->environment(), [], $checkout, $this->log, $this->root . '/sessions', 2);
        } catch (\RuntimeException $e) {
            self::fail($e->getMessage() . $this->serverLog());
        }
    }

    protected function stopServer(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    protected function serverLog(): string
    {
        return is_file($this->log) ? "\n" . (string) file_get_contents($this->log) : '';
    }

    /**
     * One request, redirects not followed. A page is asked for (Accept: text/html) unless $headers says otherwise.
     *
     * @param array<string, string>|null $fields  A form to post (the method is then POST unless given)
     * @param array<string, string>      $cookies
     * @param list<string>               $headers
     * @return array{status: int, body: string, location: string, cookies: array<string, string>, headers: string}
     */
    protected function request(string $path, ?array $fields = null, array $cookies = [], array $headers = ['Accept: text/html'], ?string $method = null): array
    {
        $curl = curl_init($this->base . $path);
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($fields !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        if ($method !== null) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($cookies !== []) {
            curl_setopt($curl, CURLOPT_COOKIE, implode('; ', array_map(static fn (string $n, string $v): string => $n . '=' . $v, array_keys($cookies), $cookies)));
        }
        $response = curl_exec($curl);
        self::assertIsString($response, curl_error($curl) . $this->serverLog());
        $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $head = substr($response, 0, $size);
        $set = [];
        if (preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $head, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $set[$match[1]] = $match[2];
            }
        }

        return [
            'status' => $status,
            'body' => substr($response, $size),
            'location' => preg_match('/^Location:\s*(\S+)/mi', $head, $match) === 1 ? $match[1] : '',
            'cookies' => $set,
            'headers' => $head,
        ];
    }

    /**
     * Runs bin/console in the site's environment.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment variables over the site's own
     * @return array{int, string, string} exit code, standard output, standard error
     */
    protected function console(array $arguments, string $stdin = '', array $environment = []): array
    {
        $checkout = dirname(__DIR__, 2);
        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'session.save_path=' . $this->root . '/sessions', $checkout . '/bin/console'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $checkout,
            $environment + $this->environment(),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * The hidden form token of a page.
     */
    protected static function token(string $html): string
    {
        self::assertSame(1, preg_match('/name="token" value="([a-f0-9]{64})"/', $html, $match), 'the page has a form token: ' . substr($html, 0, 400));

        return $match[1];
    }

    /**
     * Posts the upgrade step until the database is current.
     */
    protected function createTheTables(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $step = $this->request('/_upgrade/step', [], [], ['Accept: application/json'], 'POST');
            $answer = json_decode($step['body'], true);
            self::assertIsArray($answer, $step['body'] . $this->serverLog());
            if ($answer['status'] === 'current') {
                return;
            }
            self::assertSame('working', $answer['status'], $step['body']);
        }
        self::fail('The upgrade did not finish.');
    }
}
