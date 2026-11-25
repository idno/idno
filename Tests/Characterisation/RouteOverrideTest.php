<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Legacy\ControllerPage;
use Tests\Fixtures\ClaimingPage;
use Tests\Fixtures\ReplacedPage;
use Tests\Fixtures\SubclassedPage;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A controller's route for a site page and the legacy router on one site, over HTTP: the controller serves a path unless the
 * legacy router has a page of its own for it (a plugin's `addRoute()` of an identical pattern or `hijackRoute()`, or a subclass of a
 * kept shell), and everything else about the path stays as the legacy router answered it. The fixtures (`TestEventHooks`, under
 * IDNO_TEST_CLAIM_ROUTES): `/_test/claimable` is served by a controller whose `#[SitePage]` replaces the shell `ReplacedPage`, and
 * `/_test/unclaimed` by one that replaces nothing; each answers `controller`, and the legacy pages `legacy` and `claimed`.
 *
 * Who answered is read from the body, and, where the answer is empty (HEAD, OPTIONS), from the class of the page the `page/ready`
 * event ran for (`ControllerPage` for a controller, the class of the legacy page otherwise).
 */
final class RouteOverrideTest extends PrivateSiteTestCase
{
    use LogsIn;

    private string $recordFile = '';

    /** @var array<string, string> */
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordFile = $this->directory() . '/idno-route-override-' . bin2hex(random_bytes(6)) . '.jsonl';
    }

    private function directory(): string
    {
        return sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        @unlink($this->recordFile);
        parent::tearDown();
    }

    /**
     * The site served with a mode of IDNO_TEST_CLAIM_ROUTES ('' registers nothing).
     */
    private function server(string $mode): string
    {
        $environment = ['IDNO_TEST_RECORD_PAGES' => $this->recordFile];
        if ($mode !== '') {
            $environment['IDNO_TEST_CLAIM_ROUTES'] = $mode;
        }

        return $this->servers[$mode] ??= $this->startExtraServer($environment);
    }

    /**
     * One raw HTTP/1.1 request, as written: the target is sent exactly as given (a client would tidy `//`), with any header.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string, pages: list<string>}
     */
    private function raw(string $base, string $method, string $target, array $headers = [], string $body = ''): array
    {
        @unlink($this->recordFile);
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

        return ['status' => (int) ($status[1] ?? 0), 'headers' => $parsed, 'body' => $content, 'pages' => $this->pages()];
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
     * @return list<string> the class of the current page at each `page/ready` event of the last request
     */
    private function pages(): array
    {
        $pages = [];
        foreach (is_file($this->recordFile) ? (file($this->recordFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            $event = json_decode($line, true);
            if (($event['event'] ?? '') === 'page/ready') {
                $pages[] = (string) $event['current'];
            }
        }

        return $pages;
    }

    /**
     * The token fields of an anonymous session, and its cookie: what a POST to a legacy page needs.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function token(string $base): array
    {
        $login = (new HttpSiteClient($base))->request('GET', 'session/login');
        $this->assertSame(200, $login->status);

        return [self::sessionCookie($login), self::formToken($login->body, 'session/login')];
    }

    public function testWithoutAClaimTheControllerServesIt(): void
    {
        $base = $this->server('replaced');

        foreach (['/_test/claimable', '/_test/claimable/', '//_test/claimable', '//_test/claimable/'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertSame(200, $response['status'], $target);
            $this->assertSame('controller', $response['body'], $target . ': the controller shadows the shell, with no redirect for any of the four spellings');
            $this->assertSame([ControllerPage::class], $response['pages'], $target);
            $this->assertArrayNotHasKey('location', $response['headers'], $target);
        }
        $head = $this->raw($base, 'HEAD', '/_test/claimable');
        $this->assertSame(200, $head['status']);
        $this->assertSame('', $head['body']);
        $this->assertSame([ControllerPage::class], $head['pages']);
        $options = $this->raw($base, 'OPTIONS', '//_test/claimable/');
        $this->assertSame(204, $options['status']);
        $this->assertSame([ControllerPage::class], $options['pages']);

        $unclaimed = $this->raw($base, 'GET', '/_test/unclaimed/');
        $this->assertSame('controller', $unclaimed['body'], 'a path with no shell at all');
    }

    public function testAHijackedPathIsServedByTheLegacyPage(): void
    {
        $base = $this->server('1');

        foreach (['/_test/claimable', '/_test/claimable/', '//_test/claimable', '//_test/claimable/'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertSame(200, $response['status'], $target);
            $this->assertSame('claimed', $response['body'], $target);
            $this->assertSame([ClaimingPage::class], $response['pages'], $target);
        }
        $head = $this->raw($base, 'HEAD', '/_test/claimable');
        $this->assertSame('', $head['body']);
        $this->assertSame([ClaimingPage::class], $head['pages'], 'HEAD: the legacy page');
        $options = $this->raw($base, 'OPTIONS', '/_test/claimable');
        $this->assertSame([ClaimingPage::class], $options['pages'], 'OPTIONS: the legacy page');
        $this->assertSame('controller', $this->raw($base, 'GET', '/_test/unclaimed')['body'], 'a path nobody claimed is still the controller\'s');
    }

    public function testAClaimedPathIsServedByTheLegacyPageForEveryMethod(): void
    {
        foreach (['1', 'identical'] as $mode) {
            $base = $this->server($mode);
            [$cookie, $token] = $this->token($base);
            $query = '?' . http_build_query($token);

            $this->assertSame('claimed', $this->raw($base, 'GET', '/_test/claimable')['body'], $mode . ' GET');
            $post = $this->raw($base, 'POST', '/_test/claimable', ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
            $this->assertSame('claimed-post', $post['body'], $mode . ' POST');
            $this->assertSame([ClaimingPage::class], $post['pages'], $mode . ' POST');
            $this->assertSame('claimed-put', $this->raw($base, 'PUT', '/_test/claimable' . $query, ['Cookie' => $cookie])['body'], $mode . ' PUT');
            $this->assertSame('claimed-delete', $this->raw($base, 'DELETE', '/_test/claimable' . $query, ['Cookie' => $cookie])['body'], $mode . ' DELETE');
        }
    }

    public function testASubclassOfTheShellRegisteredByAPluginIsServed(): void
    {
        $base = $this->server('subclass');
        [$cookie, $token] = $this->token($base);

        $this->assertSame('subclass', $this->raw($base, 'GET', '/_test/claimable')['body']);
        $this->assertSame([SubclassedPage::class], $this->raw($base, 'HEAD', '/_test/claimable')['pages']);
        $post = $this->raw($base, 'POST', '/_test/claimable', ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
        $this->assertSame('legacy-post', $post['body'], 'the subclass inherits the shell\'s methods');
    }

    public function testOtherMethodsReachTheReplacedPage(): void
    {
        $base = $this->server('replaced');
        [$cookie, $token] = $this->token($base);
        $query = '?' . http_build_query($token);

        $post = $this->raw($base, 'POST', '/_test/claimable/', ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
        $this->assertSame('legacy-post', $post['body'], 'POST: the shell, through Page::post()');
        $this->assertSame([ReplacedPage::class], $post['pages']);
        $refused = $this->raw($base, 'POST', '/_test/claimable', ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], 'a=b');
        $this->assertNotSame('legacy-post', $refused['body'], 'without the form token Page::post() refuses, as it always did');
        $this->assertSame('legacy-put', $this->raw($base, 'PUT', '//_test/claimable' . $query, ['Cookie' => $cookie])['body']);
        $this->assertSame('legacy-delete', $this->raw($base, 'DELETE', '/_test/claimable/' . $query, ['Cookie' => $cookie])['body']);
    }

    public function testAnUndeclaredMethodOfAnUnclaimedPathIsTheLegacyRoutersAsBefore(): void
    {
        $base = $this->server('');

        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $response = $this->raw($base, $method, '/_test/unclaimed');
            $this->assertSame(404, $response['status'], $method . ': no legacy page is registered for the path');
            $this->assertNotSame('controller', $response['body'], $method);
            $this->assertNotContains(ControllerPage::class, $response['pages'], $method . ': no controller page was begun');
        }
    }

    public function testAPluginRouteOnAPathWithNoShellTakesIt(): void
    {
        $base = $this->server('plugin');

        foreach (['/_test/unclaimed', '/_test/unclaimed/', '//_test/unclaimed'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertSame('claimed', $response['body'], $target);
            $this->assertSame([ClaimingPage::class], $response['pages'], $target);
        }
        $this->assertSame('controller', $this->raw($base, 'GET', '/_test/claimable')['body'], 'the path the plugin did not take');
    }

    public function testTemplateTypesAreTheirOwnPagesAnswerWhoeverServes(): void
    {
        foreach ([['replaced', 'controller'], ['1', 'claimed']] as [$mode, $body]) {
            $base = $this->server($mode);

            $json = $this->raw($base, 'GET', '/_test/claimable?_t=json');
            $this->assertSame(200, $json['status'], $mode . ' ?_t=json');
            $this->assertSame($body, $json['body'], $mode);

            $array = $this->raw($base, 'GET', '/_test/claimable?_t[]=x');
            $this->assertSame(200, $array['status'], $mode . ' ?_t[]=x asks for no type and is no server error');
            $this->assertSame($body, $array['body'], $mode);
        }
    }

    public function testTheMarkdownSuffixIsTheLegacyRoutersAsBefore(): void
    {
        $base = $this->server('replaced');

        // a path whose shell is a Markdown permalink: the suffix is routed to the shell, which a controller's route never matches
        $markdown = $this->raw($base, 'GET', '/_test/claimable.md');
        $this->assertSame(200, $markdown['status']);
        $this->assertSame('legacy-markdown', $markdown['body']);
        $this->assertSame([ReplacedPage::class], $markdown['pages']);
        // a path that is not one
        $none = $this->raw($base, 'GET', '/_test/unclaimed.md');
        $this->assertSame(404, $none['status']);
        $this->assertNotSame('controller', $none['body']);
    }

    public function testAMalformedHostReachesTheLegacyRouterAsBefore(): void
    {
        $base = $this->server('1');

        $claimed = $this->raw($base, 'GET', '/_test/claimable', ['Host' => 'bad host<name>']);
        $this->assertSame('claimed', $claimed['body'], 'Symfony rejects the request itself; the legacy router serves it, as it chooses');
        $this->assertSame([ClaimingPage::class], $claimed['pages']);
    }

    public function testAMalformedHostForAPathTheLegacyRouterHasNoPageFor(): void
    {
        $bad = ['Host' => 'bad host<name>'];

        // the legacy router serves such a request, and serves what it has: the shell of a path that has one, nothing for one that has not
        $replaced = $this->server('replaced');
        $this->assertSame('legacy', $this->raw($replaced, 'GET', '/_test/claimable', $bad)['body'], 'the shell, as before the controller');
        $this->assertSame(404, $this->raw($replaced, 'GET', '/_test/unclaimed', $bad)['status'], 'a path whose page is only a controller: the legacy router has none');
    }

    public function testAnExtraTrailingSlashIsServedByTheController(): void
    {
        // Symfony's router lets one more trailing slash match a route (the legacy patterns allow exactly one, so where the web server hands
        // the router the path as sent, the legacy router had no page for it): an address nobody links to, pinned as it is
        $base = $this->server('replaced');

        $this->assertSame('controller', $this->raw($base, 'GET', '/_test/claimable//')['body']);
    }

    public function testATripleLeadingSlashIsNoAddressOfThePage(): void
    {
        $base = $this->server('');

        // for the legacy router either: one leading slash is optional, and a second is the pattern's own
        $this->assertSame(404, $this->raw($base, 'GET', '///_test/unclaimed')['status']);
    }
}
