<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the member's drafts page (`/drafts/`) answers, recorded from the legacy page over HTTP: the status, headers and body of it to a visitor, to members
 * with and without drafts, to an administrator, in each template type, for each page of the drafts, for each method, for a signed request and for a request
 * that says it is an XHR one. The answers are `Tests/Characterisation/fixtures/answers/member/`; the page that replaced the legacy one is held to them.
 * Each case is a literal, in the call. The addresses of the feed reader that was removed (`/following/`, `/stream/` and the pages under `/following/`)
 * are the site's missing page.
 *
 * The viewers are `visitor` (nobody), `member` (Carol, who has no draft), `owner` (Bob, who has twenty-six drafts, so that the drafts have a second page)
 * and `admin` (Ada, who has two drafts).
 */
final class DraftsPageTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'member';

    private const METHOD_WHY = 'a method the page does not take is the legacy router\'s 404 page, as for every page that moved to a controller (the legacy page answered the invalid-token redirect)';

    private const HOST_WHY = 'a request with a malformed Host is served by the legacy router, which has no page for the address now: its 404 page (the legacy page drew the answer for the request)';

    private const XHR_WHY = 'an XHR GET gets the headers of the same request that is not XHR';

    /**
     * The cases the pages that replaced the legacy ones answer differently ON PURPOSE, with why: the recordings are the legacy pages' and are kept as they
     * were, and the test of each asserts the answer of the page that serves it now.
     */
    private const CHANGED = [
        'drafts-owner-xhr' => self::XHR_WHY,
        'drafts-visitor-xhr' => self::XHR_WHY,
        'drafts-post-visitor' => self::METHOD_WHY,
        'drafts-post-owner-with-token' => self::METHOD_WHY,
        'drafts-malformed-host' => self::HOST_WHY,
    ];

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    protected function seedSharedSite(): void
    {
        $this->seedPermalinkContent();
        $seeded = $this->runInSite(<<<'PHP'
        $at = static fn (string $when): int => (int) strtotime($when . ' UTC');
        $id = static fn (int $n): string => '000000000000400080000000' . sprintf('%08x', 0x10000000 + $n);
        $bob = \Idno\Entities\User::getByHandle('bob');
        $ada = \Idno\Entities\User::getByHandle('ada');
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $fix = static function (string $collection, \Idno\Common\Entity $entity) use ($store, $at): void {
            $record = $store->findById($collection, (string) $entity->getID());
            $record['updated'] = $at('2026-03-20 12:00:00');
            $store->save($collection, $record);
        };

        $draft = static function (int $n, $owner, string $text) use ($id, $at, $fix): void {
            $entity = new \IdnoPlugins\Status\Status();
            $entity->_id = $id($n);
            $entity->body = $text;
            $entity->setOwner($owner);
            $entity->setAccess('PUBLIC');
            $entity->setPublishStatus('draft');
            $entity->created = $at('2026-04-01 10:00:00') + $n * 3600;
            $entity->setSlugResilient($text);
            if (!$entity->save(true)) {
                throw new \RuntimeException('draft not saved');
            }
            $fix('entities', $entity);
        };
        // twenty-five more drafts of Bob's, besides the one the permalink site holds: twenty-six, a second page of one
        for ($n = 1; $n <= 25; $n++) {
            $draft($n, $bob, 'Bob\'s draft number ' . $n . ', not yet published.');
        }
        $draft(100, $ada, 'Ada\'s second draft, not yet published.');

        return true;
        PHP);
        $this->assertTrue($seeded);
    }

    /** The answer without its `Last-Modified`: the legacy router's lines for an XHR request hold the time of the request. */
    private static function withoutLastModified(SiteResponse $r): SiteResponse
    {
        return new SiteResponse(
            $r->status,
            array_filter($r->headers, static fn ($name): bool => strcasecmp((string) $name, 'last-modified') !== 0, ARRAY_FILTER_USE_KEY),
            $r->body,
            array_values(array_filter($r->lines, static fn (string $line): bool => !str_starts_with(strtolower($line), 'last-modified:')))
        );
    }

    public function testTheDraftsPageForEachViewer(): void
    {
        $this->record('drafts-visitor', 'GET', '/drafts/');
        $this->record('drafts-visitor-json', 'GET', '/drafts/?_t=json');
        $this->record('drafts-visitor-markdown', 'GET', '/drafts/?_t=markdown');
        $this->record('drafts-member', 'GET', '/drafts/', [], 'member');
        $this->record('drafts-member-json', 'GET', '/drafts/?_t=json', [], 'member');
        $this->record('drafts-owner', 'GET', '/drafts/', [], 'owner');
        $this->record('drafts-owner-json', 'GET', '/drafts/?_t=json', [], 'owner');
        $this->record('drafts-owner-markdown', 'GET', '/drafts/?_t=markdown', [], 'owner');
        $this->record('drafts-owner-rss', 'GET', '/drafts/?_t=rss', [], 'owner');
        $this->record('drafts-admin', 'GET', '/drafts/', [], 'admin');
        $this->record('drafts-admin-json', 'GET', '/drafts/?_t=json', [], 'admin');
        $this->addToAssertionCount(1);
    }

    public function testEachPageOfTheDrafts(): void
    {
        $this->record('drafts-owner-offset-25', 'GET', '/drafts/?offset=25', [], 'owner');
        $this->record('drafts-owner-offset-25-json', 'GET', '/drafts/?offset=25&_t=json', [], 'owner');
        $this->record('drafts-owner-offset-0', 'GET', '/drafts/?offset=0', [], 'owner');
        $this->record('drafts-owner-offset-5', 'GET', '/drafts/?offset=5', [], 'owner');
        $this->record('drafts-owner-offset-far', 'GET', '/drafts/?offset=10000', [], 'owner');
        $this->record('drafts-owner-offset-negative', 'GET', '/drafts/?offset=-5', [], 'owner');
        $this->record('drafts-owner-offset-words', 'GET', '/drafts/?offset=abc', [], 'owner');
        $this->record('drafts-owner-offset-float', 'GET', '/drafts/?offset=10.7', [], 'owner');
        $this->record('drafts-owner-offset-array', 'GET', '/drafts/?offset[]=1', [], 'owner');
        $this->record('drafts-owner-owner-ignored', 'GET', '/drafts/?owner=' . rawurlencode('https://fixture.example/view/00000000-0000-4000-8000-000000000001'), [], 'owner');
        $this->record('drafts-visitor-offset-25', 'GET', '/drafts/?offset=25');
        $this->record('drafts-admin-offset-1', 'GET', '/drafts/?offset=1', [], 'admin');
        $this->addToAssertionCount(1);
    }

    public function testTheDraftsPageForEachMethodAndKindOfRequest(): void
    {
        $this->record('drafts-visitor-head', 'HEAD', '/drafts/');
        $this->record('drafts-owner-head', 'HEAD', '/drafts/', [], 'owner');
        $this->record('drafts-visitor-options', 'OPTIONS', '/drafts/');
        $this->record('drafts-owner-options', 'OPTIONS', '/drafts/', [], 'owner');
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->record('drafts-owner-xhr', 'GET', '/drafts/', $xhr, 'owner', null, self::withoutLastModified(...));
        $this->record('drafts-visitor-xhr', 'GET', '/drafts/', $xhr, 'visitor', null, self::withoutLastModified(...));
        // a signed API request acts as its member (the page asks for a member, which it is)
        $this->record('drafts-signed-api', 'GET', '/drafts/', self::signedHeadersFor('bob', $this->bobsKey(), '/drafts/'));
        $this->record('drafts-signed-api-json', 'GET', '/drafts/?_t=json', self::signedHeadersFor('bob', $this->bobsKey(), '/drafts/?_t=json'));
        $this->record('drafts-malformed-host', 'GET', '/drafts/', ['Host' => 'bad host!']);
        $this->record('drafts-no-slash', 'GET', '/drafts', [], 'owner');
        $this->record('drafts-double-slash', 'GET', '//drafts//', [], 'owner');
        $this->record('drafts-upper-case', 'GET', '/DRAFTS/', [], 'owner');
        // the other methods the router gives the page: the form token comes first, and the page defines no handler for them
        $this->record('drafts-post-visitor', 'POST', '/drafts/', [], 'visitor', ['a' => 'b']);
        $this->record('drafts-post-owner-with-token', 'POST', '/drafts/', [], 'owner', ['a' => 'b', '__bTk' => $this->tokenOf('owner')]);
        $this->addToAssertionCount(1);
    }

    /**
     * An XHR GET gets the headers of the same request that is not XHR: the legacy router's own lines for it (a JSON type, a date in 1997, two
     * `Cache-Control` lines and the time of the request as `Last-Modified`) are no longer sent. Its status and body are what they were.
     */
    public function testAnXhrRequestGetsTheHeadersOfTheSameRequestThatIsNotXhr(): void
    {
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $cases = [
            'drafts-owner-xhr' => ['/drafts/', 'owner'],
            'drafts-visitor-xhr' => ['/drafts/', 'visitor'],
        ];
        foreach ($cases as $case => [$path, $viewer]) {
            $answer = $this->site()->request('GET', $path, $this->asViewer($viewer, $xhr));
            $plain = $this->site()->request('GET', $path, $this->asViewer($viewer));
            [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutLastModified($answer), $this->baseUrl()), 2);
            [$plainHeaders] = explode("\n\n", self::normalise(self::withoutLastModified($plain), $this->baseUrl()), 2);
            $this->assertSame($plainHeaders, $answeredHeaders, $case . ': the headers of the same request that is not XHR');
            $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt');
            [, $recordedBody] = explode("\n\n", $recorded, 2);
            $this->assertSame($recordedBody, $answeredBody, $case . ': the body is what it was');
            $this->assertCount(1, $answer->lines('cache-control'), $case . ': one Cache-Control line');
        }
    }

    /** A method the drafts page does not take, and a request with a malformed Host, reach the legacy router, which has no page for the address: its 404 page. */
    public function testOtherMethodsAndAMalformedHostAreTheRoutersNotFoundPage(): void
    {
        $answers = [
            'drafts-post-visitor' => $this->site()->request('POST', '/drafts/', [], ['a' => 'b']),
            'drafts-post-owner-with-token' => $this->site()->request('POST', '/drafts/', $this->asViewer('owner'), ['a' => 'b', '__bTk' => $this->tokenOf('owner')]),
            'drafts-malformed-host' => $this->site()->request('GET', '/drafts/', ['Host' => 'bad host!']),
        ];
        foreach ($answers as $case => $answer) {
            $this->assertSame(404, $answer->status, $case);
            $this->assertStringContainsString("This page can't be found", html_entity_decode($answer->body, ENT_QUOTES), $case);
            $this->assertNull($answer->header('Location'), $case);
        }
    }

    /**
     * The addresses of the feed reader that was removed (the following list, the pages that followed a feed, the stream of the items of the feeds) are
     * the site's missing page for every viewer and method: the pages that served them are gone, and no placeholder holds a place for them.
     */
    public function testTheAddressesOfTheRemovedFeedReaderAreTheNotFoundPage(): void
    {
        $paths = ['/following/', '/following', '/following/add/', '/following/confirm/?feed=https%3A%2F%2Ffeed.example%2F', '/following/refresh/', '/stream/', '/stream'];
        foreach ($paths as $path) {
            foreach (['visitor', 'member', 'owner', 'admin'] as $viewer) {
                $answer = $this->site()->request('GET', $path, $this->asViewer($viewer));
                $this->assertSame(404, $answer->status, $viewer . ' ' . $path);
                $this->assertStringContainsString("This page can't be found", html_entity_decode($answer->body, ENT_QUOTES), $viewer . ' ' . $path);
            }
            $this->assertSame(404, $this->site()->request('HEAD', $path)->status, 'HEAD ' . $path);
            $this->assertSame(404, $this->site()->request('GET', $path . (str_contains($path, '?') ? '&' : '?') . '_t=json', $this->asViewer('owner'))->status, 'json ' . $path);
        }
        foreach (['/following/', '/following/add/', '/following/confirm/', '/following/refresh/'] as $path) {
            $answer = $this->site()->request('POST', $path, $this->asViewer('owner'), ['feed' => 'https://feed.example/', '__bTk' => $this->tokenOf('owner')]);
            $this->assertSame(404, $answer->status, 'POST ' . $path);
            $this->assertNull($answer->header('Location'), 'POST ' . $path);
        }
    }

    public function testEveryCaseThatDiffersOnPurposeIsARecordedCase(): void
    {
        foreach (self::CHANGED as $case => $why) {
            $this->assertNotSame('', $why, $case);
            $this->assertFileExists(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt', $case);
        }
    }
}
