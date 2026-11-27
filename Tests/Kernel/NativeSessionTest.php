<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Tests\Support\WebServer;

/**
 * What Idno\Security\NativeSession does to PHP's session, each case on a `php -S` of its own (a header list exists only under a web SAPI) that runs
 * `Tests/Kernel/native_session.php` on the request's session. The owner needs no kernel, so the router loads only the autoloader.
 */
final class NativeSessionTest extends TestCase
{
    private string $directory = '';

    private ?WebServer $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-native-session-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        WebServer::removeTree($this->directory);
    }

    /**
     * @param array<string, string> $ini PHP settings of the server
     * @return array<string, mixed> what the case printed
     */
    private function answerOf(string $case, array $ini = [], string $cookie = ''): array
    {
        if ($this->server === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            $this->assertNotFalse($socket, 'a free port');
            $this->port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
            fclose($socket);
            $this->server = WebServer::launch(
                __DIR__ . '/native_session.php',
                $this->port,
                ['PATH' => (string) getenv('PATH'), 'IDNO_TEST_SESSION_HANDLER_DIR' => $this->directory . '/handler'],
                $ini,
                dirname(__DIR__, 2),
                $this->directory . '/server.log',
                $this->directory . '/sessions',
                1,
            );
        }
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => $cookie === '' ? '' : 'Cookie: ' . $cookie . "\r\n"]]);
        $body = (string) file_get_contents('http://127.0.0.1:' . $this->port . '/?case=' . $case, false, $context);
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, $case . ': ' . $body . ' ' . (is_file($this->directory . '/server.log') ? file_get_contents($this->directory . '/server.log') : ''));
        $this->assertSame([], $decoded['warnings'] ?? null, $case . ' raised a warning');

        return $decoded;
    }

    // Catches a changed session setting (lifetime, strict mode, cookies only, trans-sid): nothing else pins gc_maxlifetime, use_strict_mode, use_only_cookies or use_trans_sid.
    public function testTheIniValuesAreTheOnesTheSiteAlwaysSet(): void
    {
        $result = $this->answerOf('ini');

        $this->assertSame('604800', $result['cookie_lifetime']);
        $this->assertSame('604800', $result['gc_maxlifetime']);
        $this->assertSame('1', $result['use_only_cookies']);
        $this->assertSame('1', $result['cookie_httponly']);
        $this->assertSame('Lax', $result['cookie_samesite']);
        $this->assertSame('1', $result['use_strict_mode']);
        $this->assertSame('', $result['use_trans_sid'], 'off');
        $this->assertNotSame('1', $result['cookie_secure'], 'a site served in the clear has no Secure cookie');
    }

    // Catches a site served over https getting a session cookie without the Secure flag.
    public function testASecureSiteGetsASecureCookie(): void
    {
        $this->assertSame('1', $this->answerOf('ini-secure')['cookie_secure']);
    }

    // Catches taking a plugin's own cookies off the response along with the session's cookie (a stateless request must keep them).
    public function testRemoveCookieLineKeepsOtherCookies(): void
    {
        $result = $this->answerOf('remove-cookie');

        $this->assertCount(3, $result['before'], 'the session cookie and two others');
        $this->assertSame(['Set-Cookie: other=1', 'Set-Cookie: another=2'], $result['after']);
    }

    // Catches the logout's expiring cookie losing HttpOnly or SameSite=Lax (the replacing cookie is the only one other tests see).
    public function testExpireCookieCarriesTheSessionsAttributesAndSameSiteLax(): void
    {
        $cookies = $this->answerOf('expire-cookie')['cookies'];

        $expired = array_values(array_filter($cookies, static fn (string $line): bool => str_contains($line, 'idno_test=deleted')));
        $this->assertNotSame([], $expired, implode("\n", $cookies));
        $this->assertStringContainsString('SameSite=Lax', $expired[0]);
        $this->assertStringContainsString('HttpOnly', $expired[0]);
    }

    // Catches a cookie for a session that does not exist counting as carried: an API client's session would then be left behind.
    public function testACookieForASessionThatExistsIsCarriedAndAnyOtherIsNot(): void
    {
        $first = $this->answerOf('carried');
        $this->assertFalse($first['carried'], 'no cookie');

        $again = $this->answerOf('carried', [], 'idno_test=' . $first['id']);
        $this->assertTrue($again['carried'], 'the session exists');
        $this->assertSame($first['id'], $again['id']);

        $unknown = $this->answerOf('carried', [], 'idno_test=' . str_repeat('a', 32));
        $this->assertFalse($unknown['carried'], 'strict mode gives a cookie for no session a new one');
    }

    // Catches start() warning, or starting again, when a plugin's handler has already started the session (a warning is an exception under pedantic_mode).
    public function testAHandlerThatStartsTheSessionItselfKeepsTheSitesIniValues(): void
    {
        $result = $this->answerOf('handler-starts-itself');

        $this->assertTrue($result['same_id'], 'the session was not started again');
        $this->assertTrue($result['active']);
    }
}
