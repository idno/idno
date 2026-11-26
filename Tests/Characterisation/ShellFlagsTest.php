<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;
use Tests\Support\RecordedAnswers;

/**
 * A request served through a kept shell is the page its controller serves: the flags of the action the shell names (`PageShell::ACTION`), its
 * Markdown form and `Vary`, a streamed response and a page that takes no form token, over HTTP on a site of the test's own. The fixtures are
 * `Tests\Fixtures\FixtureShell` and its subclass, `FixtureAccountShell` and `FixtureTokenlessShell`, each with the action of
 * `FixtureShellController` that serves the same page, and (for the home page) the mode `homepage-hijack` of IDNO_TEST_CLAIM_ROUTES.
 */
final class ShellFlagsTest extends PrivateSiteTestCase
{
    use LogsIn;
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    private const SHELL = '/_test/fixture-shell-sub/';

    private const CONTROLLER = '/_test/fixture-shell/controller/';

    private const ACCOUNT_SHELL = '/account/_test-fixture-shell/';

    private const ACCOUNT_CONTROLLER = '/account/_test-fixture-shell-controller/';

    private const TOKENLESS_SHELL = '/_test/fixture-shell-tokenless/';

    private const TOKENLESS_CONTROLLER = '/_test/fixture-shell-tokenless-controller/';

    /** The file a streamed page writes when its stream is sent. */
    private static string $marker = '';

    /** A small file and a large one, which the `file` case of the fixture page answers. */
    private static string $file = '';

    private static string $largeFile = '';

    private const SIZE = 5000;

    private const LARGE_MEGABYTES = 64;

    protected function setUp(): void
    {
        self::$marker = self::$marker !== '' ? self::$marker : sys_get_temp_dir() . '/idno-shell-flags-' . bin2hex(random_bytes(6));
        if (self::$file === '') {
            self::$file = sys_get_temp_dir() . '/idno-shell-flags-file-' . bin2hex(random_bytes(6));
            file_put_contents(self::$file, substr(str_repeat('0123456789abcdefghij', 300), 0, self::SIZE));
        }
        parent::setUp();
        if (is_file(self::$marker)) {
            unlink(self::$marker);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_FIXTURE_SHELL' => '1', 'IDNO_TEST_STREAM_MARKER' => self::$marker, 'IDNO_TEST_SHELL_FILE' => self::$file, 'IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack'];
    }

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->apiKeyOf('ada');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$marker !== '' && is_file(self::$marker)) {
            unlink(self::$marker);
        }
        self::$marker = '';
        foreach ([self::$file, self::$largeFile] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
        self::$file = self::$largeFile = '';
        parent::tearDownAfterClass();
    }

    /**
     * An answer as it is compared with the other path's: the page's own address (the shell's and the controller's differ) is `{page}`, and the
     * body's class (it names the class that served the page: the shell's subclass or the shell) is left out.
     */
    private function comparable(SiteResponse $response): string
    {
        $text = self::normalise($response, $this->baseUrl());
        $text = (string) preg_replace('~/(?:_test/fixture-shell(?:/controller|-sub|-tokenless-controller|-tokenless|-public-controller|-public)?|_test/fixture-account-shell-sub|account/_test-fixture-shell(?:-controller)?)(?=[/.?"<>\s]|$)~', '{page}', $text);

        // (a login link names the address it came from)
        $text = (string) preg_replace('~fwd=[A-Za-z0-9_,-]+~', 'fwd=X', $text);

        return (string) preg_replace('~^\s*<body[^\n]*\n~m', '', $text);
    }

