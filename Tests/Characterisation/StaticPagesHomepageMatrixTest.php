<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A static page chosen as the home page, over HTTP, on a site of the test's own: what `/` answers to each viewer for each kind of chosen page,
 * on an open site, on a walled garden and on a walled garden whose home route a plugin made public, in the template types a page can be asked
 * for, with the home page's replacement event (`homepage/replace`) answered by the StaticPages plugin and by fixture listeners.
 *
 * A viewer who may read the chosen page gets it; one who may not gets the ordinary home page (no refusal, no redirect to the root, which asked
 * for the same page again); the site's own rule (a private site refuses a visitor before the event) comes first. Each assertion is a status,
 * a marker of what was drawn (the static page's title or the stream's note), and the lines that decide caching and type.
 */
final class StaticPagesHomepageMatrixTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const STREAM = 'a note in the stream of the matrix';

    private const FORWARD = 'http://example.test/away';

    /** the types of a page: the query that asks for it, and the content type the answer has */
    private const TYPES = [
        'default' => ['', 'text/html;charset=UTF-8'],
        'json' => ['?_t=json', 'application/json'],
        'rss' => ['?_t=rss', 'application/rss+xml'],
        'jsonp' => ['?_t=jsonp&callback=cb', 'application/x-javascript;charset=utf-8'],
        'markdown' => ['?_t=md', 'text/markdown; charset=utf-8'],
    ];

    protected const SHARED_SITE = true;

    /** @var array<string, string> the id of the page of each kind, by name (seeded once for the class's site) */
    private static array $ids = [];

    /** @var array<string, string> the address of the page of each kind that exists, by name */
    private static array $paths = [];

    /** @var array<string, string> the session cookie of each viewer */
    private array $cookies = ['visitor' => ''];

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->addUser('bob', 'correct-horse-battery-9');
        $this->addUser('carol', 'correct-horse-battery-9');
        self::$ids = $this->runInSite(
            '$ada = \Idno\Entities\User::getByHandle("ada"); $bob = \Idno\Entities\User::getByHandle("bob"); $carol = \Idno\Entities\User::getByHandle("carol");'
            . '\Idno\Core\Idno::site()->session()->logUserOn($ada);'
            . '$group = new \Idno\Entities\AccessGroup(); $group->title = "Friends"; $group->owner = $ada->getUUID(); $group->addMember($bob->getUUID()); $group->save(true);'
            . '$mk = function ($title, $access, $extra = []) use ($carol) { $p = new \IdnoPlugins\StaticPages\StaticPage(); $p->setOwner($carol); $p->setTitle($title); $p->body = "<p>Body of $title</p>"; $p->setAccess($access); foreach ($extra as $k => $v) { $p->$k = $v; } $p->save(true); return $p->getID(); };'
            . '$note = new \IdnoPlugins\Status\Status(); $note->body = ' . $this->literal(self::STREAM) . '; $note->setOwner($ada); $note->setAccess("PUBLIC"); $note->save(true);'
            . '$gone = $mk("Marker gone page", "PUBLIC"); \IdnoPlugins\StaticPages\StaticPage::getByID($gone)->delete();'
            . 'return ["public" => $mk("Marker public page", "PUBLIC"), "site" => $mk("Marker members page", "SITE"), "private" => $mk("Marker private page", $carol->getUUID()),'
            . ' "group" => $mk("Marker group page", $group->getUUID()), "draft" => $mk("Marker draft page", "PUBLIC", ["publish_status" => "draft"]),'
            . ' "forward" => $mk("Marker forwarding page", "PUBLIC", ["forward_url" => ' . $this->literal(self::FORWARD) . ']), "deleted" => $gone, "missing" => "no-such-page"];'
        );
        self::$paths = $this->runInSite('\Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle("ada")); $paths = []; foreach (json_decode(' . $this->literal((string) json_encode(array_diff_key(self::$ids, ['deleted' => 1, 'missing' => 1]))) . ', true) as $kind => $id) { $paths[$kind] = parse_url(\IdnoPlugins\StaticPages\StaticPage::getByID($id)->getURL(), PHP_URL_PATH); } return $paths;');
        // the choice of home page is the one setting the tests change: it is saved once as it is when no page is chosen, and put back after each test
        $this->choose('');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bob', 'carol', 'ada'] as $handle) {
            $this->cookies[$handle] = $this->loginCookie($handle, 'correct-horse-battery-9');
        }
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

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->choose('');
            // a server that unsets a page that is gone saves the settings it was started with (its environment's too): put back the site's own
            $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $saved = false; foreach (["walled_garden", "test_homepage_listeners"] as $name) { if (isset($c->config[$name])) { unset($c->config[$name]); $saved = true; } } if ($saved) { $c->save(); } return 1;');
        }
        parent::tearDown();
    }

    private function choose(string $value): void
    {
        $this->runInSite('\Idno\Core\Idno::site()->config()->staticPages = ' . ($value === '' ? '[]' : '["homepage" => ' . $this->literal($value) . ']') . '; \Idno\Core\Idno::site()->config()->save(); return 1;');
    }

    private function request(string $base, string $viewer, string $method, string $path = '/'): array
    {
        return $this->raw($base, $method, $path, $this->cookies[$viewer] !== '' ? ['Cookie' => $this->cookies[$viewer]] : []);
    }

    /**
     * Who is shown the static page of each kind (the others get the stream; a page that forwards sends the others on).
     *
     * @return array<string, list<string>>
     */
    private static function readers(): array
    {
        return [
            'public' => ['visitor', 'bob', 'carol', 'ada'],
            'site' => ['bob', 'carol', 'ada'],
            'private' => ['carol', 'ada'],
            // (the page's owner is not in the group: the entity layer does not find a group's page for a viewer who may not read the group)
            'group' => ['bob', 'ada'],
            'draft' => ['carol', 'ada'],
            'forward' => ['ada'],
            'deleted' => [],
            'missing' => [],
        ];
    }

    /**
     * Asks for `/` of each viewer, for each kind of chosen page, and checks the answer against who may read it. `$visitorRefused`: the site
     * refuses a visitor before the home page is asked anything (a walled garden whose home route is not public). `$permalinkViewers`: the
     * viewers whose home page is also checked against the page's own address (where a plugin made the home route public and the address is not, a
     * visitor is shown a public page on the home page and refused at its address, a decision of its own: only the visitor is left out there).
     *
     * @param list<string> $permalinkViewers
     */
    private function assertEveryViewerGetsWhatHeMay(string $base, bool $visitorRefused, array $permalinkViewers = ['visitor', 'bob', 'carol', 'ada']): void
    {
        foreach (self::readers() as $kind => $readers) {
            $id = self::$ids[$kind];
            $title = ['site' => 'Marker members page', 'forward' => 'Marker forwarding page'][$kind] ?? 'Marker ' . $kind . ' page';
            $this->choose($id);
            // (the administrator last: asking for a page that is gone unsets the setting)
            foreach (['visitor', 'bob', 'carol', 'ada'] as $viewer) {
                foreach (self::TYPES as $type => [$query, $contentType]) {
                    $where = $kind . ' for ' . $viewer . ' as ' . $type;
                    $r = $this->request($base, $viewer, 'GET', '/' . $query);
                    if ($viewer === 'visitor' && $visitorRefused) {
                        $this->assertSame(403, $r['status'], $where);
                        $this->assertStringNotContainsString('Marker', $r['body'], $where);
                        $this->assertStringNotContainsString(self::STREAM, $r['body'], $where);
                        continue;
                    }
                    if (in_array($viewer, $readers, true)) {
                        $this->assertSame(200, $r['status'], $where);
                        $this->assertStringContainsString($title, $r['body'], $where);
                        $this->assertStringNotContainsString(self::STREAM, $r['body'], $where);
                        $this->assertSame(strtolower(str_replace('; ', ';', $contentType)), strtolower(str_replace('; ', ';', $r['headers']['content-type'][0] ?? '')), $where);
                        $this->assertSame(['no-store, no-cache, must-revalidate'], $r['headers']['cache-control'] ?? [], $where);
                        $this->assertArrayHasKey('last-modified', $r['headers'], $where);
                    } elseif ($kind === 'forward') {
                        // a visitor, a member and the owner are sent on to the page's forward address (carrying the template type); the administrator sees the page
                        $this->assertSame(302, $r['status'], $where);
                        $this->assertSame([self::FORWARD . ($type === 'default' ? '' : '?_t=' . $type)], $r['headers']['location'] ?? [], $where);
                        $this->assertStringNotContainsString(self::STREAM, $r['body'], $where);
                        $this->assertSame(['no-store, no-cache, must-revalidate'], $r['headers']['cache-control'] ?? [], $where);
                    } else {
                        // the ordinary home page: no refusal, no redirect, none of the page
                        $this->assertSame(200, $r['status'], $where);
                        $this->assertStringContainsString(self::STREAM, $r['body'], $where);
                        $this->assertStringNotContainsString('Marker', $r['body'], $where);
                        $this->assertSame(strtolower(str_replace('; ', ';', $contentType)), strtolower(str_replace('; ', ';', $r['headers']['content-type'][0] ?? '')), $where);
                        $this->assertSame(['no-store, no-cache, must-revalidate'], $r['headers']['cache-control'] ?? [], $where);
                        $this->assertArrayNotHasKey('location', $r['headers'], $where);
                    }
                }
            }
            // The home page shows the static page exactly when the page's own address would (the same viewer, the same page): a viewer the permalink
            // refuses, hides or sends elsewhere is never shown the page on the home page, and one it shows is
            if (isset(self::$paths[$kind])) {
                foreach ($permalinkViewers as $viewer) {
                    $permalink = $this->request($base, $viewer, 'GET', self::$paths[$kind]);
                    $home = $this->request($base, $viewer, 'GET', '/');
                    $this->assertSame(
                        $permalink['status'] === 200 && str_contains($permalink['body'], $title),
                        $home['status'] === 200 && str_contains($home['body'], $title),
                        'the home page and ' . self::$paths[$kind] . ' agree about ' . $viewer
                    );
                    if ($permalink['status'] === 302) {
                        $this->assertSame($permalink['headers']['location'] ?? [], $home['headers']['location'] ?? [], 'forwarding for ' . $viewer);
                    }
                }
            }
            // HEAD and OPTIONS of the root, for a visitor, a viewer who reads the page and one who does not (OPTIONS is answered before the walled garden is asked)
            foreach (['visitor', 'bob', 'ada'] as $viewer) {
                $head = $this->request($base, $viewer, 'HEAD');
                $expected = $viewer === 'visitor' && $visitorRefused ? 403 : (in_array($viewer, $readers, true) || $kind !== 'forward' ? 200 : 302);
                $this->assertSame($expected, $head['status'], 'HEAD ' . $kind . ' for ' . $viewer);
                $this->assertSame('', $head['body'], 'HEAD ' . $kind . ' for ' . $viewer);
                $options = $this->request($base, $viewer, 'OPTIONS');
                $this->assertSame(204, $options['status'], 'OPTIONS ' . $kind . ' for ' . $viewer);
                $this->assertSame(['GET, POST, HEAD, OPTIONS, PUT, DELETE'], $options['headers']['access-control-allow-methods'] ?? []);
            }
        }
    }

    private function assertNoDeprecation(string $base): void
    {
        $log = is_file($this->extraServerLog($base)) ? (string) file_get_contents($this->extraServerLog($base)) : '';
        $this->assertStringNotContainsString('Deprecated', $log . $this->serverLog());
    }

    public function testAnOpenSiteGivesEachViewerWhatHeMayRead(): void
    {
        $this->assertEveryViewerGetsWhatHeMay($this->baseUrl(), false);
        $this->assertNoDeprecation($this->baseUrl());
    }

    public function testOnAWalledGardenAVisitorIsRefusedBeforeTheStaticPageIsAskedAbout(): void
    {
        $base = $this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']);

        $this->assertEveryViewerGetsWhatHeMay($base, true);
        $this->assertNoDeprecation($base);
    }

    /**
     * A plugin that made the home route public on a private site (`addPublicRoute()`) shows a visitor the home page, which is the static page
     * only when the visitor may read that page itself: a public one is shown, one that is not public is not.
     */
    public function testOnAWalledGardenWithAPublicHomeRouteAVisitorGetsOnlyAPageHeMayRead(): void
    {
        $base = $this->startExtraServer(['IDNO_WALLED_GARDEN' => '1', 'IDNO_TEST_HOMEPAGE_LISTENERS' => 'public']);

        $this->assertEveryViewerGetsWhatHeMay($base, false, ['bob', 'carol', 'ada']);
        $this->assertNoDeprecation($base);
    }

    public function testWithNoStaticPageChosenTheStreamIsTheHomePage(): void
    {
        $this->choose('');

        foreach (['visitor', 'bob', 'ada'] as $viewer) {
            $r = $this->request($this->baseUrl(), $viewer, 'GET');
            $this->assertSame(200, $r['status'], $viewer);
            $this->assertStringContainsString(self::STREAM, $r['body'], $viewer);
        }
    }

    public function testAHomePageThatIsGoneIsUnsetOnlyWhenAnAdministratorAsks(): void
    {
        foreach (['deleted', 'missing'] as $kind) {
            $this->choose(self::$ids[$kind]);
            $this->request($this->baseUrl(), 'bob', 'GET');
            $this->assertSame(self::$ids[$kind], $this->setting(), $kind . ': a member leaves the setting');

            $admin = $this->request($this->baseUrl(), 'ada', 'GET');
            $this->assertSame(200, $admin['status'], $kind);
            $this->assertStringContainsString(self::STREAM, $admin['body'], $kind . ': the stream is served at once, with no redirect');
            $this->assertSame('', $this->setting(), $kind . ': the administrator unset it');
        }
    }

    private function setting(): string
    {
        return (string) $this->runInSite('return \Idno\Core\Idno::site()->plugins()->get("StaticPages")->getCurrentHomepageId() ?: "";');
    }

    // ---------------------------------------------------------------- listeners of the event

    public function testAListenerThatThrowsGivesTheSitesErrorPageAndIsReportedOnce(): void
    {
        $this->choose(self::$ids['public']);
        $base = $this->startExtraServer(['IDNO_TEST_HOMEPAGE_LISTENERS' => 'throw']);

        $r = $this->request($base, 'visitor', 'GET');

        $this->assertSame(500, $r['status']);
        $this->assertStringContainsString('<!DOCTYPE html>', $r['body'], 'the site\'s own page');
        $this->assertStringNotContainsString('Marker public page', $r['body']);
        $this->assertStringNotContainsString(self::STREAM, $r['body']);
        $log = (string) file_get_contents($this->extraServerLog($base));
        $this->assertSame(1, substr_count($log, 'the homepage listener broke'), $log);
        // (the streams under /content/ never ask: the home page is only the root's)
        $this->assertSame(200, $this->request($base, 'visitor', 'GET', '/content/all')['status']);
    }

    public function testAListenerThatSetsNoResponseIsIgnored(): void
    {
        $this->choose('');
        $base = $this->startExtraServer(['IDNO_TEST_HOMEPAGE_LISTENERS' => 'nonresponse']);

        $r = $this->request($base, 'visitor', 'GET');

        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString(self::STREAM, $r['body']);
        $this->assertStringNotContainsString('NOT-A-RESPONSE', $r['body']);
        // and what it set does not stop a page from being the home page
        $this->choose(self::$ids['public']);
        $this->assertStringContainsString('<title class="p-name">Marker public page</title>', $this->request($base, 'visitor', 'GET')['body']);
    }

    public function testTheFirstListenerToSetAResponseWins(): void
    {
        $this->choose(self::$ids['public']);
        $base = $this->startExtraServer(['IDNO_TEST_HOMEPAGE_LISTENERS' => 'first,late,erase']);

        $r = $this->request($base, 'visitor', 'GET');

        $this->assertSame(200, $r['status']);
        $this->assertSame('FIRST-LISTENER-PAGE', $r['body'], 'neither the plugin\'s page nor a later listener replaced it');
    }

    public function testALaterListenerCannotReplaceOrEraseThePluginsPage(): void
    {
        $this->choose(self::$ids['public']);
        $base = $this->startExtraServer(['IDNO_TEST_HOMEPAGE_LISTENERS' => 'late,erase']);

        $r = $this->request($base, 'visitor', 'GET');

        $this->assertSame(200, $r['status']);
        // (the page's own title, in its shell: the stream lists a public static page too, under the site's title)
        $this->assertStringContainsString('<title class="p-name">Marker public page</title>', $r['body']);
        $this->assertStringNotContainsString(self::STREAM, $r['body']);
        $this->assertStringNotContainsString('LATE-LISTENER-PAGE', $r['body']);
    }

    public function testAListenerCanSupplyAPageWhenNoStaticPageIsChosen(): void
    {
        $this->choose('');
        $base = $this->startExtraServer(['IDNO_TEST_HOMEPAGE_LISTENERS' => 'first']);

        $this->assertSame('FIRST-LISTENER-PAGE', $this->request($base, 'visitor', 'GET')['body']);
        // the streams under /content/ stay the stream, and so does a feed
        $this->assertStringContainsString(self::STREAM, $this->request($base, 'visitor', 'GET', '/content/all')['body']);
        $this->assertStringContainsString(self::STREAM, $this->request($base, 'visitor', 'GET', '/feed.xml')['body']);
    }

    // ---------------------------------------------------------------- where the root is

    /**
     * The root is the controller's home action wherever the site is served from: under a subdirectory, and by its front controller's name.
     */
    public function testTheRootIsReplacedUnderASubdirectoryAndAtTheFrontController(): void
    {
        $this->choose(self::$ids['public']);
        $base = $this->startExtraServer(['IDNO_TEST_SUBDIRECTORY' => 'blog']);

        foreach (['/blog/', '/blog/index.php'] as $path) {
            $r = $this->raw($base, 'GET', $path);
            $this->assertSame(200, $r['status'], $path);
            $this->assertStringContainsString('<title class="p-name">Marker public page</title>', $r['body'], $path);
        }
        $this->assertStringContainsString(self::STREAM, $this->raw($base, 'GET', '/blog/content/all')['body']);
        $this->assertStringContainsString(self::STREAM, $this->raw($base, 'GET', '/blog/feed.xml')['body']);
    }

    public function testTheFrontControllerOfASiteAtTheRootIsTheRoot(): void
    {
        $this->choose(self::$ids['public']);

        $r = $this->raw($this->baseUrl(), 'GET', '/index.php');

        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<title class="p-name">Marker public page</title>', $r['body']);
    }

    /**
     * A subclass of the home page's shell that a plugin registered for the root is its own page: it is not replaced when a static page is
     * chosen (it asked to be the home page, and the shell never offers the root to the event).
     */
    public function testAThirdPartyPageThatTookTheRootIsNotReplaced(): void
    {
        $this->choose(self::$ids['public']);
        $base = $this->startExtraServer(['IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack']);

        $r = $this->raw($base, 'GET', '/');

        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString(self::STREAM, $r['body']);
        $this->assertStringNotContainsString('<title class="p-name">Marker public page</title>', $r['body']);
        $this->assertStringContainsString('<body class="tests_fixtures_thirdpartyhomepage', $r['body']);
    }
}
