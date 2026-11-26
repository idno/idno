<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What a member's profile page (`/profile/<handle>`, its friendly content paths and `/actor/<handle>` where ActivityPub does not take it) answers,
 * recorded from the legacy page over HTTP: the status, headers and body of each profile to each kind of viewer in each form, of each friendly path, of a
 * page of the stream, of a handle that is nobody's, of each spelling and method, of a conditional request, and of what the ActivityPub plugin
 * registered for the same addresses. The answers are `Tests/Characterisation/fixtures/answers/profile/`; a page that replaces the legacy one is held to
 * them. Each case is a literal, in the tables below or in the call (`Tests\Support\RecordingOrphans` reads them).
 *
 * The profiles are Ada's (an administrator, with the golden site's posts of every type and a second page of notes), Bob's (a member with a post of every
 * state a viewer's rights differ by) and Carol's (a member who owns nothing). The viewers are `visitor`, `member` (Carol), `owner` (Bob) and `admin` (Ada).
 */
final class ProfileAnswersTest extends ProfileSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'profile';

    /**
     * The cases the page that replaced the legacy one answers differently ON PURPOSE, with why: the recordings are the legacy page's and are kept as they
     * were, and the test of each asserts the answer of the page that serves it now.
     */
    private const CHANGED = [
        'ada-xhr' => 'an XHR GET gets the headers of the same request that is not XHR',
        'ada-xhr-head' => 'an XHR HEAD gets the headers of the same request that is not XHR',
        'ada-xhr-missing' => 'an XHR GET of a profile that is not there gets the headers of the same request that is not XHR',
        'ada-xhr-json' => 'an XHR GET in the JSON type gets the headers of the same request that is not XHR',
        'ada-xhr-owner' => 'an XHR GET by the account\'s own member gets the headers of the same request that is not XHR',
    ];

    /**
     * Each profile for each viewer in the forms that differ most: case => [the handle, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const VIEWER_CASES = [
        'ada-visitor-html' => ['ada', 'visitor', 'html'],
        'ada-visitor-json' => ['ada', 'visitor', 'json'],
        'ada-visitor-markdown' => ['ada', 'visitor', 'markdown'],
        'ada-member-html' => ['ada', 'member', 'html'],
        'ada-member-json' => ['ada', 'member', 'json'],
        'ada-member-markdown' => ['ada', 'member', 'markdown'],
        'ada-owner-html' => ['ada', 'owner', 'html'],
        'ada-owner-json' => ['ada', 'owner', 'json'],
        'ada-owner-markdown' => ['ada', 'owner', 'markdown'],
        'ada-admin-html' => ['ada', 'admin', 'html'],
        'ada-admin-json' => ['ada', 'admin', 'json'],
        'ada-admin-markdown' => ['ada', 'admin', 'markdown'],
        'bob-visitor-html' => ['bob', 'visitor', 'html'],
        'bob-visitor-json' => ['bob', 'visitor', 'json'],
        'bob-visitor-markdown' => ['bob', 'visitor', 'markdown'],
        'bob-member-html' => ['bob', 'member', 'html'],
        'bob-member-json' => ['bob', 'member', 'json'],
        'bob-member-markdown' => ['bob', 'member', 'markdown'],
        'bob-owner-html' => ['bob', 'owner', 'html'],
        'bob-owner-json' => ['bob', 'owner', 'json'],
        'bob-owner-markdown' => ['bob', 'owner', 'markdown'],
        'bob-admin-html' => ['bob', 'admin', 'html'],
        'bob-admin-json' => ['bob', 'admin', 'json'],
        'bob-admin-markdown' => ['bob', 'admin', 'markdown'],
        'carol-visitor-html' => ['carol', 'visitor', 'html'],
        'carol-visitor-json' => ['carol', 'visitor', 'json'],
        'carol-visitor-markdown' => ['carol', 'visitor', 'markdown'],
        'carol-member-html' => ['carol', 'member', 'html'],
        'carol-member-json' => ['carol', 'member', 'json'],
        'carol-admin-html' => ['carol', 'admin', 'html'],
    ];

    /**
     * The other forms a profile is asked for: case => [the handle, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const FORM_CASES = [
        'ada-visitor-jsonp' => ['ada', 'visitor', 'jsonp'],
        'ada-visitor-rss' => ['ada', 'visitor', 'rss'],
        'ada-visitor-kml' => ['ada', 'visitor', 'kml'],
        'ada-visitor-query' => ['ada', 'visitor', 'query'],
        'ada-visitor-accept-markdown' => ['ada', 'visitor', 'accept-markdown'],
        'ada-visitor-accept-activitypub' => ['ada', 'visitor', 'accept-activitypub'],
        'ada-visitor-accept-json' => ['ada', 'visitor', 'accept-json'],
        'ada-admin-accept-activitypub' => ['ada', 'admin', 'accept-activitypub'],
        'ada-admin-rss' => ['ada', 'admin', 'rss'],
        'bob-owner-rss' => ['bob', 'owner', 'rss'],
        'bob-visitor-rss' => ['bob', 'visitor', 'rss'],
        'bob-owner-jsonp' => ['bob', 'owner', 'jsonp'],
        'bob-owner-kml' => ['bob', 'owner', 'kml'],
        'bob-visitor-accept-activitypub' => ['bob', 'visitor', 'accept-activitypub'],
        'carol-visitor-rss' => ['carol', 'visitor', 'rss'],
        'carol-member-accept-activitypub' => ['carol', 'member', 'accept-activitypub'],
    ];

    /**
     * The friendly content paths (the part after the handle): case => [the handle, the path after it, the viewer, the form].
     *
     * @var array<string, array{string, string, string, string}>
     */
    private const FRIENDLY_CASES = [
        'friendly-ada-notes' => ['ada', 'notes', 'visitor', 'html'],
        'friendly-ada-notes-slash' => ['ada', 'notes/', 'visitor', 'html'],
        'friendly-ada-articles' => ['ada', 'articles', 'visitor', 'html'],
        'friendly-ada-photos' => ['ada', 'photos', 'visitor', 'html'],
        'friendly-ada-all' => ['ada', 'all', 'visitor', 'html'],
        'friendly-ada-all-json' => ['ada', 'all', 'visitor', 'json'],
        'friendly-ada-all-markdown' => ['ada', 'all', 'visitor', 'markdown'],
        'friendly-ada-all-admin' => ['ada', 'all', 'admin', 'html'],
        'friendly-ada-articles-notes' => ['ada', 'articles/notes', 'visitor', 'html'],
        'friendly-ada-notes-all' => ['ada', 'notes/all', 'visitor', 'html'],
        'friendly-ada-nonsense' => ['ada', 'nonsense', 'visitor', 'html'],
        'friendly-ada-nonsense-json' => ['ada', 'nonsense', 'visitor', 'json'],
        'friendly-ada-upper-all' => ['ada', 'ALL', 'visitor', 'html'],
        'friendly-ada-bookmarks' => ['ada', 'bookmarks', 'visitor', 'html'],
        'friendly-ada-events' => ['ada', 'events', 'visitor', 'html'],
        'friendly-ada-places' => ['ada', 'places', 'visitor', 'html'],
        'friendly-ada-digits' => ['ada', '123', 'visitor', 'html'],
        'friendly-bob-all-visitor' => ['bob', 'all', 'visitor', 'html'],
        'friendly-bob-all-member' => ['bob', 'all', 'member', 'html'],
        'friendly-bob-all-owner' => ['bob', 'all', 'owner', 'html'],
        'friendly-bob-all-owner-json' => ['bob', 'all', 'owner', 'json'],
        'friendly-bob-all-admin' => ['bob', 'all', 'admin', 'html'],
        'friendly-bob-notes-owner' => ['bob', 'notes', 'owner', 'html'],
        'friendly-bob-photos-owner' => ['bob', 'photos', 'owner', 'html'],
        'friendly-bob-photos-member' => ['bob', 'photos', 'member', 'html'],
        'friendly-bob-photos-visitor' => ['bob', 'photos', 'visitor', 'html'],
        'friendly-bob-checkins-owner' => ['bob', 'places', 'owner', 'html'],
        'friendly-carol-all' => ['carol', 'all', 'visitor', 'html'],
    ];

    /**
     * Addresses that name no profile, and the spellings of one: case => [the address, the viewer, the form].
     *
     * @var array<string, array{string, string, string}>
     */
    private const ADDRESS_CASES = [
        'missing-visitor-html' => ['/profile/nobody-has-this-handle', 'visitor', 'html'],
        'missing-visitor-json' => ['/profile/nobody-has-this-handle', 'visitor', 'json'],
        'missing-visitor-markdown' => ['/profile/nobody-has-this-handle', 'visitor', 'markdown'],
        'missing-member-html' => ['/profile/nobody-has-this-handle', 'member', 'html'],
        'missing-admin-html' => ['/profile/nobody-has-this-handle', 'admin', 'html'],
        'missing-admin-json' => ['/profile/nobody-has-this-handle', 'admin', 'json'],
        'missing-friendly-visitor' => ['/profile/nobody-has-this-handle/all', 'visitor', 'html'],
        'missing-md-suffix' => ['/profile/ada.md', 'visitor', 'html'],
        'missing-an-id' => ['/profile/{ada-id}', 'visitor', 'html'],
        'missing-no-handle' => ['/profile/', 'visitor', 'html'],
        'missing-no-handle-bare' => ['/profile', 'visitor', 'html'],
        'spelling-upper-case' => ['/profile/ADA', 'visitor', 'html'],
        'spelling-trailing-slash' => ['/profile/ada/', 'visitor', 'html'],
        'spelling-double-slash-lead' => ['//profile/ada', 'visitor', 'html'],
        'spelling-double-slash-trail' => ['/profile/ada//', 'visitor', 'html'],
        'spelling-friendly-double-slash' => ['/profile/ada//notes', 'visitor', 'html'],
        'spelling-encoded-letter' => ['/profile/%61da', 'visitor', 'html'],
        'spelling-encoded-slash' => ['/profile/ada%2Fnotes', 'visitor', 'html'],
        'spelling-encoded-accent' => ['/profile/%C3%A9', 'visitor', 'html'],
        'spelling-invalid-utf8' => ['/profile/%FF', 'visitor', 'html'],
        'spelling-nul' => ['/profile/%00', 'visitor', 'html'],
        'spelling-space' => ['/profile/ada%20x', 'visitor', 'html'],
        'spelling-dot' => ['/profile/ada.', 'visitor', 'html'],
        'spelling-edit-suffix-is-another-page' => ['/profile/ada/edit', 'admin', 'html'],
        'actor-ada-html' => ['/actor/ada', 'visitor', 'html'],
        'actor-ada-json' => ['/actor/ada', 'visitor', 'json'],
        'actor-ada-admin-html' => ['/actor/ada', 'admin', 'html'],
        'actor-bob-visitor-html' => ['/actor/bob', 'visitor', 'html'],
        'actor-missing' => ['/actor/nobody-has-this-handle', 'visitor', 'html'],
        'actor-slash' => ['/actor/ada/', 'visitor', 'html'],
        'actor-upper' => ['/actor/ADA', 'visitor', 'html'],
        'actor-friendly' => ['/actor/ada/notes', 'visitor', 'html'],
    ];

    /**
     * The pages of the stream: case => [the handle, the query, the viewer].
     *
     * @var array<string, array{string, string, string}>
     */
    private const OFFSET_CASES = [
        'offset-ada-10' => ['ada', 'offset=10', 'visitor'],
        'offset-ada-10-json' => ['ada', 'offset=10&_t=json', 'visitor'],
        'offset-ada-10-markdown' => ['ada', 'offset=10&_t=markdown', 'visitor'],
        'offset-ada-20' => ['ada', 'offset=20', 'visitor'],
        'offset-ada-0' => ['ada', 'offset=0', 'visitor'],
        'offset-ada-far' => ['ada', 'offset=10000', 'visitor'],
        'offset-ada-negative' => ['ada', 'offset=-5', 'visitor'],
        'offset-ada-words' => ['ada', 'offset=abc', 'visitor'],
        'offset-ada-float' => ['ada', 'offset=10.7', 'visitor'],
        'offset-ada-array' => ['ada', 'offset[]=1', 'visitor'],
        'offset-ada-admin-10' => ['ada', 'offset=10', 'admin'],
        'offset-ada-rss' => ['ada', 'offset=10&_t=rss', 'visitor'],
        'offset-bob-1' => ['bob', 'offset=1', 'owner'],
        'offset-bob-1-visitor' => ['bob', 'offset=1', 'visitor'],
        'offset-types-ignored' => ['ada', 'types=photo', 'visitor'],
        'offset-q-ignored' => ['ada', 'q=garden', 'visitor'],
    ];

    /** The file the statements of a request are written to, for the class's second server. */
    private static string $statementLog = '';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            if (self::$statementLog !== '' && is_file(self::$statementLog)) {
                unlink(self::$statementLog);
            }
            self::$statementLog = '';
            parent::tearDownAfterClass();
        }
    }

    protected function seedSharedSite(): void
    {
        $this->seedProfiles();
    }

    /** The address a table names: `{ada-id}` is the id of a seeded account. */
    private function address(string $template): string
    {
        $ids = $this->seedProfiles()['ids'];

        return (string) preg_replace_callback('/\{([a-z-]+)\}/', static fn (array $m): string => (string) ($ids[str_replace('-id', '', $m[1])] ?? $m[0]), $template);
    }

    public function testEachProfileForEachViewer(): void
    {
        foreach (self::VIEWER_CASES as $case => [$handle, $viewer, $form]) {
            [$path, $headers] = self::form('/profile/' . $handle, $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testTheOtherFormsOfAProfile(): void
    {
        foreach (self::FORM_CASES as $case => [$handle, $viewer, $form]) {
            [$path, $headers] = self::form('/profile/' . $handle, $form);
            $answer = $this->record($case, 'GET', $path, $headers, $viewer);
            if ($form === 'accept-activitypub') {
                $this->assertTheAnswerIsTheActor($answer, $case);
            }
        }
        $this->addToAssertionCount(1);
    }

    /** What a profile's address answers when it is asked for as ActivityPub (`ActivityPubTest` has the whole document): the account's actor. */
    private function assertTheAnswerIsTheActor(SiteResponse $answer, string $case): void
    {
        $this->assertSame(200, $answer->status, $case);
        $this->assertSame('application/activity+json', trim(explode(';', (string) $answer->header('Content-Type'))[0]), $case);
        $this->assertStringContainsStringIgnoringCase('accept', (string) $answer->header('Vary'), $case);
        $this->assertSame('Person', $answer->json()['type'] ?? null, $case);
    }

    public function testEachFriendlyContentPath(): void
    {
        foreach (self::FRIENDLY_CASES as $case => [$handle, $friendly, $viewer, $form]) {
            [$path, $headers] = self::form('/profile/' . $handle . '/' . $friendly, $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testAddressesThatNameNoProfileAndEachSpelling(): void
    {
        foreach (self::ADDRESS_CASES as $case => [$template, $viewer, $form]) {
            [$path, $headers] = self::form($this->address($template), $form);
            $this->record($case, 'GET', $path, $headers, $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testEachPageOfTheStream(): void
    {
        foreach (self::OFFSET_CASES as $case => [$handle, $query, $viewer]) {
            $this->record($case, 'GET', '/profile/' . $handle . '?' . $query, [], $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testEachMethodAndKindOfRequest(): void
    {
        $this->record('ada-head', 'HEAD', '/profile/ada');
        $this->record('ada-head-owner', 'HEAD', '/profile/bob', [], 'owner');
        $this->record('ada-head-json', 'HEAD', '/profile/ada?_t=json');
        $this->record('ada-head-friendly', 'HEAD', '/profile/ada/all');
        $this->record('ada-head-missing', 'HEAD', '/profile/nobody-has-this-handle');
        $this->record('ada-options', 'OPTIONS', '/profile/ada');
        $this->record('ada-options-missing', 'OPTIONS', '/profile/nobody-has-this-handle');
        $this->record('ada-options-friendly', 'OPTIONS', '/profile/ada/all');
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->record('ada-xhr', 'GET', '/profile/ada', $xhr);
        $this->record('ada-xhr-head', 'HEAD', '/profile/ada', $xhr);
        // (the legacy router's lines for an XHR request hold the time of the request as `Last-Modified`)
        $this->record('ada-xhr-missing', 'GET', '/profile/nobody-has-this-handle', $xhr, 'visitor', null, self::withoutLastModified(...));
        $this->record('ada-xhr-json', 'GET', '/profile/ada?_t=json', $xhr);
        $this->record('ada-xhr-owner', 'GET', '/profile/bob', $xhr, 'owner');
        // a signed API request acts as its member
        $this->record('ada-signed-api', 'GET', '/profile/ada', self::signedHeadersFor('bob', $this->bobsKey(), '/profile/ada'));
        $this->record('ada-signed-api-json', 'GET', '/profile/ada?_t=json', self::signedHeadersFor('bob', $this->bobsKey(), '/profile/ada?_t=json'));
        $this->record('bob-signed-api-own', 'GET', '/profile/bob', self::signedHeadersFor('bob', $this->bobsKey(), '/profile/bob'));
        $this->record('bob-signed-api-own-json', 'GET', '/profile/bob?_t=json', self::signedHeadersFor('bob', $this->bobsKey(), '/profile/bob?_t=json'));
        $this->record('ada-malformed-host', 'GET', '/profile/ada', ['Host' => 'bad host!']);
        $this->record('ada-malformed-host-friendly', 'GET', '/profile/ada/all', ['Host' => 'bad host!']);
        $this->record('ada-malformed-host-json', 'GET', '/profile/ada?_t=json', ['Host' => 'bad host!']);
        // a template type that does not exist, and one that is not a type
        $this->record('ada-unknown-type', 'GET', '/profile/ada?_t=nonesuch');
        $this->record('ada-array-type', 'GET', '/profile/ada?_t[]=json');
        $this->record('ada-classic-type', 'GET', '/profile/ada?_t=classic');
        $this->record('ada-mail-type', 'GET', '/profile/ada?_t=email');
        // other methods the router gives the page (the page defines no handler for them: the form token comes first)
        $this->record('ada-put-visitor', 'PUT', '/profile/ada', [], 'visitor', ['name' => 'x']);
        $this->record('ada-patch-visitor', 'PATCH', '/profile/ada', [], 'visitor', ['name' => 'x']);
        $this->addToAssertionCount(1);
    }

    public function testAConditionalRequestIsNeverAnsweredNotModified(): void
    {
        $changed = (int) strtotime(self::UPDATED . ' UTC');
        $date = static fn (int $time): string => gmdate('D, d M Y H:i:s', $time) . ' GMT';
        $long = ['If-Modified-Since' => $date($changed + 86400 * 365)];
        // the profile never answers a conditional request with a 304: it sends the page, as it has always done
        $answer = $this->record('ims-ada-after', 'GET', '/profile/ada', $long);
        $this->assertSame(200, $answer->status);
        $answer = $this->record('ims-ada-after-head', 'HEAD', '/profile/ada', $long);
        $this->assertSame(200, $answer->status);
        $answer = $this->record('ims-ada-before', 'GET', '/profile/ada', ['If-Modified-Since' => $date($changed - 86400 * 365)]);
        $this->assertSame(200, $answer->status);
        $answer = $this->record('ims-ada-garbage', 'GET', '/profile/ada', ['If-Modified-Since' => 'not a date at all']);
        $this->assertSame(200, $answer->status);
        $answer = $this->record('ims-ada-none-match', 'GET', '/profile/ada', $long + ['If-None-Match' => '"abc"']);
        $this->assertSame(200, $answer->status);
        $this->addToAssertionCount(1);
    }

    /**
     * An XHR GET or HEAD of a profile gets the headers of the same request that is not XHR: the legacy router's own lines for it (a JSON type, a date in
     * 1997, two `Cache-Control` lines and the time of the request as `Last-Modified`) are no longer sent. Its status and body are what they were: the
     * legacy page's recording (the forms an XHR response does not carry are not in it). The recordings of the cases are the legacy page's and are kept.
     */
    public function testAnXhrRequestGetsTheHeadersOfTheSameRequestThatIsNotXhr(): void
    {
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $cases = [
            'ada-xhr' => ['GET', '/profile/ada', 'visitor'],
            'ada-xhr-head' => ['HEAD', '/profile/ada', 'visitor'],
            'ada-xhr-missing' => ['GET', '/profile/nobody-has-this-handle', 'visitor'],
            'ada-xhr-json' => ['GET', '/profile/ada?_t=json', 'visitor'],
            'ada-xhr-owner' => ['GET', '/profile/bob', 'owner'],
        ];
        foreach ($cases as $case => [$method, $path, $viewer]) {
            $answer = $this->site()->request($method, $path, $this->asViewer($viewer, $xhr));
            $plain = $this->site()->request($method, $path, $this->asViewer($viewer));
            [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutLastModified($answer), $this->baseUrl()), 2);
            [$plainHeaders] = explode("\n\n", self::normalise(self::withoutLastModified($plain), $this->baseUrl()), 2);
            $this->assertSame($plainHeaders, $answeredHeaders, $case . ': the headers of the same request that is not XHR');
            $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt');
            [, $recordedBody] = explode("\n\n", $recorded, 2);
            $this->assertSame($recordedBody, $answeredBody, $case . ': the body is what it was');
            $this->assertCount(1, $answer->lines('cache-control'), $case . ': one Cache-Control line');
        }
    }

    public function testEveryCaseThatDiffersOnPurposeIsARecordedCase(): void
    {
        foreach (self::CHANGED as $case => $why) {
            $this->assertNotSame('', $why, $case);
            $this->assertFileExists(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt', $case);
        }
    }

    /**
     * The ActivityPub plugin registers the actor's address for its own page (an identical key, which replaces core's handler in place), so with the plugin
     * enabled `/actor/<handle>` is the plugin's actor page in every form; the profile's own address is unaffected.
     */
    public function testTheActorAddressIsThePluginsPageWhileThePluginIsEnabled(): void
    {
        $actor = $this->record('actor-ada-activitypub', 'GET', '/actor/ada', ['Accept' => 'application/activity+json']);
        $this->assertSame(200, $actor->status);
        $this->assertSame('Person', $actor->json()['type'] ?? null);
        $this->assertSame($this->baseUrl() . 'actor/ada', $actor->json()['id'] ?? null);
        $this->assertSame($this->baseUrl() . 'profile/ada', $actor->json()['url'] ?? null);
        $this->record('actor-ada-inbox-get', 'GET', '/actor/ada/inbox', ['Accept' => 'application/activity+json']);
        $this->record('actor-ada-outbox', 'GET', '/actor/ada/outbox', ['Accept' => 'application/activity+json']);
        $this->record('actor-ada-followers', 'GET', '/actor/ada/followers', ['Accept' => 'application/activity+json']);
        $this->addToAssertionCount(1);
    }

    /**
     * The number of database statements a visitor's GET of Ada's profile sends (a second request, after one that has warmed what a process keeps),
     * recorded for each database family: the page's cost, which the page that replaces the legacy one must not raise.
     */
    public function testTheStatementsOfAVisitorsGetOfAProfile(): void
    {
        self::$statementLog = self::$statementLog !== '' ? self::$statementLog : sys_get_temp_dir() . '/idno-profile-statements-' . bin2hex(random_bytes(6));
        touch(self::$statementLog);
        $server = $this->startExtraServer(['IDNO_TEST_STATEMENT_LOG' => self::$statementLog]);
        $client = new HttpSiteClient($server);
        $family = \Idno\Storage\ConnectionParameters::driverFamily(\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all());

        $this->assertSame(200, $client->request('GET', '/profile/ada')->status, 'a first request, which is not counted');
        foreach (range(1, self::passes()) as $_) {
            file_put_contents(self::$statementLog, '');
            $this->assertSame(200, $client->request('GET', '/profile/ada')->status);
            $count = count(array_filter(explode("\n", (string) file_get_contents(self::$statementLog))));
            $this->assertGreaterThan(0, $count, 'the statements were logged');
            $this->assertRecordedAnswer(self::GROUP, $family === 'sqlite' ? 'statements-sqlite' : 'statements-mysql', new SiteResponse(200, ['Content-Type' => 'text/plain'], 'statements: ' . $count . "\n"));
        }
    }
}
