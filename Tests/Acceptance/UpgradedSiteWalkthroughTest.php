<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Characterisation\HttpSiteClient;
use Tests\Fixtures\Smtp\ScriptedSmtpServer;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\Mail\ReceivedMessage;
use Tests\Support\WebServer;
use Tests\Upgrade\LegacyFixture;

/**
 * What the owner of a site that ran Idno 1.x sees after the files are replaced: the upgrade over HTTP, then the
 * site working with the settings the 1.x site had (MySQL and MariaDB only: no 1.x site runs on SQLite, and the
 * fixture skips the test there, saying so).
 *
 * The 1.x database is the fixture site (an administrator, a public post, the Status, Text and Event plugins)
 * whose stored configuration also has what a 1.x site could have had: `smtp_host` with no port and an empty
 * `smtp_secure` (so mail goes to port 25, no security: the connection is plain, as under 1.x), a `from_email`, and `cache = "FilesystemCache"`
 * (a setting that no longer exists).
 *
 *  1. a visitor is told the database is being upgraded; the steps the page posts finish it; the 1.x post is served
 *     and the 1.x administrator logs in with the password of the 1.x database;
 *  2. a password reset asked for on the upgraded site is delivered, through the production transport and the
 *     settings the 1.x site stored, to a scripted SMTP server (the test's own switch replaces only host and port:
 *     the port 25 cannot be bound), and the transport a fresh process of the site builds MEANS port 25 with
 *     no TLS (the same seam as Tests/Mail/SwiftMailerParityTest: Tests\\Fixtures\\TestMailTransportFactory::built());
 *  3. the ignored `cache` setting is warned about ONCE by a process that uses the cache, however often it uses it;
 *  4. the site answers in French to `Accept-Language: fr` and in English without it.
 *
 * The transport the page's own process built is not visible from the test (the web server's process keeps it);
 * the mail arriving proves the page used the stored settings, and a process of the site that reads the same
 * stored settings tells which port they mean.
 */
final class UpgradedSiteWalkthroughTest extends \PHPUnit\Framework\TestCase
{
    private const PASSWORD = 'legacy-password-from-1x';

    private string $directory = '';

    private string $url = '';

    private string $serverLog = '';

    private ?LegacyFixture $fixture = null;

    private ?ScriptedSmtpServer $smtp = null;