    public function testAShellServedPageCarriesItsControllersMarkdownFlag(): void
    {
        $shell = $this->site()->request('GET', self::SHELL);
        $controller = $this->site()->request('GET', self::CONTROLLER);

        $this->assertSame(200, $shell->status, $shell->body);
        $this->assertStringContainsString('Accept', (string) $shell->header('Vary'), 'a Markdown permalink answers by Accept, whoever serves it');
        $this->assertSame($this->comparable($controller), $this->comparable($shell));

        $shellMarkdown = $this->site()->request('GET', '/_test/fixture-shell-sub.md');
        $controllerMarkdown = $this->site()->request('GET', '/_test/fixture-shell/controller.md');

        $this->assertSame(200, $shellMarkdown->status, $shellMarkdown->body);
        $this->assertStringContainsString('text/markdown', (string) $shellMarkdown->header('Content-Type'), 'the .md address is the Markdown type');
        $this->assertStringContainsString('Accept', (string) $shellMarkdown->header('Vary'));
        $this->assertSame($this->comparable($controllerMarkdown), $this->comparable($shellMarkdown));
    }

    private const ACCOUNT_SUBCLASS = '/_test/fixture-account-shell-sub/';

    public function testAnAccountActionRefusesASignedApiRequestOnWhateverRouteItsShellIsOn(): void
    {
        $controller = $this->site()->request('GET', self::ACCOUNT_CONTROLLER, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), self::ACCOUNT_CONTROLLER));
        $this->assertSame(403, $controller->status, $controller->body);

        // a subclass a plugin registered outside /account/ is no more open than the controller's page
        $outside = $this->site()->request('GET', self::ACCOUNT_SUBCLASS, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), self::ACCOUNT_SUBCLASS));
        $this->assertSame(403, $outside->status, $outside->body);
        // (the router's refusal is drawn as a page of its own and the controller's in the page itself: the answer's status and kind are the same)
        $this->assertSame($controller->header('Content-Type'), $outside->header('Content-Type'));

        // (and on its own route, under /account/, as before)
        $inside = $this->site()->request('GET', self::ACCOUNT_SHELL, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), self::ACCOUNT_SHELL));
        $this->assertSame(403, $inside->status);

        // an ordinary request is served
        $this->assertSame(200, $this->site()->request('GET', self::ACCOUNT_SUBCLASS)->status);
    }

    public function testASettingsActionIsAPageOfSettingsOnWhateverRouteItsShellIsOn(): void
    {
        foreach (['?_t=markdown', '?_t=jsonp&callback=cb'] as $query) {
            $controller = $this->site()->request('GET', self::ACCOUNT_CONTROLLER . $query);
            $outside = $this->site()->request('GET', self::ACCOUNT_SUBCLASS . $query);
            $inside = $this->site()->request('GET', self::ACCOUNT_SHELL . $query);

            $this->assertSame(200, $controller->status, $query);
            $this->assertSame($this->comparable($controller), $this->comparable($outside), $query . ' outside /account/');
            $this->assertSame($this->comparable($controller), $this->comparable($inside), $query . ' inside /account/');
        }
        $markdown = $this->site()->request('GET', self::ACCOUNT_SUBCLASS . '?_t=markdown');
        $this->assertStringContainsString('no Markdown form', $markdown->body, 'a settings page is a title and one line');
        $jsonp = $this->site()->request('GET', self::ACCOUNT_SUBCLASS . '?_t=jsonp&callback=cb');
        $this->assertStringContainsString('text/html', (string) $jsonp->header('Content-Type'), 'a settings page is never a script');
    }

    public function testOnAPrivateSiteTheShellsRegistrationDecidesWhoIsLetIn(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1', 'IDNO_TEST_FIXTURE_SHELL' => '1']));

        // the action says the page is public: the controller's page is served to a visitor
        $this->assertSame(200, $client->request('GET', '/_test/fixture-shell-public-controller')->status);
        // the shell's route is not a public route (nobody called addPublicRoute()), so the shell's page is not served to a visitor, whatever the action says
        $this->assertNotSame(200, $client->request('GET', '/_test/fixture-shell-public/')->status);
        // and an action that does not say it is public never opens a shell
        $this->assertNotSame(200, $client->request('GET', self::SHELL)->status);
        $this->assertNotSame(200, $client->request('GET', self::ACCOUNT_SUBCLASS)->status);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function answers(): array
    {
        return [
            'the 403' => ['GET', '?case=denied', []],
            'the 404' => ['GET', '?case=notfound', []],
            'the 410' => ['GET', '?case=gone', []],
            'a forward' => ['GET', '?case=redirect', []],
            'a 304' => ['GET', '?case=conditional', ['If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT']],
            'a HEAD' => ['HEAD', '', []],
            'the page' => ['GET', '', []],
        ];
    }

    /**
     * @dataProvider answers
     *
     * @param array<string, string> $headers
     */
    public function testEveryAnswerOfAMarkdownPermalinkShellVariesByAccept(string $method, string $query, array $headers): void
    {
        $shell = $this->site()->request($method, self::SHELL . $query, $headers);
        $controller = $this->site()->request($method, self::CONTROLLER . $query, $headers);

        $this->assertStringContainsString('Accept', (string) $controller->header('Vary'), 'the controller says so');
        $this->assertStringContainsString('Accept', (string) $shell->header('Vary'), 'and so does the shell');
        $this->assertSame($controller->status, $shell->status);
        $this->assertSame($this->comparable($controller), $this->comparable($shell));
    }

    public function testAShellStreamsAStreamedResponse(): void
    {
        $response = $this->site()->request('GET', '/_test/fixture-shell/?case=stream');

        $this->assertSame(200, $response->status);
        $this->assertSame('streamed', $response->body);
        $this->assertFileExists(self::$marker, 'the stream was sent');
    }

    public function testAHeadOfAStreamedResponseRunsNoCallback(): void
    {
        $response = $this->site()->request('HEAD', '/_test/fixture-shell/?case=stream');

        $this->assertSame(200, $response->status);
        $this->assertSame('', $response->body);
        $this->assertFileDoesNotExist(self::$marker, 'a HEAD never runs the stream');
    }

    private function bytes(int $from, int $to): string
    {
        return substr((string) file_get_contents(self::$file), $from, $to - $from + 1);
    }

    public function testAFileResponseIsPreparedAsTheKernelPreparesIt(): void
    {
        $path = '/_test/fixture-shell/controller/?case=file';
        $shellPath = '/_test/fixture-shell-sub/?case=file';

        $whole = $this->site()->request('GET', $shellPath);
        $this->assertSame(200, $whole->status);
        $this->assertSame((string) self::SIZE, $whole->header('Content-Length'));
        $this->assertSame('application/octet-stream', $whole->header('Content-Type'));
        $this->assertSame($this->bytes(0, self::SIZE - 1), $whole->body);
        $this->assertSame($this->comparable($this->site()->request('GET', $path)), $this->comparable($whole));

        $head = $this->site()->request('HEAD', $shellPath);
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertSame((string) self::SIZE, $head->header('Content-Length'));
        $this->assertSame($this->comparable($this->site()->request('HEAD', $path)), $this->comparable($head));

        $range = $this->site()->request('GET', $shellPath, ['Range' => 'bytes=10-19']);
        $this->assertSame(206, $range->status);
        $this->assertSame($this->bytes(10, 19), $range->body);
        $this->assertSame('bytes 10-19/' . self::SIZE, $range->header('Content-Range'));
        $this->assertSame($this->comparable($this->site()->request('GET', $path, ['Range' => 'bytes=10-19'])), $this->comparable($range));

        // an unsatisfiable range: the answer says so with no body (the controller's path sends the file's own `Content-Length` with it, which no client can read)
        $past = $this->site()->request('GET', $shellPath, ['Range' => 'bytes=' . (self::SIZE + 1000) . '-' . (self::SIZE + 2000)]);
        $this->assertSame(416, $past->status);
        $this->assertSame('bytes */' . self::SIZE, $past->header('Content-Range'));
        $this->assertSame('', $past->body);
    }

    public function testAStreamedResponseIsAnsweredAsTheControllerAnswersIt(): void
    {
        $shell = $this->site()->request('GET', '/_test/fixture-shell-sub/?case=stream');
        $controller = $this->site()->request('GET', '/_test/fixture-shell/controller/?case=stream');

        $this->assertSame($this->comparable($controller), $this->comparable($shell));
    }

    public function testALargeFileIsStreamedByAShellUnderASmallMemoryLimit(): void
    {
        self::$largeFile = self::$largeFile !== '' ? self::$largeFile : sys_get_temp_dir() . '/idno-shell-flags-large-' . bin2hex(random_bytes(6));
        if (!is_file(self::$largeFile)) {
            $piece = str_repeat('0123456789abcdef', 65536);
            $handle = fopen(self::$largeFile, 'wb');
            for ($i = 0; $i < self::LARGE_MEGABYTES; $i++) {
                fwrite($handle, substr($piece, 0, -4) . sprintf('%04d', $i));
            }
            fclose($handle);
        }
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_TEST_FIXTURE_SHELL' => '1', 'IDNO_TEST_SHELL_FILE' => self::$largeFile], ['memory_limit' => '32M']));
        $size = self::LARGE_MEGABYTES * 1048576;

        $shell = $client->request('GET', '/_test/fixture-shell-sub/?case=file');
        $this->assertSame(200, $shell->status, substr($shell->body, 0, 300));
        $this->assertSame((string) $size, $shell->header('Content-Length'));
        $this->assertSame($size, strlen($shell->body));
        $this->assertSame(hash_file('sha256', self::$largeFile), hash('sha256', $shell->body));

        $from = 5 * 1048576 + 123;
        $to = 50 * 1048576 + 456;
        $range = $client->request('GET', '/_test/fixture-shell-sub/?case=file', ['Range' => 'bytes=' . $from . '-' . $to]);
        $this->assertSame(206, $range->status);
        $this->assertSame($to - $from + 1, strlen($range->body));
        $this->assertSame(hash('sha256', substr((string) file_get_contents(self::$largeFile), $from, $to - $from + 1)), hash('sha256', $range->body));
    }

    public function testTheHomepageShellIsTheHomePageNotTheFeed(): void
    {
        $response = $this->site()->request('GET', '/');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'), 'the default type: the feed action (rss) is not the shell\'s');
        $this->assertStringNotContainsString('<rss', $response->body);
        $this->assertStringContainsString('class="h-feed"', $response->body, 'the home page\'s stream');
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function withoutToken(array $json): array
    {
        unset($json['form_token']);

        return $json;
    }

    public function testAShellOfAPageThatTakesNoFormTokenServesItsPostAsNobody(): void
    {
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');

        $get = $this->site()->request('GET', self::TOKENLESS_SHELL, ['Cookie' => $cookie]);
        $this->assertIsString($get->json()['user'], 'a GET through the shell is the member\'s, as the controller\'s is');

        $shell = $this->site()->request('POST', self::TOKENLESS_SHELL, ['Cookie' => $cookie]);
        $controller = $this->site()->request('POST', self::TOKENLESS_CONTROLLER, ['Cookie' => $cookie]);

        $this->assertSame(200, $controller->status, $controller->body);
        $this->assertSame(['user' => null, 'api' => true, 'method' => 'POST', 'granted' => false], self::withoutToken($controller->json()));
        $this->assertSame(200, $shell->status, 'no form token is asked of the page: ' . $shell->body);
        $this->assertSame(self::withoutToken($controller->json()), self::withoutToken($shell->json()), 'and the request is nobody\'s, whatever cookie it carries');
    }

    public function testAShellOfAPageThatNeedsTheTokenStillAsksForIt(): void
    {
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');

        $response = $this->site()->request('POST', '/_test/fixture-shell/', ['Cookie' => $cookie]);

        $this->assertNotSame(200, $response->status, 'a POST with no token is never served as a page');
    }
}
