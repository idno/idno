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
    private function server(string $mode, bool $noPathInfo = false, string $subdirectory = ''): string
    {
        $environment = ['IDNO_TEST_RECORD_PAGES' => $this->recordFile];
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

    /**
     * The spellings of `/_test/claimable` the router matches and each server hands the legacy router: the second says whether the raw
     * spelling is one the legacy router's patterns match (`/_test/claimable/?` under `^/?`) where the server sets no PATH_INFO.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function spellings(string $prefix = ''): array
    {
        return [
            [$prefix . '/_test/claimable', true],
            [$prefix . '/_test/claimable/', true],
            // (under a subdirectory the pattern carries it, `^/?` allows the one slash before it and not another after it)
            [$prefix . '//_test/claimable', $prefix === ''],
            [$prefix . '//_test/claimable/', $prefix === ''],
            [$prefix . '/_test/claim%61ble', false],
            [$prefix . '/_test/%63laimable/', false],
            [$prefix . '/%2F_test/claimable', false],
            [$prefix . '/%2f_test/claimable', false],
            [$prefix . '/_test/claimable%2F', false],
            [$prefix . '/_test/claimable%2f', false],
        ];
    }

    /**
     * What the legacy router gives a claimed path under each spelling, with and without the PATH_INFO the built-in server sets
     * (decoded, dot segments removed): the claim whenever it is given a spelling its patterns match, the 404 it always was when it
     * is given an encoded one. Never the controller's page: an address a plugin took is not the controller's in any spelling.
     */
    public function testAnEncodedSpellingNeverBypassesAPluginsClaim(): void
    {
        foreach (['1', 'identical'] as $mode) {
            foreach ([false, true] as $noPathInfo) {
                $base = $this->server($mode, $noPathInfo);
                foreach (self::spellings() as [$target, $rawMatches]) {
                    foreach (['GET', 'HEAD'] as $method) {
                        $label = $mode . ($noPathInfo ? ' (REQUEST_URI only)' : ' (PATH_INFO)') . ' ' . $method . ' ' . $target;
                        $response = $this->raw($base, $method, $target);
                        $this->assertNotSame('controller', $response['body'], $label);
                        $this->assertNotContains(ControllerPage::class, $response['pages'], $label);
                        if (!$noPathInfo || $rawMatches) {
                            $this->assertSame(200, $response['status'], $label);
                            $this->assertSame($method === 'GET' ? 'claimed' : '', $response['body'], $label);
                            $this->assertSame([ClaimingPage::class], $response['pages'], $label);
                        } else {
                            $this->assertSame(404, $response['status'], $label . ': the legacy router has no page for the raw spelling, as before');
                        }
                    }
                }
            }
        }
    }

    public function testEveryMethodOfAnEncodedSpellingOfAClaimedPathIsTheLegacyRouters(): void
    {
        $base = $this->server('1');
        [$cookie, $token] = $this->token($base);

        $post = $this->raw($base, 'POST', '/_test/claim%61ble', ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
        $this->assertSame('claimed-post', $post['body']);
        $this->assertSame('claimed-put', $this->raw($base, 'PUT', '/%2F_test/claimable?' . http_build_query($token), ['Cookie' => $cookie])['body']);
        $this->assertSame([ClaimingPage::class], $this->raw($base, 'OPTIONS', '/_test/claim%61ble')['pages']);
    }

    /**
     * An encoded spelling of a path nobody claimed: the controller serves it as it serves the plain one (the legacy router, given only
     * the raw path, answered a 404; given a decoded PATH_INFO, it served the shell), under either server.
     */
    public function testAnEncodedSpellingOfAnUnclaimedPathIsTheControllers(): void
    {
        foreach (['replaced', ''] as $mode) {
            foreach ([false, true] as $noPathInfo) {
                $base = $this->server($mode, $noPathInfo);
                foreach (self::spellings() as [$target]) {
                    $label = ($mode ?: 'no registration') . ($noPathInfo ? ' (REQUEST_URI only)' : ' (PATH_INFO)') . ' ' . $target;
                    $response = $this->raw($base, 'GET', $target);
                    $this->assertSame(200, $response['status'], $label);
                    $this->assertSame('controller', $response['body'], $label);
                    $this->assertSame([ControllerPage::class], $response['pages'], $label);
                    $this->assertSame([ControllerPage::class], $this->raw($base, 'HEAD', $target)['pages'], $label . ' HEAD');
                }
            }
        }
    }

    public function testAnEncodedSpellingOfAPathWithOnlyAPluginsPageIsThePlugins(): void
    {
        foreach ([false, true] as $noPathInfo) {
            $base = $this->server('plugin', $noPathInfo);
            foreach (['/_test/unclaimed', '/_test/unclaim%65d', '/%2F_test/unclaimed', '/_test/unclaimed%2F'] as $target) {
                $response = $this->raw($base, 'GET', $target);
                $this->assertNotSame('controller', $response['body'], $target);
                if ($noPathInfo && $target !== '/_test/unclaimed') {
                    $this->assertSame(404, $response['status'], $target . ': the legacy router given only the raw path, as before');
                } else {
                    $this->assertSame('claimed', $response['body'], $target);
                }
            }
        }
    }

    /**
     * A dot segment is no spelling Symfony's router matches (it takes the path literally), so the request is the legacy router's whatever
     * the server makes of it: the built-in server removes the segment from PATH_INFO and the router serves the page, a server that
     * leaves only the raw path answers the legacy 404.
     */
    public function testADotSegmentIsTheLegacyRoutersWhateverTheServerMakesOfIt(): void
    {
        $withPathInfo = $this->raw($this->server('1'), 'GET', '/_test/x/../claimable');
        $this->assertSame('claimed', $withPathInfo['body']);
        $this->assertSame('legacy', $this->raw($this->server('replaced'), 'GET', '/_test/x/../claimable')['body'], 'the kept shell, as before');
        $rawOnly = $this->raw($this->server('1', true), 'GET', '/_test/x/../claimable');
        $this->assertSame(404, $rawOnly['status']);
        $this->assertNotSame('controller', $rawOnly['body']);
    }

    /**
     * The same in a site in a subdirectory (`/blog`): a registered pattern carries it, the router's base URL is it, and a plugin's
     * `addRoute()` is chosen under every spelling. (`hijackRoute()` adds no prefix, so in a subdirectory it never matches the path:
     * the router's old behaviour, which the controller's own claim on the path then does not hide.)
     */
    public function testTheSameHoldsInASubdirectory(): void
    {
        foreach ([false, true] as $noPathInfo) {
            $label = $noPathInfo ? ' (REQUEST_URI only)' : ' (PATH_INFO)';
            $claimed = $this->server('identical', $noPathInfo, 'blog');
            foreach (self::spellings('/blog') as [$target, $rawMatches]) {
                $response = $this->raw($claimed, 'GET', $target);
                $this->assertNotSame('controller', $response['body'], 'identical' . $label . ' ' . $target);
                if (!$noPathInfo || $rawMatches) {
                    $this->assertSame('claimed', $response['body'], 'identical' . $label . ' ' . $target);
                } else {
                    $this->assertSame(404, $response['status'], 'identical' . $label . ' ' . $target);
                }
            }
            $this->assertSame([ClaimingPage::class], $this->raw($claimed, 'HEAD', '/blog/_test/claimable')['pages']);

            $replaced = $this->server('replaced', $noPathInfo, 'blog');
            foreach (self::spellings('/blog') as [$target]) {
                $response = $this->raw($replaced, 'GET', $target);
                $this->assertSame('controller', $response['body'], 'replaced' . $label . ' ' . $target);
                $this->assertSame([ControllerPage::class], $response['pages'], 'replaced' . $label . ' ' . $target);
            }
        }
    }

    public function testAPermalinkWithAFreeParameterAnswersItsMarkdownFormWhenItIsAMarkdownPermalink(): void
    {
        $base = $this->server('');

        $html = $this->raw($base, 'GET', '/_test/markdown/abc');
        $this->assertSame(200, $html['status']);
        $this->assertStringContainsString('The name is', $html['body']);
        $this->assertStringContainsString('text/html', $html['headers']['content-type'][0] ?? '');
        $this->assertStringNotContainsString('---', substr($html['body'], 0, 4));

        foreach (['GET', 'HEAD'] as $method) {
            $markdown = $this->raw($base, $method, '/_test/markdown/abc.md');
            $this->assertSame(200, $markdown['status'], $method);
            $this->assertStringContainsString('text/markdown', $markdown['headers']['content-type'][0] ?? '', $method);
            if ($method === 'GET') {
                $this->assertStringStartsWith('---', $markdown['body']);
                $this->assertStringContainsString('abc', $markdown['body']);
                $this->assertStringContainsString('The name is **abc**.', $markdown['body'], 'the suffix is no part of the name');
            }
            $this->assertSame([ControllerPage::class], $markdown['pages'], $method);
        }
        // an explicit template type other than Markdown wins over the suffix, as it does for a legacy permalink
        $json = $this->raw($base, 'GET', '/_test/markdown/abc.md?_t=json');
        $this->assertStringNotContainsString('text/markdown', $json['headers']['content-type'][0] ?? '');
        // a name that is only the suffix is a name
        $this->assertStringContainsString('.md', $this->raw($base, 'GET', '/_test/markdown/.md')['body']);
    }

    public function testAMarkdownPermalinkAPluginServesKeepsItsMarkdownFormWhateverTheControllerIs(): void
    {
        $base = $this->server('mdclaim');

        $this->assertSame('subclass', $this->raw($base, 'GET', '/_test/markdown/abc')['body']);
        $markdown = $this->raw($base, 'GET', '/_test/markdown/abc.md');
        $this->assertSame('subclass-markdown', $markdown['body']);
        $this->assertSame([SubclassedPage::class], $markdown['pages']);
        $this->assertSame([SubclassedPage::class], $this->raw($base, 'HEAD', '/_test/markdown/abc.md')['pages']);
    }

    public function testATripleLeadingSlashIsNoAddressOfThePage(): void
    {
        $base = $this->server('');

        // for the legacy router either: one leading slash is optional, and a second is the pattern's own
        $this->assertSame(404, $this->raw($base, 'GET', '///_test/unclaimed')['status']);
    }
}
