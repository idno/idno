<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the delete of an annotation answers to a POST, recorded from the legacy page over HTTP on a site of the test's own (the requests change it): the
 * form token's refusal, the refusal of a visitor, the owner of the post, another member who is not the annotation's author and one who is, an
 * administrator, a signed request, the two addresses the delete is made at (the post's `annotation/delete?permalink=` and `annotations/<id>/delete`), a post
 * that is not there or that the requester may not read, and the pages read after each. The answers are
 * `Tests/Characterisation/fixtures/answers/annotation-delete/`.
 *
 * A form token is a secret of its session: no recording holds one. The scenario is run on two sites, so that a capture that is verified records each case twice.
 */
final class AnnotationDeleteTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'annotation-delete';

    /** The cases the page that replaced the legacy one answers differently ON PURPOSE, with why; the test of each asserts the answer of the page that serves it now. */
    private const CHANGED = [
        'delete-signed-owner' => 'the JSON that tells a signed request where to go is sent as application/json (the legacy page sent it as text/html)',
        'delete-get' => 'a GET of the delete address is the legacy router\'s 404 page (a page a controller serves answers only the methods it declares; the legacy page answered with the site\'s 404 for a GET it did not define)',
        'delete-put' => 'a PUT is the legacy router\'s 404 page (the legacy page answered the site\'s 403 page for a request with no form token)',
        'delete-delete' => 'a DELETE is the legacy router\'s 404 page (the same)',
        'delete-override-delete' => 'a POST that asks to be handled as a DELETE is the legacy router\'s 404 page',
    ];

    /** The annotation Carol made herself on Bob's note (its address is the site's own, as the site makes it for a member's comment). */
    private const BY_CAROL = 'by-carol';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    /** The scrub of an answer: the log-in link's address, which holds the request's, in base64, and the tokens. */
    private static function scrubbed(array $tokens): \Closure
    {
        return static function (SiteResponse $r) use ($tokens): SiteResponse {
            $swap = static function (string $text) use ($tokens): string {
                foreach ($tokens as $token) {
                    $text = str_replace($token, '{form-token}', $text);
                }

                return (string) preg_replace('/"file": "[^"]*"/', '"file": "{file}"', (string) preg_replace('/fwd=[A-Za-z0-9+\/=_%-]+/', 'fwd={address}', $text));
            };

            // (the answer to an XHR request says when it was made)
            $headers = [];
            foreach ($r->headers as $name => $value) {
                $headers[$name] = strcasecmp((string) $name, 'last-modified') === 0 ? '{the time of the request}' : $swap((string) $value);
            }
            $lines = array_map(static fn (string $line): string => preg_match('/^last-modified:/i', $line) === 1 ? 'Last-Modified: {the time of the request}' : $swap($line), $r->lines);

            return new SiteResponse($r->status, $headers, $swap($r->body), $lines);
        };
    }

    private function scrub(): \Closure
    {
        return self::scrubbed([$this->tokenOf('owner'), $this->tokenOf('admin'), $this->tokenOf('member')]);
    }

    /** Adds Carol's own comment to Bob's annotated note (as the comment form does: the address is the annotation's own, the author's address is the profile's). */
    private function carolsComment(string $note, string $publicNote): void
    {
        $code = <<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $record = $store->findById('entities', '0000000000004000800000000000006b');
        $carol = \Idno\Entities\User::getByHandle('carol');
        $key = \Idno\Core\Idno::site()->config()->getURL() . %NOTE% . '/annotations/' . md5('by-carol');
        $record['annotations']['reply'][$key] = ['permalink' => $key, 'owner_name' => 'Carol Member', 'owner_url' => $carol->getURL(), 'owner_image' => '', 'content' => 'Carol comments on Bob\'s note herself.', 'time' => (int) strtotime('2025-12-11 17:00:00 UTC'), 'title' => ''];
        $store->save('entities', $record);
        // a like the site made for a member on Bob's public note: its address is the annotation's own
        $public = $store->findById('entities', '00000000000040008000000000000041');
        $own = \Idno\Core\Idno::site()->config()->getURL() . %PUBLIC% . '/annotations/' . md5('local');
        $public['annotations']['like'][$own] = ['permalink' => $own, 'owner_name' => 'Erin', 'owner_url' => 'https://example.org/erin', 'owner_image' => '', 'content' => '', 'time' => (int) strtotime('2026-03-15 15:00:00 UTC'), 'title' => ''];
        $store->save('entities', $public);

        return true;
        PHP;
        $this->assertTrue($this->runInSite(str_replace(['%NOTE%', '%PUBLIC%'], [$this->literal(ltrim($note, '/')), $this->literal(ltrim($publicNote, '/'))], $code)));
    }

    /** The scenario changes the site, so each recording of a capture that is verified is made on a site of its own: this is the first. */
    public function testTheDeleteOfAnAnnotation(): void
    {
        $this->scenario();
    }

    /** The same scenario on another site: in a capture that is verified, the second recording of each case; otherwise it holds the same answers again. */
    public function testTheSameScenarioOnAnotherSiteAnswersTheSame(): void
    {
        $this->scenario();
    }

    /** The home page is not recorded: the site holds Bob's posts. */
    protected static function passes(): int
    {
        return 1;
    }

    private function keyOf(string $note, string $name): string
    {
        return $this->baseUrl() . ltrim($note, '/') . '/annotations/' . md5($name);
    }

    private function deleteAddress(string $note, string $name): string
    {
        return $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($this->keyOf($note, $name));
    }

    private function scenario(): void
    {
        $paths = $this->seedPermalinkContent();
        $note = '/' . $paths['bob-annotated'];
        $this->carolsComment($note, '/' . $paths['bob-public']);
        $list = $note . '/annotations/';
        $scrub = $this->scrub();
        $tokens = fn (string $viewer): array => ['__bTk' => $this->tokenOf($viewer)];

        $this->record('list-before', 'GET', $list, [], 'owner');

        // a POST with no token: the form token is the session's, and the page forwards with a message
        $this->record('delete-no-token-visitor', 'POST', $this->deleteAddress($note, 'like'), [], 'visitor', [], $scrub);
        $this->record('delete-no-token-owner', 'POST', $this->deleteAddress($note, 'like'), [], 'owner', [], $scrub);
        // with the token of a visitor's session: the 403 page
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $visitorToken = $this->tokenWith(['Cookie' => $cookie]);
        $this->record('delete-token-visitor', 'POST', $this->deleteAddress($note, 'like'), ['Cookie' => $cookie], 'visitor', ['__bTk' => $visitorToken], self::scrubbed([$visitorToken]));
        // another member, who did not write it: nothing is deleted, and the page forwards to the post's comments
        $this->record('delete-token-member', 'POST', $this->deleteAddress($note, 'like'), [], 'member', $tokens('member'), $scrub);
        $this->record('delete-token-member-then', 'GET', $list, [], 'owner');
        // the member who wrote an annotation of their own: the post is Bob's and saving it is refused to Carol, so it stays
        $this->record('delete-token-author', 'POST', $this->deleteAddress($note, self::BY_CAROL), [], 'member', $tokens('member'), $scrub);
        $this->record('delete-token-author-then', 'GET', $list, [], 'owner');
        // the post that is not there, and one the requester may not read
        $this->record('delete-token-missing-post', 'POST', '/2026/no-such-slug-here/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode('https://x.example/a'), [], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-token-private-post-member', 'POST', '/' . $paths['bob-owner'] . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($this->keyOf('/' . $paths['bob-owner'], 'reply')), [], 'member', $tokens('member'), $scrub);
        $this->record('delete-token-view-id-owner-unknown', 'POST', '/view/0000000000004000800000000000006b/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode('https://x.example/nothing'), [], 'owner', $tokens('owner'), $scrub);
        // the owner of the post: with a permalink that names nothing, nothing is deleted
        $this->record('delete-token-owner-unknown', 'POST', $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode('https://x.example/nothing'), [], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-token-owner-no-permalink', 'POST', $note . '/annotations/' . md5('nothing') . '/delete', [], 'owner', $tokens('owner'), $scrub);
        // a signed request, an XHR request, JSON
        $signed = $this->deleteAddress($note, 'mention');
        $answer = $this->record('delete-signed-owner', 'POST', $signed, self::signedHeadersFor('bob', $this->bobsKey(), $signed), 'visitor', []);
        $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/delete-signed-owner.txt');
        $expected = str_replace("content-type: text/html; charset=UTF-8\n", "content-type: application/json\n", $recorded);
        $this->assertNotSame($recorded, $expected, 'the recording holds the legacy type');
        $this->assertSame($expected, self::normalise($answer, $this->baseUrl()));
        $this->record('delete-signed-owner-then', 'GET', $list . '?_t=rss', [], 'owner');
        $this->record('delete-xhr-owner', 'POST', $this->deleteAddress($note, 'share'), ['X-Requested-With' => 'XMLHttpRequest'], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-xhr-owner-then', 'GET', $list . '?_t=rss', [], 'owner');
        // the owner deletes one at the address of its own, and an administrator another
        $this->record('delete-token-owner-by-id', 'POST', $note . '/annotations/' . md5('reply') . '/delete', [], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-token-owner-by-id-then', 'GET', $list, [], 'owner');
        $this->record('delete-token-owner', 'POST', $this->deleteAddress($note, 'like'), [], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-token-owner-then', 'GET', $list, [], 'owner');
        $this->record('delete-token-owner-again', 'POST', $this->deleteAddress($note, 'like'), [], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-json-owner', 'POST', $this->deleteAddress($note, self::BY_CAROL) . '&_t=json', [], 'owner', $tokens('owner'), $scrub);
        $this->record('delete-json-owner-then', 'GET', $list, [], 'owner');
        $public = '/' . $paths['bob-public'];
        $this->record('delete-token-admin', 'POST', $this->deleteAddress($public, 'local'), [], 'admin', $tokens('admin'), $scrub);
        $this->record('delete-token-admin-then', 'GET', $public . '/annotations/', [], 'admin');

        // the methods the page does not take: the legacy router's 404 page (the legacy page answered a GET with the 501 page, a PUT or DELETE with the 403 page
        // of a request with no form token, and a POST that asks to be handled as a DELETE as the POST it is). What the request asked is not done.
        foreach ([
            'delete-get' => ['GET', null],
            'delete-put' => ['PUT', $tokens('owner')],
            'delete-delete' => ['DELETE', null],
        ] as $case => [$method, $body]) {
            $answer = $this->record($case, $method, $this->deleteAddress($note, 'like'), [], 'owner', $body, $scrub);
            $this->assertSame(404, $answer->status, $case);
        }
        $answer = $this->record('delete-override-delete', 'POST', $this->deleteAddress($note, 'like'), ['X-HTTP-Method-Override' => 'DELETE'], 'owner', $tokens('owner'), $scrub);
        $this->assertSame(404, $answer->status, 'a POST that asks to be handled as a DELETE: the page takes POST alone, as the real method and the one the header names');
        $this->record('delete-token-owner-final', 'GET', $list, [], 'owner');
        $this->addToAssertionCount(1);
    }
}
