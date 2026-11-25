<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;
use Tests\Templating\PageNormaliser;

/**
 * `Idno\Pages\Homepage` as a deprecated shell, over HTTP, on a site of the test's own: the home page is served by a controller, and a
 * page a plugin wrote against the legacy class (a subclass registered with `hijackRoute()` for the root or `addRoute()` for a path of its
 * own) still serves it, through the same service, with the headers the home page has, and logs one deprecation line a process. The
 * fixtures are `Tests\Fixtures\ThirdPartyHomepage` and the modes `homepage-hijack` and `homepage-route` of IDNO_TEST_CLAIM_ROUTES.
 */
final class HomepageShellTest extends PrivateSiteTestCase
{
    private const DEPRECATION = 'Deprecated: Tests\Fixtures\ThirdPartyHomepage extends Idno\Pages\Homepage';

    protected function setUp(): void
    {
        parent::setUp();
        $cookie = $this->registerUser('ada');
        $this->publishStatus($cookie, 'A note in the stream of the shell test #books');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function raw(string $base, string $method, string $target, array $headers = []): array
    {
        $parts = parse_url($base);
        $socket = stream_socket_client('tcp://' . $parts['host'] . ':' . $parts['port'], $errno, $error, 10);
        $this->assertNotFalse($socket, 'connect: ' . $error);
        $headers += ['Host' => $parts['host'] . ':' . $parts['port']];
        $request = $method . ' ' . $target . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        fwrite($socket, $request . "Connection: close\r\n\r\n");
        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);

        [$head, $content] = explode("\r\n\r\n", $response, 2) + ['', ''];
        $lines = explode("\r\n", $head);
        preg_match('~^HTTP/\S+ (\d{3})~', (string) array_shift($lines), $status);
        $parsed = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + ['', ''];
            $parsed[strtolower($name)][] = trim($value);
        }

