<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\MailsAlerts;
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
    use MailsAlerts;

    protected function setUp(): void
    {
        parent::setUp();
        // the site has an address for the alert of a failure, so that \"nothing was mailed\" can fail
        $this->mailAlertsToAnAdministrator();
    }

    use RecordedAnswers;

    protected const GROUP = 'permalink-writes';

    /**
     * The cases the page that replaced the legacy one answers differently ON PURPOSE: the recordings are the legacy page's and are kept as they were, and the
     * test of each asserts the answer of the page that serves it now.
     */
    private const CHANGED = [
        'delete-no-token-owner' => 'a DELETE with no form token is refused with the site\'s 403 page, nothing reported and nobody mailed (the legacy page threw the exception of a timed out page: the reported 500 page)',
        'delete-visitor' => 'the same, for a visitor',
        'put-no-token-owner' => 'a PUT with no form token is refused the same way (it was the reported 500 page)',
        'put-visitor' => 'the same, for a visitor',
        'post-signed-owner' => 'the JSON that tells a signed request where to go is sent as application/json (the legacy page sent it as text/html)',
        'post-signed-json-owner' => 'the same',
        'post-signed-missing-post' => 'the same',
        'delete-signed-owner' => 'the same',
    ];

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

    /**
     * The scrub of an answer to a request that carried a token (or none): the tokens, the log-in link's address (which holds the request's, in base64), the
     * trace of an exception page (a site in debug mode draws it, with the server's paths) and, when asked, the time the post was last changed (a write
     * stamps it with the time of the request).
     */
    private static function scrubbed(array $tokens, bool $lastModified = false): \Closure
    {
        return static function (SiteResponse $r) use ($tokens, $lastModified): SiteResponse {
            $swap = static function (string $text) use ($tokens, $lastModified): string {
                foreach ($tokens as $token) {
                    $text = str_replace($token, '{form-token}', $text);
                }
                $text = (string) preg_replace('/fwd=[A-Za-z0-9+\/=_%-]+/', 'fwd={address}', $text);
                // the JSON of an exception says where it was thrown, with the server's path
                $text = (string) preg_replace('/"file": "[^"]*"/', '"file": "{file}"', $text);
                if ($lastModified) {
                    $text = (string) preg_replace('/^(last-modified:).*$/mi', '$1 {the time of the write}', $text);
                    $text = (string) preg_replace('/"updated": "[^"]+"/', '"updated": "{the time of the write}"', $text);
                    $text = (string) preg_replace('/(<time class="dt-updated"[^>]*datetime=")[^"]+"/', '$1{the time of the write}"', $text);
                }

                return $text;
            };
            $body = str_contains($r->body, '{main}') ? '[the error page of an exception; it ' . (str_contains($r->body, 'The page you were on timed out.') ? 'says the page timed out' : 'does not say the page timed out') . "]\n" : $swap($r->body);

            return new SiteResponse($r->status, array_map($swap, $r->headers), $body, array_map($swap, $r->lines));
        };
    }

    /** The mail the site has sent since the last call, as a list (the log is emptied). */
    private function mailLog(): array
    {
        $log = json_decode($this->site()->request('GET', '/_test/mail/log')->body, true);
        $this->assertIsArray($log);

        return $log;
    }

    /**
     * A DELETE with no form token is the site's 403 page, which says the page timed out; nothing is reported (no line of the exception in the log) and no
     * alert is mailed to the administrator, as the exception of the legacy page was.
     */
    private function assertRefusedAndNotReported(SiteResponse $answer, string $who): void
    {
        $this->assertSame(403, $answer->status, $who);
        $this->assertStringContainsString('The page you were on timed out.', $answer->body, $who);
        $this->assertStringNotContainsString('{main}', $answer->body, $who . ': no trace of an exception');
        $this->assertSame([], $this->mailLog(), $who . ': nobody is mailed');
        $this->assertStringNotContainsString('timed out', $this->serverLogContents(), $who . ': nothing is reported');
    }

    /**
     * The answer to a signed request that is told where to go is the recording of the legacy page's answer, but for its type: the JSON `{"location": ...}`
     * is sent as `application/json`, where the legacy page sent it as `text/html; charset=UTF-8`.
     */
    private function assertTheRecordingButForItsJsonType(string $case, SiteResponse $answer): void
    {
        $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt');
        $expected = str_replace("content-type: text/html; charset=UTF-8\n", "content-type: application/json\n", $recorded);
        // (and its type is chosen from the request's Accept header, which every such page says: the recording was made before pages said so)
        $expected = str_replace("pragma: no-cache\n", "pragma: no-cache\nvary: Accept\n", $expected);
        $this->assertNotSame($recorded, $expected, $case . ': the recording holds the legacy type');
        $this->assertSame($expected, self::normalise(self::scrubbed([], true)($answer), $this->baseUrl()), $case);
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

    /** The home page is recorded after the writes, and it counts the posts: the site holds no more than it did when it was recorded. */
    protected function seedsOwnedPosts(): bool
    {
        return false;
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
        $this->mailLog();
        $answer = $this->record('delete-no-token-owner', 'DELETE', $disposable, $referer, 'owner', null, self::withoutTrace(...));
        $this->assertRefusedAndNotReported($answer, 'the owner');
        $answer = $this->record('delete-visitor', 'DELETE', $disposable, $referer, 'visitor', null, self::withoutTrace(...));
        $this->assertRefusedAndNotReported($answer, 'a visitor');
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

        $this->theWaysAPermalinkIsWrittenTo($paths);
    }

    /**
     * What the page answers to each other way a post is written to, as the page is today: an edit by the owner that changes the post (and the page read
     * after it), the Referer branch of the redirect, a post that is not there, an administrator, an XHR request, `?_t=json`, a signed API request, a DELETE
     * by an administrator and in each of those forms, and the methods the page defines no handler for.
     *
     * @param array<string, mixed> $paths
     */
    /** The scrub of an answer to a request of any of the three members, with the tokens their sessions hold now. */
    private function scrubAll(): \Closure
    {
        return self::scrubbed([$this->tokenOf('owner'), $this->tokenOf('admin'), $this->tokenOf('member')], true);
    }

    private function theWaysAPermalinkIsWrittenTo(array $paths): void
    {
        $note = '/' . $paths['bob-public'];
        $elsewhere = ['Referer' => $this->baseUrl() . 'somewhere/else'];
        $foreign = ['Referer' => 'https://elsewhere.example/page'];

        // an edit that changes the post, with a Referer (a success forwards to the post, whatever the Referer says), and the page read after it
        $this->record('post-token-change-owner', 'POST', $note, $elsewhere, 'owner', ['__bTk' => $this->tokenOf('owner'), 'body' => 'Bob changes his note.'], $this->scrubAll());
        $this->record('post-token-change-owner-then', 'GET', $note, [], 'visitor', null, self::scrubbed([], true));
        $this->record('post-token-change-owner-then-json', 'GET', $note . '?_t=json', [], 'visitor', null, self::scrubbed([], true));
        // an administrator edits another member's post
        $this->record('post-token-change-admin', 'POST', $note, [], 'admin', ['__bTk' => $this->tokenOf('admin'), 'body' => 'Ada changes Bob\'s note.'], $this->scrubAll());
        $this->record('post-token-change-admin-then', 'GET', $note, [], 'visitor', null, self::scrubbed([], true));
        // the Referer branch: an edit that is refused forwards to where the request came from
        $this->record('post-token-other-member-referer', 'POST', $note, $elsewhere, 'member', ['__bTk' => $this->tokenOf('member'), 'body' => 'Carol changes it.'], $this->scrubAll());
        $this->record('post-token-other-member-foreign-referer', 'POST', $note, $foreign, 'member', ['__bTk' => $this->tokenOf('member'), 'body' => 'Carol changes it.'], $this->scrubAll());
        $this->record('post-token-other-member-no-referer', 'POST', $note, [], 'member', ['__bTk' => $this->tokenOf('member'), 'body' => 'Carol changes it.'], $this->scrubAll());
        $this->record('post-no-token-referer', 'POST', $note, $elsewhere, 'owner', ['body' => 'No token.'], $this->scrubAll());
        // a post that is not there, and one that is not readable
        $this->record('post-token-missing-post', 'POST', '/2026/no-such-slug-here', $elsewhere, 'owner', ['__bTk' => $this->tokenOf('owner'), 'body' => 'x'], $this->scrubAll());
        $this->record('post-token-private-post-visitor', 'POST', '/' . $paths['bob-owner'], $elsewhere, 'member', ['__bTk' => $this->tokenOf('member'), 'body' => 'x'], $this->scrubAll());
        // an XHR request and a JSON one
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->record('post-xhr-no-token-owner', 'POST', $note, $xhr, 'owner', ['body' => 'x'], $this->scrubAll());
        $this->record('post-xhr-token-owner', 'POST', $note, $xhr, 'owner', ['__bTk' => $this->tokenOf('owner'), 'body' => 'Bob changes it by XHR.'], $this->scrubAll());
        $this->record('post-json-no-token-owner', 'POST', $note . '?_t=json', [], 'owner', ['body' => 'x'], $this->scrubAll());
        $this->record('post-json-token-owner', 'POST', $note . '?_t=json', [], 'owner', ['__bTk' => $this->tokenOf('owner'), 'body' => 'Bob changes it, as JSON.'], $this->scrubAll());

        // DELETE by an administrator, by XHR, as JSON, signed; each on a post made again
        $referer = ['Referer' => $this->baseUrl()];
        $disposable = $this->disposableNote();
        $this->record('delete-token-admin', 'DELETE', $disposable . '?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll());
        $this->record('delete-token-admin-then', 'GET', $disposable);
        $disposable = $this->disposableNote();
        $this->record('delete-xhr-token-owner', 'DELETE', $disposable . '?__bTk=' . $this->tokenOf('owner'), $xhr + $referer, 'owner', null, $this->scrubAll());
        $this->record('delete-xhr-token-owner-then', 'GET', $disposable);
        $disposable = $this->disposableNote();
        $this->record('delete-json-token-owner', 'DELETE', $disposable . '?_t=json&__bTk=' . $this->tokenOf('owner'), $referer, 'owner', null, $this->scrubAll());
        $this->record('delete-json-token-owner-then', 'GET', $disposable);
        $disposable = $this->disposableNote();
        $disposable = $this->disposableNote();
        $this->record('delete-token-owner-foreign-referer', 'DELETE', $disposable . '?__bTk=' . $this->tokenOf('owner'), $foreign, 'owner', null, $this->scrubAll());
        $this->record('delete-token-owner-no-referer', 'DELETE', $this->disposableNote() . '?__bTk=' . $this->tokenOf('owner'), [], 'owner', null, $this->scrubAll());
        $this->record('delete-token-missing-post-admin', 'DELETE', '/2026/no-such-slug-here?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll());

        // the methods the page has no handler for
        $this->record('put-token-owner', 'PUT', $note . '?__bTk=' . $this->tokenOf('owner'), [], 'owner', ['body' => 'x'], $this->scrubAll());
        $this->assertRefusedAndNotReported($this->record('put-no-token-owner', 'PUT', $note, [], 'owner', ['body' => 'x'], $this->scrubAll()), 'the owner\'s PUT');
        $this->assertRefusedAndNotReported($this->record('put-visitor', 'PUT', $note, [], 'visitor', ['body' => 'x'], $this->scrubAll()), 'a visitor\'s PUT');
        $this->record('patch-token-owner', 'PATCH', $note . '?__bTk=' . $this->tokenOf('owner'), [], 'owner', ['body' => 'x'], $this->scrubAll());
        $this->record('patch-visitor', 'PATCH', $note, [], 'visitor', ['body' => 'x'], $this->scrubAll());

        // a signed API request needs no token and carries no cookie: the stored key of the member signs the request's address (path and query)
        $signed = self::signedHeadersFor('bob', $this->bobsKey(), $note);
        $this->assertTheRecordingButForItsJsonType('post-signed-owner', $this->record('post-signed-owner', 'POST', $note, $signed, 'visitor', ['body' => 'Bob changes it, signed.'], self::scrubbed([], true)));
        $signedJson = self::signedHeadersFor('bob', $this->bobsKey(), $note . '?_t=json');
        $this->assertTheRecordingButForItsJsonType('post-signed-json-owner', $this->record('post-signed-json-owner', 'POST', $note . '?_t=json', $signedJson, 'visitor', ['body' => 'Bob changes it, signed, as JSON.'], self::scrubbed([], true)));
        $this->assertTheRecordingButForItsJsonType('post-signed-missing-post', $this->record('post-signed-missing-post', 'POST', '/2026/no-such-slug-here', self::signedHeadersFor('bob', $this->bobsKey(), '/2026/no-such-slug-here'), 'visitor', ['body' => 'x'], self::scrubbed([], true)));
        $disposable = $this->disposableNote();
        $this->assertTheRecordingButForItsJsonType('delete-signed-owner', $this->record('delete-signed-owner', 'DELETE', $disposable . '?x=1', self::signedHeadersFor('bob', $this->bobsKey(), $disposable . '?x=1') + $referer, 'visitor', null, self::scrubbed([], true)));
        $this->record('delete-signed-owner-then', 'GET', $disposable);
        // another member's signed requests are refused and change nothing
        $carol = $this->storedKeyOf('carol');
        $this->record('post-signed-other-member', 'POST', $note, self::signedHeadersFor('carol', $carol, $note), 'visitor', ['body' => 'Carol changes it, signed.'], self::scrubbed([], true));
        $disposable = $this->disposableNote();
        $this->record('delete-signed-other-member', 'DELETE', $disposable . '?x=1', self::signedHeadersFor('carol', $carol, $disposable . '?x=1') + $referer, 'visitor', null, self::scrubbed([], true));
        $this->record('delete-signed-other-member-then', 'GET', $disposable);
    }
}
