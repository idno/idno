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

    protected function setUp(): void
    {
        self::$marker = self::$marker !== '' ? self::$marker : sys_get_temp_dir() . '/idno-shell-flags-' . bin2hex(random_bytes(6));
        parent::setUp();
        if (is_file(self::$marker)) {
            unlink(self::$marker);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_FIXTURE_SHELL' => '1', 'IDNO_TEST_STREAM_MARKER' => self::$marker, 'IDNO_TEST_CLAIM_ROUTES' => 'homepage-hijack'];
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
        parent::tearDownAfterClass();
    }

    /**
     * An answer as it is compared with the other path's: the page's own address (the shell's and the controller's differ) is `{page}`, and the
     * body's class (it names the class that served the page: the shell's subclass or the shell) is left out.
     */
    private function comparable(SiteResponse $response): string
    {
        $text = self::normalise($response, $this->baseUrl());
        $text = (string) preg_replace('~/(?:_test/fixture-shell(?:/controller|-sub|-tokenless-controller|-tokenless)?|account/_test-fixture-shell(?:-controller)?)(?=[/.?"<>\s]|$)~', '{page}', $text);

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

    public function testAShellServedPageCarriesTheSettingsAndAccountFlags(): void
    {
        $signed = $this->site()->request('GET', self::ACCOUNT_SHELL, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), self::ACCOUNT_SHELL));
        $this->assertSame(403, $signed->status, 'a signed API request is refused on an account page');

        $shell = $this->site()->request('GET', self::ACCOUNT_SHELL . '?_t=markdown');
        $controller = $this->site()->request('GET', self::ACCOUNT_CONTROLLER . '?_t=markdown');

        $this->assertSame(200, $controller->status, $controller->body);
        $this->assertSame(200, $shell->status, $shell->body);
        $this->assertStringContainsString('no Markdown form', $shell->body, 'a settings page is a title and one line');
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

    public function testTheHomepageShellIsTheHomePageNotTheFeed(): void
    {
        $response = $this->site()->request('GET', '/');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'), 'the default type: the feed action (rss) is not the shell\'s');
        $this->assertStringNotContainsString('<rss', $response->body);
        $this->assertStringContainsString('class="h-feed"', $response->body, 'the home page\'s stream');
    }

    public function testAShellOfAPageThatTakesNoFormTokenServesItsPostAsNobody(): void
    {
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');

        $get = $this->site()->request('GET', self::TOKENLESS_SHELL, ['Cookie' => $cookie]);
        $this->assertIsString($get->json()['user'], 'a GET through the shell is the member\'s, as the controller\'s is');

        $shell = $this->site()->request('POST', self::TOKENLESS_SHELL, ['Cookie' => $cookie]);
        $controller = $this->site()->request('POST', self::TOKENLESS_CONTROLLER, ['Cookie' => $cookie]);

        $this->assertSame(200, $controller->status, $controller->body);
        $this->assertSame(['user' => null, 'api' => true, 'method' => 'POST'], $controller->json());
        $this->assertSame(200, $shell->status, 'no form token is asked of the page: ' . $shell->body);
        $this->assertSame($controller->json(), $shell->json(), 'and the request is nobody\'s, whatever cookie it carries');
    }

    public function testAShellOfAPageThatNeedsTheTokenStillAsksForIt(): void
    {
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');

        $response = $this->site()->request('POST', '/_test/fixture-shell/', ['Cookie' => $cookie]);

        $this->assertNotSame(200, $response->status, 'a POST with no token is never served as a page');
    }
}
