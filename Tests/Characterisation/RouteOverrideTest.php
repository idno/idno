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

    protected const SHARED_SITE = true;

    /** The file the servers record the page events in: one for the class, so that every test asks for the same servers. */
    private static string $recordFile = '';

    /** @var array<string, string> */
    private array $servers = [];

    protected function setUp(): void
    {
        self::$recordFile = self::$recordFile !== '' ? self::$recordFile : sys_get_temp_dir() . '/idno-route-override-' . bin2hex(random_bytes(6)) . '.jsonl';
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$recordFile);
        self::$recordFile = '';
        parent::tearDownAfterClass();
    }

    /**
     * The site served with a mode of IDNO_TEST_CLAIM_ROUTES ('' registers nothing).
     */
    private function server(string $mode, bool $noPathInfo = false, string $subdirectory = ''): string
    {
        $environment = ['IDNO_TEST_RECORD_PAGES' => self::$recordFile];
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
        foreach (is_file(self::$recordFile) ? (file(self::$recordFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
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

    public function testAPluginsPageOnAFeedSearchOrTagPathTakesItNowThatCoreHasNoLegacyPageThere(): void
    {
        $base = $this->server('feeds');

        // The pages core registered for these paths are deleted: nothing of core's is registered beside a plugin's pattern, so the legacy router
        // chooses the plugin's, and the controller (which shadows no class) steps aside, in every spelling the legacy pattern matched.
        foreach (['/feed.xml', '/feed.xml/', '//feed.xml', '/search', '/search/', '/tag/books', '/tag/books/', '/tag/%FF'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertSame('claimed', $response['body'], $target);
            $this->assertSame([ClaimingPage::class], $response['pages'], $target);
        }
        // the paths the plugin did not take stay the controller's
        foreach (['/rss.xml', '/feed'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertNotSame('claimed', $response['body'], $target);
            $this->assertContains(ControllerPage::class, $response['pages'], $target . ': the controller begun the page');
            $this->assertNotContains(ClaimingPage::class, $response['pages'], $target);
        }
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

    /**
     * Spellings that reach the front controller by its own name, which Symfony's router strips (its base URL) before it matches and which
     * a server may or may not tell the legacy router about (the built-in server sets PATH_INFO, Apache's rewrite does; nginx's shipped
     * configuration leaves only the raw REQUEST_URI). Each is a claimed path with a path no controller serves in its place: what the
     * legacy router answers for the second is what it answered for the first before any controller served it.
     *
     * @return list<array{0: string, 1: string}> the target for the claimed path, the same spelling for the path nobody else serves
     */
    private static function frontControllerSpellings(string $prefix, string $origin): array
    {
        $pairs = [];
        foreach ([['_test/claimable', '_test/legacyonly'], ['_test/%63laimable', '_test/%6cegacyonly'], ['_test/claimable/', '_test/legacyonly/']] as [$claimed, $other]) {
            foreach (['/index.php/%s', '/index.php//%s', '/ind%%65x.php/%s', '/INDEX.PHP/%s'] as $shape) {
                $pairs[] = [$prefix . sprintf($shape, $claimed), $prefix . sprintf($shape, $other)];
            }
            $pairs[] = [$origin . $prefix . '/' . $claimed, $origin . $prefix . '/' . $other];       // absolute-form request target
        }

        return $pairs;
    }

    public function testTheFrontControllersOwnNameNeverBypassesAPluginsClaim(): void
    {
        foreach ([['1', '', ''], ['identical', 'blog', '/blog']] as [$mode, $subdirectory, $prefix]) {
            foreach ([false, true] as $noPathInfo) {
                $base = $this->server($mode, $noPathInfo, $subdirectory);
                foreach (self::frontControllerSpellings($prefix, rtrim($base, '/')) as [$claimedTarget, $otherTarget]) {
                    foreach (['GET', 'HEAD'] as $method) {
                        $label = ($subdirectory ?: 'root') . ($noPathInfo ? ' (REQUEST_URI only) ' : ' (PATH_INFO) ') . $method . ' ' . $claimedTarget;
                        $claimed = $this->raw($base, $method, $claimedTarget);
                        $legacy = $this->raw($base, $method, $otherTarget);
                        $this->assertNotSame('controller', $claimed['body'], $label . ': a claimed path is never the controller\'s');
                        $this->assertNotContains(ControllerPage::class, $claimed['pages'], $label);
                        $this->assertSame($legacy['status'], $claimed['status'], $label . ': the status the legacy router gives the same spelling of a path no controller serves');
                        $this->assertSame($legacy['body'] === 'claimed', $claimed['body'] === 'claimed', $label . ': the plugin\'s page where the legacy router serves it, its 404 where it does not');
                        $this->assertSame($legacy['pages'] === [ClaimingPage::class], $claimed['pages'] === [ClaimingPage::class], $label);
                    }
                }
            }
        }
    }

    public function testAnUnclaimedPathReachedByTheFrontControllersNameIsTheControllers(): void
    {
        foreach ([false, true] as $noPathInfo) {
            $base = $this->server('replaced', $noPathInfo);
            foreach (['/index.php/_test/claimable', '/index.php/_test/claimable/', '/ind%65x.php/_test/claimable', '/index.php//_test/claimable'] as $target) {
                $response = $this->raw($base, 'GET', $target);
                $this->assertSame('controller', $response['body'], $target . ($noPathInfo ? ' (REQUEST_URI only)' : ' (PATH_INFO)'));
            }
        }
    }

    public function testAMarkdownPermalinkVariesOnAcceptInEveryAnswer(): void
    {
        $base = $this->server('');

        foreach (['abc', 'abc.md', 'denied', 'denied.md', 'gone', 'gone.md', 'missing', 'missing.md'] as $name) {
            foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
                $response = $this->raw($base, $method, '/_test/markdown/' . $name);
                $this->assertContains('Accept', array_map('trim', explode(',', implode(',', $response['headers']['vary'] ?? []))), $name . ' ' . $method . ' (' . $response['status'] . ')');
            }
        }
        $this->assertSame([403, 410, 404], [$this->raw($base, 'GET', '/_test/markdown/denied')['status'], $this->raw($base, 'GET', '/_test/markdown/gone.md')['status'], $this->raw($base, 'GET', '/_test/markdown/missing')['status']]);
        // a page that is no permalink says nothing of the kind
        $this->assertNotContains('Accept', array_map('trim', explode(',', implode(',', $this->raw($base, 'GET', '/_test/unclaimed')['headers']['vary'] ?? []))));
    }

    /**
     * The paths core had pages for before the home page's feeds, search and tags moved to controllers, with what the controller answers for each
     * (the search forwards, the others draw): a plugin's page registered AFTER core's, under any pattern that is not core's own, never took them,
     * because the legacy router chooses the first pattern that matches and core's came first. The controllers keep that order.
     *
     * @return list<string>
     */
    private static function corePaths(): array
    {
        return ['/', '/content/all', '/feed', '/feed.xml', '/rss.xml', '/search', '/tag/books'];
    }

    public function testABroadPatternARegisteredAfterCoresNeverTakesAPathCoreHasAPageFor(): void
    {
        $base = $this->server('broad');
        [$cookie, $token] = $this->token($base);

        foreach (self::corePaths() as $target) {
            foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
                $response = $this->raw($base, $method, $target);
                $label = $method . ' ' . $target . ' (' . $response['status'] . ')';
                $this->assertNotSame('claimed', $response['body'], $label);
                $this->assertNotContains(ClaimingPage::class, $response['pages'], $label);
                $this->assertContains(ControllerPage::class, $response['pages'], $label . ': the controller begun the page');
            }
        }
        // the paths core never had, which the same plugin serves, for every method
        foreach (['/zzz-short', '/zzz/short/', '//zzz-short'] as $target) {
            $this->assertSame('claimed', $this->raw($base, 'GET', $target)['body'], $target);
            $this->assertSame([ClaimingPage::class], $this->raw($base, 'HEAD', $target)['pages'], $target);
            $post = $this->raw($base, 'POST', $target, ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
            $this->assertSame('claimed-post', $post['body'], $target);
        }
    }

    public function testAClaimOfCoresExactPatternsIsEveryMethodsForEveryPathItServed(): void
    {
        foreach (['corehijack', 'coreidentical'] as $mode) {
            $base = $this->server($mode);
            [$cookie, $token] = $this->token($base);
            $query = '?' . http_build_query($token);
            foreach (self::corePaths() as $target) {
                $label = $mode . ' ' . $target;
                $this->assertSame('claimed', $this->raw($base, 'GET', $target)['body'], $label . ' GET');
                $this->assertSame([ClaimingPage::class], $this->raw($base, 'HEAD', $target)['pages'], $label . ' HEAD');
                $this->assertSame([ClaimingPage::class], $this->raw($base, 'OPTIONS', $target)['pages'], $label . ' OPTIONS');
                $post = $this->raw($base, 'POST', $target, ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
                $this->assertSame('claimed-post', $post['body'], $label . ' POST');
                $this->assertSame('claimed-put', $this->raw($base, 'PUT', $target . $query, ['Cookie' => $cookie])['body'], $label . ' PUT');
                $this->assertSame('claimed-delete', $this->raw($base, 'DELETE', $target . $query, ['Cookie' => $cookie])['body'], $label . ' DELETE');
            }
            // (and in the spellings of the address, as the claim of any path is)
            foreach (['/feed.xml/', '//feed.xml', '/fe%65d.xml', '/index.php/feed.xml'] as $target) {
                $this->assertSame('claimed', $this->raw($base, 'GET', $target)['body'], $mode . ' ' . $target);
            }
        }
    }

    public function testAClaimOfOneOfCoresPatternsIsThatPatternsPathsOnly(): void
    {
        $base = $this->server('feedhijack');

        $this->assertSame('claimed', $this->raw($base, 'GET', '/feed.xml')['body']);
        foreach (['/feed', '/rss.xml', '/search'] as $target) {
            $response = $this->raw($base, 'GET', $target);
            $this->assertNotSame('claimed', $response['body'], $target);
            $this->assertContains(ControllerPage::class, $response['pages'], $target);
        }
    }

    public function testAHijackedPatternThatIsNotCoresButMatchesItsPathsStillWinsAsItAlwaysDid(): void
    {
        // hijackRoute() puts the pattern in front of every other: it was the first to match, whatever the pattern, so it still is the plugin's
        $base = $this->server('hijackbroad');

        foreach (['/feed', '/feed.xml', '/search', '/search/'] as $target) {
            $this->assertSame('claimed', $this->raw($base, 'GET', $target)['body'], $target);
        }
        $this->assertNotSame('claimed', $this->raw($base, 'GET', '/rss.xml')['body'], 'a path the pattern does not match');
        $this->assertNotSame('claimed', $this->raw($base, 'GET', '/tag/books')['body']);
    }

    /**
     * A page that moved to a controller keeps its place in the legacy router's table (a placeholder under core's own pattern, registered where
     * core registered the page), so everything the legacy router's own rules decided before the page moved is still decided by them: a plugin's
     * `addRoute()` of the identical pattern replaces the handler in place, `hijackRoute()` moves it to the front, an exact key beats every pattern,
     * a pattern registered before core's wins and one after loses, and a registration core makes later overwrites an earlier one. `/feed.xml` is registered by
     * `Idno::registerPages()` before the plugins are built, `/.well-known/webfinger` by a component built after them.
     *
     * @return array<string, array{0: string, 1: string}> mode => who serves `/feed.xml`, who serves `/.well-known/webfinger` ('controller' or 'plugin')
     */
    public static function movedModes(): array
    {
        return [
            'moved-none' => ['controller', 'controller'],
            'moved-identical' => ['plugin', 'plugin'],
            'moved-hijack' => ['plugin', 'plugin'],
            'moved-exact' => ['plugin', 'plugin'],
            'moved-broad' => ['controller', 'plugin'],
            'moved-prehijack' => ['plugin', 'controller'],
        ];
    }

    public function testAMovedPathIsServedAsTheLegacyRoutersOwnRulesChooseIt(): void
    {
        foreach (self::movedModes() as $mode => $expected) {
            $base = $this->server($mode);
            [$cookie, $token] = $this->token($base);
            foreach (['/feed.xml' => $expected[0], '/.well-known/webfinger' => $expected[1]] as $path => $who) {
                $label = $mode . ' ' . $path;
                $get = $this->raw($base, 'GET', $path);
                $head = $this->raw($base, 'HEAD', $path);
                $post = $this->raw($base, 'POST', $path, ['Cookie' => $cookie, 'Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($token));
                if ($who === 'plugin') {
                    $this->assertSame('claimed', $get['body'], $label . ' GET');
                    $this->assertSame([ClaimingPage::class], $head['pages'], $label . ' HEAD');
                    $this->assertSame('claimed-post', $post['body'], $label . ' POST');
                } else {
                    $this->assertNotSame('claimed', $get['body'], $label . ' GET');
                    $this->assertContains(ControllerPage::class, $get['pages'], $label . ' GET: the controller');
                    $this->assertContains(ControllerPage::class, $head['pages'], $label . ' HEAD');
                    $this->assertSame(404, $post['status'], $label . ' POST: a method the controller does not take is the legacy router\'s, which has no page');
                    $this->assertNotSame('claimed-post', $post['body'], $label . ' POST');
                }
            }
        }
    }

    public function testAnExactKeyOfAPluginTakesTheHomePage(): void
    {
        // `addRoute('/')`: the key is exactly the path, which Toro looks up before any pattern
        $base = $this->server('moved-exact');

        $this->assertSame('claimed', $this->raw($base, 'GET', '/')['body']);
        // by the front controller's name the legacy router has no page for the path (it never had), so it answers its 404: what is never served is the controller's home page
        $bare = $this->raw($base, 'GET', '/index.php');
        $this->assertNotContains(ControllerPage::class, $bare['pages']);
        $this->assertSame(404, $bare['status']);
        // (the built-in server gives the legacy router `/` for `/index.php/`, whose exact key is the plugin's)
        $slash = $this->raw($base, 'GET', '/index.php/');
        $this->assertNotContains(ControllerPage::class, $slash['pages']);
        $this->assertSame('claimed', $this->raw($base, 'GET', '/feed.xml')['body'], 'an exact, unescaped key');
    }

    public function testAHijackOfTheRootTakesTheHomePageOfASiteInASubdirectory(): void
    {
        foreach ([false, true] as $noPathInfo) {
            $base = $this->server('moved-hijackroot', $noPathInfo, 'blog');
            $label = $noPathInfo ? ' (REQUEST_URI only)' : ' (PATH_INFO)';

            $this->assertSame('claimed', $this->raw($base, 'GET', '/blog/')['body'], 'GET /blog/' . $label);
            $this->assertSame([ClaimingPage::class], $this->raw($base, 'HEAD', '/blog/')['pages'], 'HEAD /blog/' . $label);
        }
    }

    public function testABroadPatternServesThePathsCoreNeverHadAndLosesTheOnesItHadFirst(): void
    {
        $base = $this->server('moved-broad');

        $this->assertSame('claimed', $this->raw($base, 'GET', '/zzz-short')['body']);
        $this->assertNotSame('claimed', $this->raw($base, 'GET', '/feed.xml')['body'], 'registered after core\'s early pattern');
        $this->assertSame('claimed', $this->raw($base, 'GET', '/.well-known/webfinger')['body'], 'registered before core\'s late pattern, so it matched first');
    }

    public function testATripleLeadingSlashIsNoAddressOfThePage(): void
    {
        $base = $this->server('');

        // for the legacy router either: one leading slash is optional, and a second is the pattern's own
        $this->assertSame(404, $this->raw($base, 'GET', '///_test/unclaimed')['status']);
    }
}
