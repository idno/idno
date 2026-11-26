<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * A site page that says what authenticates its state-changing requests in place of the form token
 * (`#[SitePage(formTokenReplacedBy: ...)]`, here `Tests\Fixtures\SitePageFixtureController::tokenless`) takes them with no token and
 * serves them as nobody: from `page/ready` on, in the legacy session and in Symfony Security, whatever cookie or signature the request
 * carries, and without touching the browser's session.
 */
final class FormTokenExemptionTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const TOKENLESS = '/_test/site-page/tokenless';

    protected const SHARED_SITE = true;

    private static string $recordFile = '';

    /** The site's session files (one directory for the class's shared site, which asks for its settings before it has a directory of its own). */
    private static string $sessions = '';

    protected function setUp(): void
    {
        self::$recordFile = self::$recordFile !== '' ? self::$recordFile : sys_get_temp_dir() . '/idno-form-token-exemption-' . bin2hex(random_bytes(6));
        if (self::$sessions === '') {
            self::$sessions = sys_get_temp_dir() . '/idno-form-token-exemption-sessions-' . bin2hex(random_bytes(6));
            mkdir(self::$sessions, 0700);
        }
        parent::setUp();
    }

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->apiKeyOf('ada');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$recordFile !== '' && is_file(self::$recordFile)) {
            unlink(self::$recordFile);
        }
        self::$recordFile = '';
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/*') ?: []);
            rmdir(self::$sessions);
        }
        self::$sessions = '';
        parent::tearDownAfterClass();
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_RECORD_PAGES' => self::$recordFile, 'IDNO_TEST_FIXTURE_SHELL' => '1'];
    }

    protected function siteIni(): array
    {
        // the site's session files are in a directory of its own: the system's shared one is written by every process of the machine
        return ['session.save_path' => self::$sessions];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: SiteResponse, 1: list<array<string, mixed>>} the answer and the page events it recorded, in order
     */
    private function recorded(string $method, string $path, array $headers = [], string|array|null $body = null, ?SiteClient $client = null): array
    {
        file_put_contents(self::$recordFile, '');
        $response = ($client ?? $this->site())->request($method, $path, $headers, $body);
        $events = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents(self::$recordFile))))
        );

        return [$response, $events];
    }

    /**
     * @return array<string, mixed> the JSON answer of the fixture page less the form token, which the session test reads
     */
    private static function answer(SiteResponse $response): array
    {
        $json = $response->json();
        unset($json['form_token']);
        ksort($json);

        return $json;
    }

    private function administratorCookie(): string
    {
        return $this->loginCookie('ada', 'correct-horse-battery-9');
    }

    public function testAPostWithoutATokenReachesAnExemptPage(): void
    {
        [$response, $events] = $this->recorded('POST', self::TOKENLESS);

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(['api' => true, 'granted' => false, 'method' => 'POST', 'user' => null], self::answer($response));
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($events, 'event'), 'the page is begun, entered and told of the POST');
    }

    public function testAnExemptRequestNeverActsAsTheBrowsersUser(): void
    {
        $cookie = $this->administratorCookie();

        // the same address, the same cookie: a GET is the administrator's, a POST is nobody's
        [$get, $getEvents] = $this->recorded('GET', self::TOKENLESS, ['Cookie' => $cookie]);
        $this->assertSame(200, $get->status);
        $uuid = $get->json()['user'];
        $this->assertIsString($uuid, 'a GET is the administrator\'s');
        $this->assertTrue($get->json()['granted'], 'Symfony Security knows the administrator on a GET');
        $this->assertFalse($get->json()['api']);
        $this->assertSame([$uuid, $uuid, $uuid], array_column($getEvents, 'user'));

        [$post, $postEvents] = $this->recorded('POST', self::TOKENLESS, ['Cookie' => $cookie]);

        $this->assertSame(200, $post->status, $post->body);
        $this->assertSame(['api' => true, 'granted' => false, 'method' => 'POST', 'user' => null], self::answer($post), 'the controller, the legacy session and Symfony Security see nobody');
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($postEvents, 'event'));
        $this->assertSame([null, null, null], array_column($postEvents, 'user'), 'no page event sees the cookie\'s user');
    }

    public function testASignedRequestToAnExemptPageIsNobodyToo(): void
    {
        $headers = self::signedHeadersFor('ada', $this->apiKeyOf('ada'), self::TOKENLESS);

        [$post, $events] = $this->recorded('POST', self::TOKENLESS, $headers);

        $this->assertSame(200, $post->status, $post->body);
        $this->assertSame(['api' => true, 'granted' => false, 'method' => 'POST', 'user' => null], self::answer($post), 'a page that wants the signer authenticates it itself');
        $this->assertSame([null, null, null], array_column($events, 'user'));
        // (the same signature on a GET is the member, as for every page)
        $this->assertSame(200, $this->site()->request('GET', self::TOKENLESS, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), self::TOKENLESS))->status);
    }

    public function testATemplateSeesNobody(): void
    {
        $cookie = $this->administratorCookie();

        $get = $this->site()->request('GET', self::TOKENLESS . '?case=draw', ['Cookie' => $cookie]);
        $post = $this->site()->request('POST', self::TOKENLESS . '?case=draw', ['Cookie' => $cookie]);

        $this->assertSame(200, $get->status);
        $this->assertStringContainsString('session/logout', $get->body, 'the shell of a GET shows the administrator\'s log out link');
        $this->assertSame(200, $post->status, $post->body);
        $this->assertStringNotContainsString('session/logout', $post->body, 'the shell of the exempt POST (is_logged_in() false) shows none');
        $this->assertStringNotContainsString('admin/', strtolower(preg_replace('~href="[^"]*(?:static|css|js)[^"]*"~', '', $post->body)), 'and no administrator menu');
    }

    /**
     * @return array<string, array{0: string}> the pages that take a request with no form token: the controller's and its shell's
     */
    public static function pages(): array
    {
        return ['the controller' => [self::TOKENLESS], 'the shell' => ['/_test/fixture-shell-tokenless/']];
    }

    /** What each `case` of the fixture answers: the status (null: any below 400) for a POST. */
    private const CASES = ['' => 200, 'draw' => 200, 'message' => 200, 'throw' => 500, 'denied' => 403, 'notfound' => 404, 'redirect' => null];

    private const BROWSERS_MESSAGE = 'A message to be shown once';

    /**
     * @param array<string, string> $headers
     */
    private function leaveAMessageFor(string $cookie): void
    {
        $this->assertSame(302, $this->site()->request('GET', '/_test/site-page/draw-new?case=messages', ['Cookie' => $cookie])->status);
    }

    private static function cookieName(string $cookie): string
    {
        return substr($cookie, 0, (int) strpos($cookie, '='));
    }

    /**
     * @dataProvider pages
     */
    public function testAnAnswerThatIsDrawnNeverReadsOrWritesTheBrowsersSession(string $page): void
    {
        $cookie = $this->administratorCookie();
        $name = self::cookieName($cookie);
        $xhr = ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'];
        $token = $this->site()->request('GET', 'service/security/csrftoken/', $xhr)->json()['token'];

        foreach (self::CASES as $case => $status) {
            $this->leaveAMessageFor($cookie);

            $response = $this->site()->request('POST', $page . '?case=' . $case, ['Cookie' => $cookie]);

            $label = $page . ' case ' . json_encode($case);
            if ($status === null) {
                $this->assertLessThan(400, $response->status, $label . ' ' . $response->body);
            } else {
                $this->assertSame($status, $response->status, $label . ' ' . substr($response->body, 0, 500));
            }
            $this->assertStringNotContainsString(self::BROWSERS_MESSAGE, $response->body, $label . ': the browser\'s waiting message is not taken and shown');
            $this->assertStringNotContainsString($token, $response->body, $label . ': the browser\'s form token is not printed');
            foreach ($response->lines('Set-Cookie') as $line) {
                $this->assertStringStartsNotWith($name . '=', $line, $label . ': no cookie for the browser\'s session');
            }

            // the browser's session is as it was: logged in, the same id, the same form token, and the message is still waiting for it
            $next = $this->site()->request('GET', '/_test/site-page/draw-new?case=draw', ['Cookie' => $cookie]);
            $this->assertSame(200, $next->status, $label);
            $this->assertStringContainsString(self::BROWSERS_MESSAGE, $next->body, $label . ': shown on its next ordinary page');
            $this->assertStringNotContainsString(\Tests\Fixtures\TokenlessCases::MESSAGE, $next->body, $label . ': and the exempt page\'s own message (added while it acted as nobody) is never written to the browser\'s session');
            $this->assertStringContainsString('session/logout', $next->body, $label . ': still logged in');
            foreach ($next->lines('Set-Cookie') as $line) {
                $this->assertStringStartsNotWith($name . '=', $line, $label . ': the same session id');
            }
            $this->assertSame($token, $this->site()->request('GET', 'service/security/csrftoken/', $xhr)->json()['token'], $label . ': the same form token');
        }
    }

    /**
     * @dataProvider pages
     */
    public function testAFormTheExemptPageDrawsCarriesNoTokenOfTheBrowsers(string $page): void
    {
        $cookie = $this->administratorCookie();
        $token = $this->site()->request('GET', 'service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];

        $response = $this->site()->request('POST', $page . '?case=draw', ['Cookie' => $cookie]);

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(1, preg_match('~form token ([0-9a-f]{64})~', $response->body, $found), 'the page prints a token');
        $this->assertNotSame($token, $found[1], 'a token that is the process\'s own, never the browser\'s session\'s');
    }

    /**
     * @dataProvider pages
     */
    public function testASessionTheRequestStartedIsNotLeftBehindWhateverIsDrawn(string $page): void
    {
        $name = self::cookieName($this->administratorCookie());
        $sessions = $this->sessionIds();
        $this->assertNotSame([], $sessions, 'the site writes its sessions here (logging in made one)');

        foreach (self::CASES as $case => $status) {
            $response = $this->site()->request('POST', $page . '?case=' . $case);

            $label = $page . ' case ' . json_encode($case) . ' (' . $response->status . ')';
            $this->assertSame($sessions, $this->sessionIds(), $label . ': no session is left behind by a request with no cookie, and none of the others is removed');
            foreach ($response->lines('Set-Cookie') as $line) {
                $this->assertStringStartsNotWith($name . '=', $line, $label . ': no cookie for a session that does not exist');
            }
        }
    }

    /**
     * The ids of the site's sessions, sorted: the session files of its own directory on SQLite, the rows of `idno_sessions` of its database on
     * MySQL and MariaDB.
     *
     * @return list<string>
     */
    private function sessionIds(): array
    {
        if (ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all()) === 'sqlite') {
            $files = array_map('basename', glob(self::$sessions . '/sess_*') ?: []);
            sort($files);

            return $files;
        }
        $ids = array_map('strval', $this->siteConnection()->fetchFirstColumn('select session_id from idno_sessions'));
        sort($ids);

        return $ids;
    }

    public function testAnExemptPageOnAWalledGardenIsAskedAsNobody(): void
    {
        $cookie = $this->administratorCookie();
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1'], ['session.save_path' => self::$sessions]));

        $get = $client->request('GET', self::TOKENLESS, ['Cookie' => $cookie]);
        $post = $client->request('POST', self::TOKENLESS, ['Cookie' => $cookie]);

        $this->assertSame(200, $get->status, 'the administrator may read the private site');
        $this->assertSame(403, $post->status, 'the exempt POST is nobody, and nobody is refused by a private site that did not make the page public: ' . $post->body);
        $this->assertStringNotContainsString('"user"', $post->body);
    }

    public function testANonExemptPageStillNeedsTheToken(): void
    {
        $path = '/_test/site-page/post';
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);

        [$refused, $events] = $this->recorded('POST', $path, ['Cookie' => $cookie]);

        $this->assertSame(302, $refused->status, 'a POST without its token is sent away, as it was');
        $this->assertSame(['page/ready', 'page/head'], array_column($events, 'event'), 'no page/post');

        $admin = $this->administratorCookie();
        [$member, ] = $this->recorded('POST', $path, ['Cookie' => $admin]);
        $this->assertSame(302, $member->status, 'a logged-in browser with no token is refused as well');

        [$ok, $okEvents] = $this->recorded('POST', $path, ['Cookie' => $cookie], self::formToken($form->body, 'session/login'));
        $this->assertSame(200, $ok->status);
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($okEvents, 'event'));
        $this->assertTrue($ok->json()['site_page'], 'a page without the exemption is begun as it was');
    }

    public function testConstructingAShellDuringAnOrdinaryRequestChangesNothing(): void
    {
        $cookie = $this->administratorCookie();
        $xhr = ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'];
        $token = $this->site()->request('GET', 'service/security/csrftoken/', $xhr)->json()['token'];

        $response = $this->site()->request('POST', '/_test/site-page/post?construct=1', ['Cookie' => $cookie], ['__bTk' => $token]);

        $this->assertSame(200, $response->status, $response->body);
        $this->assertIsString($response->json()['user'], 'the page that only builds the shell is still the member\'s request');
        $this->assertFalse($response->json()['api']);
        $this->assertSame($token, $this->site()->request('GET', 'service/security/csrftoken/', $xhr)->json()['token'], 'and the session is intact');
        $this->assertStringContainsString('session/logout', $this->site()->request('GET', '/_test/site-page/draw-new?case=draw', ['Cookie' => $cookie])->body);
    }

    public function testAGetOfTheExemptShellKeepsTheBrowsersUserAndItsPostIsNobodyAtEveryEvent(): void
    {
        $cookie = $this->administratorCookie();
        $shell = '/_test/fixture-shell-tokenless/';

        [$get, $getEvents] = $this->recorded('GET', $shell, ['Cookie' => $cookie]);
        $this->assertIsString($get->json()['user'], 'a GET is the member\'s');
        $this->assertContains('page/ready', array_column($getEvents, 'event'));
        foreach ($getEvents as $event) {
            $this->assertIsString($event['user'], $event['event']);
        }

        [$post, $events] = $this->recorded('POST', $shell, ['Cookie' => $cookie]);
        $this->assertSame(200, $post->status, $post->body);
        $this->assertNull($post->json()['user']);
        $this->assertContains('page/ready', array_column($events, 'event'), 'the shell\'s page/ready is recorded');
        foreach ($events as $event) {
            $this->assertNull($event['user'], 'nobody at ' . $event['event']);
        }
    }
}
