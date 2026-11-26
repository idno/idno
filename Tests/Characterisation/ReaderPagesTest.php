<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the member's drafts page (`/drafts/`), the following page (`/following/`) and the stream address (`/stream/`) answer, recorded from the legacy pages
 * over HTTP: the status, headers and body of each to a visitor, to members with and without anything to list, to an administrator, in each template type, for
 * each page of the drafts, for each method, for a signed request and for a request that says it is an XHR one. The answers are
 * `Tests/Characterisation/fixtures/answers/member/`; the pages that replace the legacy ones are held to them. Each case is a literal, in the call.
 *
 * The viewers are `visitor` (nobody), `member` (Carol, who has no draft and follows nobody), `owner` (Bob, who has twenty-six drafts, so that the drafts
 * have a second page, and two subscriptions, one of a feed that names itself with markup and a script address) and `admin` (Ada, who has two drafts).
 */
final class ReaderPagesTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'member';

    /** The cases the pages that replace the legacy ones answer differently ON PURPOSE (case => why). */
    private const CHANGED = [];

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

        // The reader's entities are written to the `entities` collection (an entity class that names another collection through a redeclared private
        // property is still saved there) and read from `reader`, where an upgraded site holds them: the records are put where they are read.
        $move = static function (\Idno\Common\Entity $entity) use ($store, $at): void {
            $record = $store->findById('entities', (string) $entity->getID());
            $store->delete('entities', (string) $entity->getID());
            $record['updated'] = $at('2026-03-20 12:00:00');
            if ($store->save('reader', $record) === false) {
                throw new \RuntimeException('record not moved');
            }
        };
        $subscribe = static function (int $n, string $feedUrl, string $siteUrl, string $title) use ($id, $at, $bob, $move): void {
            $feed = new \Idno\Entities\Reader\Feed();
            $feed->_id = $id($n);
            $feed->feed_url = $feedUrl;
            $feed->url = $siteUrl;
            $feed->title = $title;
            $feed->created = $at('2026-03-01 09:00:00') + $n;
            if (!$feed->save(true)) {
                throw new \RuntimeException('feed not saved');
            }
            $move($feed);
            $subscription = new \Idno\Entities\Reader\Subscription();
            $subscription->_id = $id($n + 1000);
            $subscription->feed_url = $feedUrl;
            $subscription->setOwner($bob);
            $subscription->created = $at('2026-03-01 09:30:00') + $n;
            if (!$subscription->save(true)) {
                throw new \RuntimeException('subscription not saved');
            }
            $move($subscription);
        };
        $subscribe(200, 'https://ada.example/feed.xml', 'https://ada.example/', 'Ada\'s writing');
        $subscribe(201, 'https://hostile.example/feed.xml?a=1&b="x"', 'javascript:alert(1)', 'Hostile "><x-canary a=\'1');

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

    public function testTheFollowingPageForEachViewer(): void
    {
        $this->record('following-visitor', 'GET', '/following/');
        $this->record('following-visitor-json', 'GET', '/following/?_t=json');
        $this->record('following-visitor-markdown', 'GET', '/following/?_t=markdown');
        $this->record('following-member', 'GET', '/following/', [], 'member');
        $this->record('following-member-json', 'GET', '/following/?_t=json', [], 'member');
        $this->record('following-owner', 'GET', '/following/', [], 'owner');
        $this->record('following-owner-json', 'GET', '/following/?_t=json', [], 'owner');
        $this->record('following-owner-markdown', 'GET', '/following/?_t=markdown', [], 'owner');
        $this->record('following-owner-rss', 'GET', '/following/?_t=rss', [], 'owner');
        $this->record('following-admin', 'GET', '/following/', [], 'admin');
        $this->record('following-admin-json', 'GET', '/following/?_t=json', [], 'admin');
        $this->addToAssertionCount(1);
    }

    public function testTheFollowingPageForEachMethodAndKindOfRequest(): void
    {
        $this->record('following-visitor-head', 'HEAD', '/following/');
        $this->record('following-owner-head', 'HEAD', '/following/', [], 'owner');
        $this->record('following-visitor-options', 'OPTIONS', '/following/');
        $this->record('following-owner-options', 'OPTIONS', '/following/', [], 'owner');
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->record('following-owner-xhr', 'GET', '/following/', $xhr, 'owner', null, self::withoutLastModified(...));
        $this->record('following-visitor-xhr', 'GET', '/following/', $xhr, 'visitor', null, self::withoutLastModified(...));
        $this->record('following-signed-api', 'GET', '/following/', self::signedHeadersFor('bob', $this->bobsKey(), '/following/'));
        $this->record('following-signed-api-json', 'GET', '/following/?_t=json', self::signedHeadersFor('bob', $this->bobsKey(), '/following/?_t=json'));
        $this->record('following-malformed-host', 'GET', '/following/', ['Host' => 'bad host!']);
        $this->record('following-no-slash', 'GET', '/following', [], 'owner');
        $this->record('following-double-slash', 'GET', '//following//', [], 'owner');
        $this->record('following-post-visitor', 'POST', '/following/', [], 'visitor', ['a' => 'b']);
        $this->addToAssertionCount(1);
    }

    /** The stream address, which the page that listed feed items answered with an empty page: nothing ever made an item on a supported PHP. */
    public function testTheStreamAddress(): void
    {
        $this->record('stream-visitor', 'GET', '/stream/');
        $this->record('stream-member', 'GET', '/stream/', [], 'member');
        $this->record('stream-admin', 'GET', '/stream/', [], 'admin');
        $this->record('stream-member-json', 'GET', '/stream/?_t=json', [], 'member');
        $this->record('stream-head', 'HEAD', '/stream/');
        $this->addToAssertionCount(1);
    }

    public function testEveryCaseThatDiffersOnPurposeIsARecordedCase(): void
    {
        foreach (self::CHANGED as $case => $why) {
            $this->assertNotSame('', $why, $case);
            $this->assertFileExists(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt', $case);
        }
    }
}