    private ?WebServer $server = null;

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->smtp?->stop();
        $this->smtp = null;
        $this->fixture?->drop();
        $this->fixture = null;
        if ($this->directory !== '' && is_dir($this->directory)) {
            WebServer::removeTree($this->directory . '/sessions');
            foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testASiteUpgradedFromOneXKeepsItsMailSettingsIgnoresItsCacheSettingAndTranslates(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-acceptance-1x-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->url = 'http://127.0.0.1:' . self::freePort() . '/';

        // A 1.x database (skips the test on an SQLite rig) with the settings a 1.x site stored.
        $this->fixture = LegacyFixture::create('accept');
        $site = $this->fixture->insertSite($this->url, 'legacyadmin', ['Status', 'Text', 'Event'], self::PASSWORD);
        $connection = $this->fixture->connection;
        $row = $connection->fetchAssociative('select `_id`, `contents` from `config` limit 1');
        $this->assertIsArray($row);
        $document = json_decode((string) $row['contents'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('smtp_port', $document);
        $document = ['smtp_host' => '127.0.0.1', 'smtp_secure' => '', 'from_email' => 'owner@legacy.example', 'cache' => 'FilesystemCache'] + $document;
        $connection->executeStatement('update `config` set `contents` = ? where `_id` = ?', [json_encode($document, JSON_THROW_ON_ERROR), $row['_id']]);

        $this->smtp = ScriptedSmtpServer::start();
        $this->startServer();
        $client = new HttpSiteClient($this->url);
        $browser = new Browser($client);

        // ------------------------------------------------------------ 1 the upgrade
        $visitor = $client->request('GET', '/', ['Accept' => 'text/html']);
        $this->assertSame(503, $visitor->status, 'while the database is 1.x the visitor is told it is being upgraded');
        $this->assertStringContainsString('Idno is upgrading its database', $visitor->body);
        $statuses = [];
        for ($i = 0; $i < 100 && end($statuses) !== 'current'; ++$i) {
            $answer = $client->request('POST', '_upgrade/step', ['Accept' => 'application/json']);
            $data = json_decode($answer->body, true);
            $this->assertIsArray($data, $answer->body . $this->log());
            $this->assertContains($data['status'], ['working', 'current'], $answer->body . $this->log());
            $statuses[] = $data['status'];
        }
        $this->assertSame('current', end($statuses), 'the steps the page posts finish the upgrade');

        $home = $client->request('GET', '/', ['Accept' => 'text/html']);
        $this->assertSame(200, $home->status, substr($home->body, 0, 1000) . $this->log());
        $post = $client->request('GET', 'view/' . $site['post'], ['Accept' => 'text/html']);
        $this->assertSame(200, $post->status);
        $this->assertStringContainsString($site['body'], $post->body, 'the 1.x post is served');
        $admin = $browser->tryLogin('legacyadmin', self::PASSWORD);
        $this->assertNotNull($admin, 'the 1.x administrator logs in with the password of the 1.x database');
        $this->assertNull($browser->tryLogin('legacyadmin', 'not-the-password'));

        // The stored settings are the ones the 1.x site had.
        $stored = $this->script('$c = \Idno\Core\Idno::site()->config(); return ["smtp_host" => $c->smtp_host, "smtp_port" => $c->smtp_port, "smtp_secure" => $c->smtp_secure, "from_email" => $c->from_email, "cache" => $c->cache];');
        $this->assertSame(['smtp_host' => '127.0.0.1', 'smtp_port' => null, 'smtp_secure' => '', 'from_email' => 'owner@legacy.example', 'cache' => 'FilesystemCache'], $stored['result']);

        // ------------------------------------------------------------ 2 mail
        $asked = $browser->submit('account/password/', 'account/password', ['email' => 'legacyadmin@example.com'], null);
        $this->assertSame(302, $asked->status, substr($asked->body, 0, 500) . $this->log());
        $this->assertStringContainsString('sent=true', (string) $asked->header('Location'));
        $messages = $this->smtp->messages();
        $this->assertCount(1, $messages, 'the reset mail is delivered with the stored 1.x settings' . $this->log());
        $mail = ReceivedMessage::parse($messages[0]);
        $this->assertStringStartsWith('Reset your password', (string) $mail->header('Subject'));
        $this->assertSame('legacyadmin@example.com', $mail->header('To'));
        $this->assertStringContainsString('owner@legacy.example', (string) $mail->header('From'));
        $this->assertSame(1, preg_match('~account/password/reset/\?email=([^&\s]+)&code=([0-9a-f]+)~', (string) $mail->part('text/plain'), $link));
        $this->assertSame(200, $client->request('GET', 'account/password/reset/?email=' . $link[1] . '&code=' . $link[2])->status, 'the link works');
        $this->assertSame(['legacyadmin@example.com'], $this->smtp->connections()[0]['rcptTo']);
        $this->assertFalse($this->smtp->connections()[0]['beganWithTls'], 'no port 465 and no ssl: the connection is plain');
        $this->assertFalse($this->smtp->connections()[0]['startTlsSent']);

        $built = $this->script(
            '\Tests\Fixtures\TestMailTransportFactory::forget();' . "\n"
            . '$mail = new \Idno\Core\Email(); $mail->addTo("probe@example.net"); $mail->setSubject("transport probe"); $mail->setTextBody("probe");' . "\n"
            . 'return ["sent" => $mail->send(), "built" => \Tests\Fixtures\TestMailTransportFactory::built()];'
        )['result'];
        $this->assertSame(1, $built['sent']);
        $this->assertCount(1, $built['built']);
        $this->assertSame(25, $built['built'][0]['port'], 'a stored host with no port means port 25');
        $this->assertSame('127.0.0.1', $built['built'][0]['host']);
        $this->assertFalse($built['built'][0]['implicitTls'], 'an empty smtp_secure is not implicit TLS');
        $this->assertFalse($built['built'][0]['autoTls'], 'and sends in the clear, as 1.x did (STARTTLS is not tried)');
        $this->assertFalse($built['built'][0]['requireTls']);
        $this->assertCount(2, $this->smtp->messages(), 'and the probe is delivered too');

        // ------------------------------------------------------------ 3 the ignored cache setting
        $warning = 'The "cache" setting (FilesystemCache) is ignored';
        $this->assertSame(0, substr_count($this->serverLogContents(), $warning), 'no request so far used the cache: no warning');
        $published = $browser->submit('status/edit?body=x', 'status/edit/', ['body' => 'Written after the upgrade', 'access' => 'PUBLIC'], $admin);
        $this->assertSame(302, $published->status, substr($published->body, 0, 500) . $this->log());
        // A post is announced to the hub through Webservice, which asks the cache about HSTS: the site's log carries one warning.
        $this->assertSame(1, substr_count($this->serverLogContents(), $warning), 'the site\'s log carries exactly one warning about the ignored setting' . $this->log());
        $uses = $this->script('$cache = \Idno\Core\Idno::site()->cache(); $cache->load("a"); $cache->store("b", 1, 5); $cache->load("b"); $cache->delete("b"); return true;', true);
        $this->assertSame(1, substr_count($uses['stderr'], $warning), 'one warning for a process that used the cache four times: ' . $uses['stderr']);
        $none = $this->script('return true;', true);
        $this->assertSame(0, substr_count($none['stderr'], 'setting (FilesystemCache)'), 'and none for one that did not use it');

        // ------------------------------------------------------------ 4 translation
        // Idno 2026 shows the name of each content type (the Event plugin's catalogue has `Event`) in the compose dialog of a signed-in member
        $french = $client->request('GET', 'content/all/', ['Accept-Language' => 'fr', 'Cookie' => $admin]);
        $this->assertSame(200, $french->status);
        $this->assertStringContainsString('Évènement', $french->body);
        $english = $client->request('GET', 'content/all/', ['Cookie' => $admin]);
        $this->assertSame(200, $english->status);
        $this->assertStringNotContainsString('Évènement', $english->body);
        $this->assertStringContainsString('Event</div>', $english->body);

        $this->assertSame(1, substr_count($this->serverLogContents(), $warning), 'and no other request of the walkthrough added one');
        $this->assertStringNotContainsString('FilesystemCache', preg_replace('/The "cache" setting[^\n]*/', '', $this->serverLogContents()) ?? '', 'nothing else in the log names the retired cache');
    }

    // ------------------------------------------------------------------ the site

    /**
     * The environment of the web server and of every script: the rig's kernel settings, the private database, the
     * site's URL, the test switch that delivers mail to the scripted server, and the allowance for the site's own
     * origin. Nothing else is inherited but PATH.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $this->assertNotNull($this->fixture);

        return [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'IDNO_DBNAME' => (string) $this->fixture->connection->getDatabase(),
            'IDNO_URL' => $this->url,
            'IDNO_PROXY_STRING' => '127.0.0.1:9',
            'IDNO_TEST_HTTP_ALLOW' => (string) parse_url($this->url, PHP_URL_HOST) . ':' . (string) parse_url($this->url, PHP_URL_PORT),
            TestMailTransportFactory::DELIVER => '127.0.0.1:' . $this->smtp->port(),
            'TMPDIR' => sys_get_temp_dir(),
            'PATH' => (string) getenv('PATH'),
        ];
    }

    /**
     * Runs PHP code with the legacy application booted on the site (as `Idno/start.php` does for any script), the last
     * statement `return`ing JSON. $asConsole defines IDNO_CONSOLE first, as `bin/console` and so the queue worker do.
     *
     * @return array{result: mixed, stdout: string, stderr: string}
     */
    private function script(string $code, bool $asConsole = false): array
    {
        $root = dirname(__DIR__, 2);
        $script = $this->directory . '/script-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . "if (PHP_SAPI !== 'cli') { exit(1); }\n"
            . ($asConsole ? "define('IDNO_CONSOLE', true);\n" : '')
            . 'require ' . self::literal($root . '/Idno/start.php') . ";\n"
            . "\$result = (function () {\n" . $code . "\n})();\n"
        . "fwrite(STDOUT, \"\\n#RESULT#\" . json_encode(\$result, JSON_UNESCAPED_SLASHES));\n");
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', ...WebServer::sessionArguments($this->directory . '/sessions'), '-d', 'display_errors=stderr', '-d', 'log_errors=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $this->environment());
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unlink($script);
        $this->assertSame(0, $exit, 'script failed: ' . $stdout . $stderr);
        $position = strrpos($stdout, "\n#RESULT#");
        $this->assertNotFalse($position, 'script printed no result: ' . $stdout . $stderr);

        return ['result' => json_decode(substr($stdout, $position + 9), true, 512, JSON_THROW_ON_ERROR), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function startServer(): void
    {
        $root = dirname(__DIR__, 2);
        $router = $this->directory . '/router.php';
        // The built-in server does not put the environment into $_SERVER, where the configuration and the test
        // hooks read it from: this router passes on the IDNO_* settings, then runs the rig's.
        file_put_contents($router, '<?php' . "\n"
            . "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }\n"
            . "foreach (array_keys(getenv()) as \$name) { if (str_starts_with(\$name, 'IDNO_')) { \$_SERVER[\$name] = getenv(\$name); } }\n"
        . 'return require ' . self::literal($root . '/Tests/rig/router.php') . ";\n");
        $this->serverLog = $this->directory . '/server.log';
        $port = (int) parse_url($this->url, PHP_URL_PORT);
        try {
            // sessions are files in the test's own directory, never the system's shared one
            $this->server = WebServer::launch($router, $port, $this->environment(), [], $root, $this->serverLog, $this->directory . '/sessions', 2, 15.0);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage() . $this->log());
        }
    }

    private function stopServer(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function serverLogContents(): string
    {
        return is_file($this->serverLog) ? (string) file_get_contents($this->serverLog) : '';
    }

    private function log(): string
    {
        return "\nserver log:\n" . substr($this->serverLogContents(), -4000);
    }

    /**
     * A PHP single-quoted string literal for a path written into a script.
     */
    private static function literal(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
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
