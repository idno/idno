<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Legacy\ControllerPage;
use Tests\Fixtures\RoutedPlaceholder;
use Tests\Fixtures\SubclassedRoutedShell;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A page routed by the legacy route table over HTTP (`IDNO_TEST_ROUTED=1`: `Tests\Fixtures\RoutedFixtureController`, no `#[Route]`): the placeholder
 * `RoutedPlaceholder` at `/_test/routed/word/:id/?` and the kept shell `RoutedFixtureShell` at `/_test/routed-shell/:id/.*`. The legacy router's own choice
 * decides who serves a request, so a plugin's registration still wins, and everything the action does not take stays the router's.
 *
 * Who answered is read from the page events the site records (`IDNO_TEST_RECORD_PAGES`): `ControllerPage` is the current page of a controller's page.
 */
final class RoutedPagesTest extends PrivateSiteTestCase
{
    use LogsIn;

    protected const SHARED_SITE = true;

    private static string $recordFile = '';

    /** @var array<string, string> */
    private array $servers = [];

    protected function setUp(): void
    {
        self::$recordFile = self::$recordFile !== '' ? self::$recordFile : sys_get_temp_dir() . '/idno-routed-pages-' . bin2hex(random_bytes(6)) . '.jsonl';
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$recordFile);
        self::$recordFile = '';
        parent::tearDownAfterClass();
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_ROUTED' => '1', 'IDNO_TEST_RECORD_PAGES' => self::$recordFile];
    }

    /**
     * The site as the test class has it, or another server on the same site with other settings.
     */
    private function server(string $mode = '', bool $noPathInfo = false, string $subdirectory = ''): string
    {
        if ($mode === '' && !$noPathInfo && $subdirectory === '') {
            return $this->baseUrl();
        }
        $environment = ['IDNO_TEST_ROUTED' => '1', 'IDNO_TEST_RECORD_PAGES' => self::$recordFile];
        if ($mode !== '') {
            $environment['IDNO_TEST_CLAIM_ROUTES'] = $mode;
        }
        if ($noPathInfo) {
            $environment['IDNO_TEST_SERVER_NO_PATH_INFO'] = '1';
        }
        if ($subdirectory !== '') {
            $environment['IDNO_TEST_SUBDIRECTORY'] = $subdirectory;
        }

        return $this->servers[$mode . '|' . (int) $noPathInfo . '|' . $subdirectory] ??= $this->startExtraServer($environment);
    }

    /**
     * One raw HTTP/1.1 request, as written: the target is sent exactly as given (a client would tidy `//`), with any header.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string, events: list<array<string, mixed>>}
     */
    private function raw(string $base, string $method, string $target, array $headers = [], string $body = ''): array
    {
        @unlink(self::$recordFile);
        $parts = parse_url($base);
        $socket = stream_socket_client('tcp://' . $parts['host'] . ':' . $parts['port'], $errno, $error, 10);
        $this->assertNotFalse($socket, 'connect: ' . $error);
        $headers += ['Host' => $parts['host'] . ':' . $parts['port']];
        $request = $method . ' ' . $target . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        if ($body !== '') {
            $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        fwrite($socket, $request . "Connection: close\r\n\r\n" . $body);
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
        if (isset($parsed['transfer-encoding']) && in_array('chunked', $parsed['transfer-encoding'], true)) {
            $content = self::unchunk($content);
        }

        return ['status' => (int) ($status[1] ?? 0), 'headers' => $parsed, 'body' => $content, 'events' => $this->events()];
    }

    private static function unchunk(string $content): string
    {
        $out = '';
        while ($content !== '') {
            [$size, $rest] = explode("\r\n", $content, 2) + ['0', ''];
            $length = (int) hexdec($size);
            if ($length === 0) {
                break;
            }
            $out .= substr($rest, 0, $length);
            $content = substr($rest, $length + 2);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>> the page events of the last request
     */
    private function events(): array
    {
        $events = [];
        foreach (is_file(self::$recordFile) ? (file(self::$recordFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $events[] = json_decode($line, true);
        }

        return $events;
    }

    /**
     * @param array{events: list<array<string, mixed>>} $response
     *
     * @return list<string> the class of the current page at each `page/ready` event
     */
    private static function pages(array $response): array
    {
        return array_values(array_map(static fn (array $event): string => (string) $event['current'], array_filter($response['events'], static fn (array $event): bool => $event['event'] === 'page/ready')));
    }

    private static function arguments(string $body): ?array
    {
        return preg_match('~arguments=(\[[^\]]*\]) markdown=(yes|no)~', html_entity_decode($body), $m) === 1 ? [json_decode($m[1], true), $m[2]] : null;
    }

    public function testARoutedPlaceholderIsServedByItsController(): void
    {
        $response = $this->raw($this->server(), 'GET', '/_test/routed/word/abc');

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertSame([['abc'], 'no'], self::arguments($response['body']), 'the page lists the argument the legacy page would have been called with');
        $this->assertSame([ControllerPage::class], self::pages($response), 'a controller served it');
        $get = array_values(array_filter($response['events'], static fn (array $event): bool => $event['event'] === 'page/get'));
        $this->assertCount(1, $get, 'page/get fired once');
        $this->assertSame(RoutedPlaceholder::class, $get[0]['page_class']);
        $this->assertSame(['abc'], $get[0]['arguments']);
        $this->assertStringContainsString('routedplaceholder', strtolower((string) $get[0]['body_class'] . strtolower(RoutedPlaceholder::class)), 'the body class names the page');
        $head = $this->raw($this->server(), 'HEAD', '/_test/routed/word/abc');
        $this->assertSame(200, $head['status']);
        $this->assertSame([ControllerPage::class], self::pages($head));
        $options = $this->raw($this->server(), 'OPTIONS', '/_test/routed/word/abc');
        $this->assertSame(204, $options['status']);
    }

    public function testAShellsPatternIsServedByItsController(): void
    {
        $response = $this->raw($this->server(), 'GET', '/_test/routed-shell/abc/anything');

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertSame([['abc'], 'no'], self::arguments($response['body']));
        $this->assertSame([ControllerPage::class], self::pages($response));
        $this->assertStringContainsString('Accept', implode(',', $response['headers']['vary'] ?? []), 'a Markdown permalink answers by Accept');
    }

    public function testTheMarkdownSuffixReachesTheController(): void
    {
        $response = $this->raw($this->server(), 'GET', '/_test/routed-shell/abc/anything.md');

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertStringContainsString('text/markdown', implode(',', $response['headers']['content-type'] ?? []));
        $this->assertStringContainsString('markdown=yes', html_entity_decode($response['body']), 'the action is told the Markdown form was asked');
        $this->assertStringContainsString('arguments=["abc"]', str_replace('\\', '', html_entity_decode($response['body'])), 'and its argument is the slug without the suffix');
        $this->assertSame([ControllerPage::class], self::pages($response));
        // a page that is not a Markdown permalink has no Markdown form: the address is the router's, which has no route for it
        $plain = $this->raw($this->server(), 'GET', '/_test/routed/word/abc.md');
        $this->assertSame(404, $plain['status']);
        $this->assertNotContains(ControllerPage::class, self::pages($plain));
    }

    public function testAPluginsHijackStillWins(): void
    {
        $base = $this->server('routed-hijack');

        $claimed = $this->raw($base, 'GET', '/_test/routed/word/abc');
        $this->assertSame(200, $claimed['status']);
        $this->assertSame('claimed', $claimed['body'], 'the legacy router chose the plugin\'s page');
        $this->assertNotContains(ControllerPage::class, self::pages($claimed));

        // a subclass of a routed shell is served through the shell, as a plugin's page, and is no page of the action
        $subclass = $this->raw($base, 'GET', '/_test/routed-shell/abc/anything');
        $this->assertSame(200, $subclass['status'], $subclass['body']);
        $this->assertSame([SubclassedRoutedShell::class], self::pages($subclass));
    }

    public function testAMethodThePageDoesNotTakeReachesTheLegacyRouter(): void
    {
        $base = $this->server();

        foreach (['/_test/routed/word/abc', '/_test/routed-shell/abc/anything'] as $path) {
            $post = $this->raw($base, 'POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=b');
            $this->assertContains($post['status'], [302, 404], $path . ': the router\'s answer (the shell\'s invalid-token redirect, the placeholder\'s 404): ' . $post['body']);
            $this->assertNotContains(ControllerPage::class, self::pages($post), $path);
            $put = $this->raw($base, 'PUT', $path);
            $this->assertNotSame(200, $put['status'], $path);
            // a POST that names GET in an override header is served by the router as a POST: it is no page of the action
            $override = $this->raw($base, 'POST', $path, ['X-HTTP-Method-Override' => 'GET', 'Content-Type' => 'application/x-www-form-urlencoded'], 'a=b');
            $this->assertNotContains(ControllerPage::class, self::pages($override), $path);
        }
    }

    public function testADoubledSlashAndATrailingSlashAreTheRoutersSpellings(): void
    {
        $base = $this->server();

        foreach (['/_test/routed/word/abc/', '//_test/routed/word/abc', '//_test/routed/word/abc/'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertSame(200, $response['status'], $target);
            $this->assertSame([['abc'], 'no'], self::arguments($response['body']), $target);
            $this->assertSame([ControllerPage::class], self::pages($response), $target);
        }
        // one more trailing slash is what a legacy page of the same kind answers (the server's own path handling, and a pattern that ends in `/?`)
        $this->assertSame($this->raw($base, 'GET', '/session/login//')['status'], $this->raw($base, 'GET', '/_test/routed/word/abc//')['status']);
        // an encoded spelling: the server gives the router the path decoded here, so the argument is what the router captured
        $encoded = $this->raw($base, 'GET', '/_test/routed/word/a%62c');
        $this->assertSame([['abc'], 'no'], self::arguments($encoded['body']));
        $this->assertSame([ControllerPage::class], self::pages($encoded));
    }

    public function testASiteInASubdirectoryAndAServerThatGivesOnlyTheRawPath(): void
    {
        $blog = $this->server('', false, 'blog');
        $response = $this->raw($blog, 'GET', '/blog/_test/routed/word/abc');
        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertSame([['abc'], 'no'], self::arguments($response['body']));
        $this->assertSame([ControllerPage::class], self::pages($response));
        $markdown = $this->raw($blog, 'GET', '/blog/_test/routed-shell/abc/anything.md');
        $this->assertSame(200, $markdown['status'], $markdown['body']);
        $this->assertStringContainsString('text/markdown', implode(',', $markdown['headers']['content-type'] ?? []));

        $raw = $this->server('', true);
        foreach (['GET /_test/routed/word/abc', 'GET //_test/routed/word/abc/'] as $line) {
            [$method, $target] = explode(' ', $line);
            $response = $this->raw($raw, $method, $target);
            $this->assertSame(200, $response['status'], $line . ': ' . $response['body']);
            $this->assertSame([['abc'], 'no'], self::arguments($response['body']), $line);
            $this->assertSame([ControllerPage::class], self::pages($response), $line);
        }
        // a server that gives the router only the raw path: the encoded spelling is one its pattern cannot match, as for any legacy page
        $encoded = $this->raw($raw, 'GET', '/_test/routed/word/a%62c');
        $this->assertSame(404, $encoded['status']);
        $this->assertNotContains(ControllerPage::class, self::pages($encoded));
    }

    public function testAMalformedHostIsTheLegacyRoutersAndItsPlaceholdersAre404s(): void
    {
        $base = $this->server();
        $placeholder = $this->raw($base, 'GET', '/_test/routed/word/abc', ['Host' => 'bad host']);
        $this->assertSame(404, $placeholder['status'], $placeholder['body']);
        $this->assertNotContains(ControllerPage::class, self::pages($placeholder));
        $shell = $this->raw($base, 'GET', '/_test/routed-shell/abc/anything', ['Host' => 'bad host']);
        $this->assertSame(200, $shell['status'], 'a kept shell still serves: ' . $shell['body']);
    }

    public function testALegacyPageIsServedWithTheOneEntryTheRouterChose(): void
    {
        $legacy = $this->raw($this->server(), 'GET', '/_test/legacy-plain');
        $this->assertSame(200, $legacy['status']);
        $this->assertNotContains(ControllerPage::class, self::pages($legacy));
        $none = $this->raw($this->server(), 'GET', '/no/such/page/here');
        $this->assertSame(404, $none['status']);
    }
}
