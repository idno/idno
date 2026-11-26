<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the post permalink answers to a POST and to a DELETE, recorded from the legacy page over HTTP on a site of the test's own (the requests
 * change it): the form token's refusal, an edit with the token that changes nothing, and a delete by the owner, by another member and with no token.
 * The answers are `Tests/Characterisation/fixtures/answers/permalink-writes/`.
 *
 * A form token is a secret of its session: no recording holds one (the recorder refuses a 64-digit hex value). The scenario is run on two
 * sites, so that a capture that is verified records each case twice (a site that has been through it answers some cases differently: the second run of
 * the same scenario on one site showed no session message).
 */
final class PermalinkWritesTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'permalink-writes';

    /** The note the scenario deletes: Bob's, with a fixed id. */
    private const DISPOSABLE = '00000000000040008000000000000051';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    /** The scrub of a response that repeats the token the request carried (a `rel="self"` link holds the address as asked, and so does the log-in link of a refusal). */
    private static function withoutToken(string $token): \Closure
    {
        // the refusal's log-in link carries the address as asked (with the token), written in base64: it is replaced whole
        $swap = static fn (string $text): string => (string) preg_replace('/fwd=[A-Za-z0-9+\/=_%-]+/', 'fwd={address}', str_replace($token, '{form-token}', $text));

        return static fn (SiteResponse $r): SiteResponse => new SiteResponse($r->status, array_map($swap, $r->headers), $swap($r->body), array_map($swap, $r->lines));
    }

    /**
     * The answer without its body, which is the error page of an exception: a site in debug mode (the rig) draws the exception's trace, with the
     * server's paths. What is kept of it is the status, the headers and whether the page names the refusal.
     */
    private static function withoutTrace(SiteResponse $r): SiteResponse
    {
        $says = str_contains($r->body, 'The page you were on timed out.') ? 'says the page timed out' : 'does not say the page timed out';

        return new SiteResponse($r->status, $r->headers, '[the error page of an exception; it ' . $says . "]\n", $r->lines);
    }

    /** Writes Bob's disposable note (again, after an earlier run deleted it) and returns its address. */
    private function disposableNote(): string
    {
        $path = $this->runInSite(<<<'PHP'
        $bob = \Idno\Entities\User::getByHandle('bob');
        $note = new \IdnoPlugins\Status\Status();
        $note->_id = '00000000000040008000000000000051';
        $note->body = 'Bob writes a note to be deleted.';
        $note->setOwner($bob);
        $note->setAccess('PUBLIC');
        $note->setPublishStatus('published');
        $note->created = (int) strtotime('2026-03-16 12:00:00 UTC');
        $note->setSlugResilient('Bob writes a note to be deleted.');
        if (!$note->save(true)) {
            throw new \RuntimeException('not saved');
        }
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $record = $store->findById('entities', (string) $note->getID());
        $record['updated'] = (int) strtotime('2026-03-20 12:00:00 UTC');
        $store->save('entities', $record);

        return substr($note->getURL(), strlen(\Idno\Core\Idno::site()->config()->getURL()));
        PHP);
        $this->assertIsString($path);

        return '/' . $path;
    }

    /** The scenario changes the site, so each recording of a capture that is verified is made on a site of its own: this is the first. */
    public function testAPostAndADeleteOfAPermalink(): void
    {
        $this->scenario();
    }

    /** The same scenario on another site: in a capture that is verified, the second recording of each case; otherwise it holds the same answers again. */
    public function testTheSameScenarioOnAnotherSiteAnswersTheSame(): void
    {
        $this->scenario();
    }

    protected static function passes(): int
    {
        return 1;
    }

    private function scenario(): void
    {
        $paths = $this->seedPermalinkContent();
        $note = '/' . $paths['bob-public'];
        $text = 'Bob publishes a note anyone can read.';
        // a POST with no token: the form token is the session's, and the page forwards with a message
        $this->record('post-no-token-visitor', 'POST', $note, [], 'visitor', ['body' => $text]);
        $this->record('post-no-token-owner', 'POST', $note, [], 'owner', ['body' => $text]);
        $this->record('post-no-token-owner-next', 'GET', '/', [], 'owner');
        // with the token and nothing changed: the owner's edit (which stamps the post's time: it is not read again) and another member's
        $this->record('post-token-other-member', 'POST', $note, [], 'member', ['__bTk' => $this->tokenOf('member'), 'body' => $text]);
        $this->record('post-token-no-change-owner', 'POST', $note, [], 'owner', ['__bTk' => $this->tokenOf('owner'), 'body' => $text]);
        $this->record('post-token-no-change-owner-next', 'GET', '/', [], 'owner');

        $referer = ['Referer' => $this->baseUrl()];
        $disposable = $this->disposableNote();
        $this->record('delete-no-token-owner', 'DELETE', $disposable, $referer, 'owner', null, self::withoutTrace(...));
        $this->record('delete-visitor', 'DELETE', $disposable, $referer, 'visitor', null, self::withoutTrace(...));
        $this->record('delete-no-token-still-there', 'GET', $disposable);

        // another member's DELETE with the token of their own session is refused and the post stays: these two answers, and the two of the visitor's
        // below, were recorded from a page that deleted it (it never asked who the requester was) and were captured again when that was fixed
        $other = $this->record('delete-token-other-member', 'DELETE', $disposable . '?__bTk=' . $this->tokenOf('member'), $referer, 'member', null, self::withoutToken($this->tokenOf('member')));
        $this->assertSame(403, $other->status, 'another member is refused');
        $then = $this->record('delete-token-other-member-then', 'GET', $disposable);
        $this->assertSame(200, $then->status, 'the post is still there');

        // and so is a visitor's, with the token of the session they were given
        $disposable = $this->disposableNote();
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $visitorToken = $this->tokenWith(['Cookie' => $cookie]);
        $this->record('post-token-visitor', 'POST', $note, ['Cookie' => $cookie], 'visitor', ['__bTk' => $visitorToken, 'body' => $text]);
        $refused = $this->record('delete-token-visitor', 'DELETE', $disposable . '?__bTk=' . $visitorToken, $referer + ['Cookie' => $cookie], 'visitor', null, self::withoutToken($visitorToken));
        $this->assertSame(403, $refused->status, 'a visitor is refused');
        $then = $this->record('delete-token-visitor-then', 'GET', $disposable);
        $this->assertSame(200, $then->status, 'the post is still there');

        $disposable = $this->disposableNote();
        $this->record('delete-token-owner-still-there', 'GET', $disposable);
        $this->record('delete-token-owner', 'DELETE', $disposable . '?__bTk=' . $this->tokenOf('owner'), $referer, 'owner', null, self::withoutToken($this->tokenOf('owner')));
        $this->record('delete-token-owner-gone', 'GET', $disposable);
        $this->record('delete-token-owner-next', 'GET', '/', [], 'owner');
        $this->record('delete-missing-post', 'DELETE', '/2026/no-such-slug-here?__bTk=' . $this->tokenOf('owner'), $referer, 'owner', null, self::withoutToken($this->tokenOf('owner')));
    }
}