        return ['status' => (int) ($status[1] ?? 0), 'headers' => $parsed, 'body' => $content];
    }

    /**
     * The body with the server's address (written as it is, and with its slashes escaped in a JSON text) and the ids a page makes by itself taken out.
     */
    private function withoutServer(string $body, string $base): string
    {
        $body = PageNormaliser::normalise($body, $base, true);

        // (the posts' own addresses are the first site's, whichever server shows them)
        foreach ([$base, $this->baseUrl()] as $address) {
            $body = str_replace([rtrim(str_replace('/', '\\/', $address), '\\/'), rtrim($address, '/')], '{site}', $body);
        }

        return $body;
    }

    private function deprecationLines(string $base): int
    {
        return substr_count($this->extraServerLog($base) !== '' && is_file($this->extraServerLog($base)) ? (string) file_get_contents($this->extraServerLog($base)) : '', self::DEPRECATION);
    }

    public function testAHijackedSubclassServesTheHomePageThroughTheService(): void
    {
        $base = $this->startExtraServer(['IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack']);

        $home = $this->raw($base, 'GET', '/');

        $this->assertSame(200, $home['status'], $home['body']);
        // the home page: the stream, in the shell, drawn by the service
        $this->assertStringContainsString('<!DOCTYPE html>', $home['body']);
        $this->assertStringContainsString('A note in the stream of the shell test', $home['body']);
        // `LegacyRouteOverride` gave the root to the legacy router: it is the subclass that was served, and its class is the page's
        $this->assertStringContainsString('<body class="tests_fixtures_thirdpartyhomepage', $home['body']);
        // one deprecation line for the process that served it
        $this->assertSame(1, $this->deprecationLines($base), $this->extraServerLog($base));
        // the other spellings and the content filter paths are the controller's, which the plugin did not take
        $this->assertStringContainsString('<body class="idno_pages_homepage', $this->raw($base, 'GET', '/content/all')['body']);
    }

    public function testAPageEndsWhereTheLegacyPageEnded(): void
    {
        $base = $this->startExtraServer(['IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack']);

        $home = $this->raw($base, 'GET', '/');

        $this->assertSame(200, $home['status'], $home['body']);
        // the subclass draws after `parent::getContent()` and draws a second page: the legacy page exited when it drew, so neither happens
        $this->assertStringNotContainsString(\Tests\Fixtures\ThirdPartyHomepage::AFTER_PARENT, $home['body']);
        $this->assertSame(1, substr_count($home['body'], '<!DOCTYPE html>'));
        // (the length the page sent is the page's: nothing was added after it)
        $this->assertSame([(string) strlen($home['body'])], $home['headers']['content-length'] ?? []);
    }

    public function testASubclassOnItsOwnRouteServesTheHomePage(): void
    {
        $base = $this->startExtraServer(['IDNO_TEST_CLAIM_ROUTES' => 'homepage-route']);

        $home = $this->raw($base, 'GET', '/third-party-home/');

        $this->assertSame(200, $home['status'], $home['body']);
        $this->assertStringContainsString('A note in the stream of the shell test', $home['body']);
        $this->assertStringContainsString('<body class="tests_fixtures_thirdpartyhomepage', $home['body']);
        $this->assertSame(1, $this->deprecationLines($base), $this->extraServerLog($base));
        // the root is still the controller's
        $this->assertStringContainsString('<body class="idno_pages_homepage', $this->raw($base, 'GET', '/')['body']);
    }

    public function testTheShellDrawsThroughTheService(): void
    {
        $base = $this->startExtraServer(['IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack']);

        // The same page, drawn by the controller (this site's own server) and by the shell (the hijacked root): they differ in the address
        // of the server and in the page's class, which the body's class names
        foreach (['/', '/?_t=json', '/?q=shell+test&_t=rss', '/?_t=md'] as $path) {
            $controller = $this->site()->request('GET', $path);
            $shell = $this->raw($base, 'GET', $path);

            $this->assertSame(200, $shell['status'], $path);
            $this->assertSame($this->withoutServer($controller->body, $this->baseUrl()), str_replace('tests_fixtures_thirdpartyhomepage', 'idno_pages_homepage', $this->withoutServer($shell['body'], $base)), $path);
        }
    }

    public function testAShellServedRequestHasTodaysHeaders(): void
    {
        $base = $this->startExtraServer(['IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack']);

        $controller = $this->site()->request('GET', '/');
        $shell = $this->raw($base, 'GET', '/');

        $this->assertSame(200, $shell['status']);
        $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $shell['headers']['expires'] ?? []);
        $this->assertSame(['no-store, no-cache, must-revalidate'], $shell['headers']['cache-control'] ?? []);
        $this->assertSame(['no-cache'], $shell['headers']['pragma'] ?? []);
        $this->assertCount(1, $shell['headers']['last-modified'] ?? []);
        $this->assertSame('*', ($shell['headers']['access-control-allow-origin'] ?? [''])[0]);
        $this->assertStringStartsWith('text/html', ($shell['headers']['content-type'] ?? [''])[0]);
        // the names the controller sends, the shell sends (the dates and the cookie differ)
        $names = static function (array $names): array {
            $names = array_values(array_unique(array_diff($names, ['date', 'set-cookie', 'host', 'connection'])));
            sort($names);

            return $names;
        };
        $this->assertSame($names(array_map('strtolower', array_keys($controller->headers))), $names(array_keys($shell['headers'])));
        $this->assertSame($controller->header('Cache-Control'), $shell['headers']['cache-control'][0]);
        $this->assertSame((string) strlen($shell['body']), $shell['headers']['content-length'][0] ?? null);

        // a POST without a token is refused as the legacy page refused it: back to the home page with a message
        $post = $this->raw($base, 'POST', '/', ['Content-Length' => '0']);
        $this->assertSame(302, $post['status']);
        $this->assertSame(['no-store, no-cache, must-revalidate'], $post['headers']['cache-control'] ?? []);
        $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $post['headers']['expires'] ?? []);
        $this->assertSame(['no-cache'], $post['headers']['pragma'] ?? []);
    }

    public function testAMalformedHostReachesTheShell(): void
    {
        // Symfony refuses the request, and the legacy router serves it: the page is the shell's, as it has always been
        $home = $this->site()->request('GET', '/', ['Host' => 'bad host!']);

        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('<!DOCTYPE html>', $home->body);
        $this->assertStringContainsString('A note in the stream of the shell test', $home->body);
        $this->assertStringContainsString('<body class="idno_pages_homepage', $home->body);
    }
}
