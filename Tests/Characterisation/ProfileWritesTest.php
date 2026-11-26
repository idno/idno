<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What a profile's address answers to a POST (the profile edit form, which posts to the profile's own address) and to a DELETE, recorded from the legacy
 * page over HTTP on a site of the test's own (the requests change it): the form token's refusal, an edit by the member, by another member, by an
 * administrator, by a visitor and by a signed request, a profile that is not there, a request a script sends, and each way a DELETE is made. The answers
 * are `Tests/Characterisation/fixtures/answers/profile-writes/`.
 *
 * A form token is a secret of its session: no recording holds one. The scenario is run on two sites, so that a capture that is verified records each case
 * twice (a site that has been through it answers some cases differently).
 */
final class ProfileWritesTest extends ProfileSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'profile-writes';

    private const CHANGED = [
        'delete-token-owner-handle-xhr' => 'a DELETE of the profile address is no handler of the page: a script\'s request, which cannot be forwarded, is answered 501 (it was 200, from the forward that deleted nothing)',
        'delete-token-visitor-handle' => 'a DELETE of the profile address is no handler of the page: with a valid token it is answered 501 and forwarded, whoever sends it (a visitor was refused 403 by a gatekeeper that no longer runs)',
        'delete-token-visitor-id' => 'the same, for an address that holds an account\'s id (nothing is looked up as an entity\'s id any more)',
        'delete-token-member-id-other' => 'the same: another member\'s account is deleted by no one at this address, and the answer no longer refuses it',
        'delete-token-owner-id-own' => 'the same: nor is a member\'s own',
        'delete-token-admin-id-account' => 'the page no longer looks the address up as an entity\'s id: an administrator\'s DELETE of an account\'s id deletes nothing (an account is deleted at /view/<id> or from the Users page) and goes to the home page, not to the Referer',
        'delete-token-admin-id-account-then' => 'the account is still there',
        'delete-token-admin-id-post' => 'the same for a post\'s id: nothing is deleted, and the answer goes to the home page',
        'delete-signed-owner-handle' => 'a signed DELETE of the profile address is answered 501 with the JSON of the page (it was 200: the forward that deleted nothing)',
        'delete-signed-owner-id' => 'the same, for an address that holds an account\'s id (it was refused 403)',
        'post-signed-owner' => 'the JSON that tells a signed request where to go is sent as application/json (the legacy page sent it as text/html)',
        'post-signed-other-member' => 'the same',
        'post-signed-owner-then' => 'the profile lists the post that the legacy DELETE of its id deleted earlier in the scenario',
    ];

    /** The boundary of the multipart bodies the edit form sends. */
    private const BOUNDARY = '----idno-profile-edit-boundary';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    /**
     * A multipart body as the edit form posts it, and the header that says so.
     *
     * @param array<string, string> $fields
     * @param array{name: string, type: string, content: string}|null $avatar
     *
     * @return array{array<string, string>, string}
     */
    private static function multipart(array $fields, ?array $avatar = null): array
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . self::BOUNDARY . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
        }
        if ($avatar !== null) {
            $body .= '--' . self::BOUNDARY . "\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"" . $avatar['name'] . "\"\r\nContent-Type: " . $avatar['type'] . "\r\n\r\n" . $avatar['content'] . "\r\n";
        }
        $body .= '--' . self::BOUNDARY . "--\r\n";

        return [['Content-Type' => 'multipart/form-data; boundary=' . self::BOUNDARY], $body];
    }

    /**
     * The scrub of an answer to a request that carried a token (or none): the tokens, the log-in link's address (which holds the request's, in base64), the
     * trace of an exception page (a site in debug mode draws it, with the server's paths) and the time the account was last changed (a write stamps it with
     * the time of the request).
     *
     * @param list<string> $tokens
     */
    private static function scrubbed(array $tokens): \Closure
    {
        return static function (SiteResponse $r) use ($tokens): SiteResponse {
            $swap = static function (string $text) use ($tokens): string {
                foreach ($tokens as $token) {
                    $text = str_replace($token, '{form-token}', $text);
                }
                $text = (string) preg_replace('/fwd=[A-Za-z0-9+\/=_%-]+/', 'fwd={address}', $text);
                $text = (string) preg_replace('/"file": "[^"]*"/', '"file": "{file}"', $text);
                $text = (string) preg_replace('/^(last-modified:).*$/mi', '$1 {the time of the write}', $text);
                $text = (string) preg_replace('/"updated": "[^"]+"/', '"updated": "{the time of the write}"', $text);

                return (string) preg_replace('/(<time class="dt-updated"[^>]*datetime=")[^"]+"/', '$1{the time of the write}"', $text);
            };
            $body = str_contains($r->body, '{main}') ? '[the error page of an exception; it ' . (str_contains($r->body, 'The page you were on timed out.') ? 'says the page timed out' : 'does not say the page timed out') . "]\n" : $swap($r->body);

            return new SiteResponse($r->status, array_map($swap, $r->headers), $body, array_map($swap, $r->lines));
        };
    }

    private function scrubAll(): \Closure
    {
        return self::scrubbed([$this->tokenOf('owner'), $this->tokenOf('admin'), $this->tokenOf('member')]);
    }

    /**
     * The answer to a DELETE of the profile address that carried a valid token: a page with no handler for the method answers 501 and forwards, which is
     * the home page (the request's Referer is not asked), and nothing is deleted.
     */
    private function assertForwardedHome(SiteResponse $answer, string $who): void
    {
        $this->assertSame(302, $answer->status, $who);
        $this->assertSame($this->baseUrl(), $answer->lines('location')[0] ?? null, $who);
    }

    /**
     * The answer to a signed request that is told where to go is the recording of the legacy page's answer, but for its type: the JSON `{"location": ...}`
     * is sent as `application/json`, where the legacy page sent it as `text/html; charset=UTF-8`.
     */
    private function assertTheRecordingButForItsJsonType(string $case, SiteResponse $answer): void
    {
        $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt');
        $expected = str_replace("content-type: text/html; charset=UTF-8\n", "content-type: application/json\n", $recorded);
        $this->assertNotSame($recorded, $expected, $case . ': the recording holds the legacy type');
        $this->assertSame($expected, self::normalise(self::scrubbed([])($answer), $this->baseUrl()), $case);
    }

    /** The name of an account, as the site stores it. */
    private function nameOf(string $handle): string
    {
        $name = $this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getTitle();');
        $this->assertIsString($name);

        return $name;
    }

    /** Whether an account is stored. */
    private function stored(string $id): bool
    {
        return $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById(\'entities\', ' . $this->literal($id) . ') !== null;') === true;
    }

    public function testEveryCaseThatDiffersOnPurposeIsARecordedCase(): void
    {
        foreach (self::CHANGED as $case => $why) {
            $this->assertNotSame('', $why, $case);
            $this->assertFileExists(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt', $case);
        }
    }

    /** The scenario changes the site, so each recording of a capture that is verified is made on a site of its own: this is the first. */
    public function testTheEditFormAndADeleteOfAProfile(): void
    {
        $this->scenario();
    }

    /** The same scenario on another site: in a capture that is verified, the second recording of each case; otherwise it holds the same answers again. */
    public function testTheSameScenarioOnAnotherSiteAnswersTheSame(): void
    {
        $this->scenario();
    }

    /** The home page is not recorded here, and the site holds no more than the accounts and the posts of the fixture. */
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
        $paths = $this->seedProfiles();
        $profile = '/profile/bob';
        $referer = ['Referer' => $this->baseUrl() . 'somewhere/else'];
        $foreign = ['Referer' => 'https://elsewhere.example/page'];
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $then = self::scrubbed([]);
        $this->assertSame('Bob Member', $this->nameOf('bob'));

        // with no form token: the session's token is checked first, whoever posts, and the page forwards with a message
        [$type, $body] = self::multipart(['name' => 'Bob Without A Token']);
        $this->record('post-no-token-owner', 'POST', $profile, $type, 'owner', $body, $this->scrubAll());
        $this->record('post-no-token-owner-next', 'GET', '/', [], 'owner', null, $this->scrubAll());
        $this->record('post-no-token-visitor', 'POST', $profile, $type, 'visitor', $body, $this->scrubAll());
        $this->record('post-no-token-xhr-owner', 'POST', $profile, $type + $xhr, 'owner', $body, $this->scrubAll());
        $this->assertSame('Bob Member', $this->nameOf('bob'), 'nothing is stored without the token');

        // an edit by another member, by a visitor with the token of the session they were given: refused, and the account is as it was
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('member'), 'name' => 'Carol Renames Bob']);
        $this->record('post-token-other-member', 'POST', $profile, $type, 'member', $body, $this->scrubAll());
        $this->record('post-token-other-member-referer', 'POST', $profile, $type + $referer, 'member', $body, $this->scrubAll());
        $this->record('post-token-other-member-foreign-referer', 'POST', $profile, $type + $foreign, 'member', $body, $this->scrubAll());
        $this->record('post-token-other-member-xhr', 'POST', $profile, $type + $xhr, 'member', $body, $this->scrubAll());
        $this->record('post-token-other-member-then', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);
        $this->assertSame('Bob Member', $this->nameOf('bob'), 'another member does not rename the account');
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $visitorToken = $this->tokenWith(['Cookie' => $cookie]);
        [$type, $body] = self::multipart(['__bTk' => $visitorToken, 'name' => 'A Visitor Renames Bob']);
        $this->record('post-token-visitor', 'POST', $profile, $type + ['Cookie' => $cookie] + $referer, 'visitor', $body, self::scrubbed([$visitorToken]));
        $this->assertSame('Bob Member', $this->nameOf('bob'), 'a visitor does not rename the account');
        // a file that is not a picture is not taken for one
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('member'), 'name' => 'Carol Renames Bob'], ['name' => 'avatar.txt', 'type' => 'text/plain', 'content' => 'not a picture']);
        $this->record('post-token-other-member-avatar', 'POST', $profile, $type, 'member', $body, $this->scrubAll());
        $this->assertSame('Bob Member', $this->nameOf('bob'));

        // a profile that is not there: the site's 404 page, for a script's request too, whoever asks
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Nobody']);
        $this->record('post-token-missing-owner', 'POST', '/profile/nobody-has-this-handle', $type, 'owner', $body, $this->scrubAll());
        $this->record('post-token-missing-xhr-owner', 'POST', '/profile/nobody-has-this-handle', $type + $xhr, 'owner', $body, $this->scrubAll());
        [$adminType, $adminBody] = self::multipart(['__bTk' => $this->tokenOf('admin'), 'name' => 'Nobody']);
        $this->record('post-token-missing-admin', 'POST', '/profile/nobody-has-this-handle', $adminType, 'admin', $adminBody, $this->scrubAll());
        $this->record('post-token-missing-friendly-owner', 'POST', '/profile/nobody-has-this-handle/all', $type, 'owner', $body, $this->scrubAll());

        // the owner's edit with the token: the name and the profile's fields are stored and the page forwards to the profile, whatever the Referer says
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Robert Member', 'profile[description]' => 'Robert writes about <b>things</b>.', 'profile[url][]' => 'https://example.org/robert']);
        $this->record('post-token-owner-change', 'POST', $profile, $type + $referer, 'owner', $body, $this->scrubAll());
        $this->record('post-token-owner-change-then', 'GET', $profile, [], 'visitor', null, $then);
        $this->record('post-token-owner-change-then-json', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);
        $this->assertSame('Robert Member', $this->nameOf('bob'));
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob Member']);
        $this->record('post-token-owner-change-back', 'POST', $profile, $type, 'owner', $body, $this->scrubAll());
        $this->record('post-token-owner-no-referer-then', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner')]);
        $this->record('post-token-owner-no-fields', 'POST', $profile, $type, 'owner', $body, $this->scrubAll());
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob By Script']);
        $this->record('post-xhr-token-owner', 'POST', $profile, $type + $xhr, 'owner', $body, $this->scrubAll());
        $this->record('post-xhr-token-owner-then', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob As Json']);
        $this->record('post-json-token-owner', 'POST', $profile . '?_t=json', $type, 'owner', $body, $this->scrubAll());
        $this->record('post-json-no-token-owner', 'POST', $profile . '?_t=json', self::multipart(['name' => 'x'])[0], 'owner', self::multipart(['name' => 'x'])[1], $this->scrubAll());
        // the friendly address is the profile's too
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob Friendly']);
        $this->record('post-token-owner-friendly', 'POST', $profile . '/all', $type, 'owner', $body, $this->scrubAll());
        $this->record('post-token-owner-friendly-then', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);

        // an administrator does not edit another member's profile: an account is its member's own (`User::canEdit()`; a listener of `canEdit/user` may say otherwise)
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('admin'), 'name' => 'Bob Edited By Ada']);
        $this->record('post-token-admin-change', 'POST', $profile, $type, 'admin', $body, $this->scrubAll());
        $this->record('post-token-admin-change-then', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);
        $this->assertSame('Bob Friendly', $this->nameOf('bob'), 'an administrator does not rename another member');
        // the actor's address is the profile's (with the ActivityPub plugin off); with it on it is the plugin's page, which takes no POST
        $this->record('post-token-owner-actor', 'POST', '/actor/bob', self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob By Actor'])[0], 'owner', self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob By Actor'])[1], $this->scrubAll());

        // the methods the page has no handler for, and a DELETE
        $this->record('put-token-owner', 'PUT', $profile . '?__bTk=' . $this->tokenOf('owner'), [], 'owner', ['name' => 'x'], $this->scrubAll());
        $this->record('put-no-token-owner', 'PUT', $profile, [], 'owner', ['name' => 'x'], $this->scrubAll());
        $this->record('patch-token-owner', 'PATCH', $profile . '?__bTk=' . $this->tokenOf('owner'), [], 'owner', ['name' => 'x'], $this->scrubAll());
        $this->record('delete-no-token-owner', 'DELETE', $profile, $referer, 'owner', null, $this->scrubAll());
        $this->record('delete-no-token-visitor', 'DELETE', $profile, $referer, 'visitor', null, $this->scrubAll());
        $this->record('delete-token-owner-handle', 'DELETE', $profile . '?__bTk=' . $this->tokenOf('owner'), $referer, 'owner', null, $this->scrubAll());
        $answer = $this->record('delete-token-owner-handle-xhr', 'DELETE', $profile . '?__bTk=' . $this->tokenOf('owner'), $referer + $xhr, 'owner', null, $this->scrubAll());
        $this->assertSame(501, $answer->status, 'a script\'s DELETE of the profile address: no handler');
        $this->record('delete-token-member-handle', 'DELETE', $profile . '?__bTk=' . $this->tokenOf('member'), $referer, 'member', null, $this->scrubAll());
        $answer = $this->record('delete-token-visitor-handle', 'DELETE', $profile . '?__bTk=' . $visitorToken, $referer + ['Cookie' => $cookie], 'visitor', null, self::scrubbed([$visitorToken]));
        $this->assertForwardedHome($answer, 'a visitor');
        $this->record('delete-token-admin-handle', 'DELETE', $profile . '?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll());
        $this->record('delete-token-admin-handle-json', 'DELETE', $profile . '?_t=json&__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll());
        $this->record('delete-token-admin-missing', 'DELETE', '/profile/nobody-has-this-handle?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll());
        $this->record('delete-token-admin-friendly', 'DELETE', $profile . '/all?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll());
        $this->record('delete-after-then', 'GET', $profile . '?_t=json', [], 'visitor', null, $then);
        // an account's id is no handle: the page looked the address up as an entity's id (CHANGED: it deletes nothing)
        $ids = $paths['ids'];
        $this->assertForwardedHome($this->record('delete-token-member-id-other', 'DELETE', '/profile/' . $ids['bob'] . '?__bTk=' . $this->tokenOf('member'), $referer, 'member', null, $this->scrubAll()), 'another member');
        $this->assertForwardedHome($this->record('delete-token-owner-id-own', 'DELETE', '/profile/' . $ids['bob'] . '?__bTk=' . $this->tokenOf('owner'), $referer, 'owner', null, $this->scrubAll()), 'the member');
        $this->assertForwardedHome($this->record('delete-token-visitor-id', 'DELETE', '/profile/' . $ids['bob'] . '?__bTk=' . $visitorToken, $referer + ['Cookie' => $cookie], 'visitor', null, self::scrubbed([$visitorToken])), 'a visitor');
        $this->assertForwardedHome($this->record('delete-token-admin-id-post', 'DELETE', '/profile/' . $ids['bob-public'] . '?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $this->scrubAll()), 'an administrator');
        $this->assertTrue($this->stored((string) $ids['bob-public']), 'the post is still there');
        $this->assertTrue($this->stored((string) $ids['bob']), 'nobody deleted the account');

        // a signed API request needs no token and carries no cookie: the stored key of the member signs the request's address (path and query)
        $signed = self::signedHeadersFor('bob', $this->bobsKey(), $profile);
        $this->assertTheRecordingButForItsJsonType('post-signed-owner', $this->record('post-signed-owner', 'POST', $profile, $signed, 'visitor', ['name' => 'Bob Signed'], self::scrubbed([])));
        $then = $this->record('post-signed-owner-then', 'GET', $profile . '?_t=json', [], 'visitor', null, self::scrubbed([]));
        $this->assertStringContainsString('Bob publishes a note anyone can read.', $then->body, 'the profile lists the post nobody deleted');
        $this->assertStringContainsString('Bob Signed', $then->body, 'the signed edit was made');
        $carol = $this->storedKeyOf('carol');
        $this->assertTheRecordingButForItsJsonType('post-signed-other-member', $this->record('post-signed-other-member', 'POST', $profile, self::signedHeadersFor('carol', $carol, $profile), 'visitor', ['name' => 'Carol Signed'], self::scrubbed([])));
        $this->record('post-signed-missing', 'POST', '/profile/nobody-has-this-handle', self::signedHeadersFor('bob', $this->bobsKey(), '/profile/nobody-has-this-handle'), 'visitor', ['name' => 'x'], self::scrubbed([]));
        $answer = $this->record('delete-signed-owner-handle', 'DELETE', $profile . '?x=1', self::signedHeadersFor('bob', $this->bobsKey(), $profile . '?x=1') + $referer, 'visitor', null, self::scrubbed([]));
        $this->assertSame(501, $answer->status, 'a signed DELETE of the profile address: no handler');
        $answer = $this->record('delete-signed-owner-id', 'DELETE', '/profile/' . $ids['bob'] . '?x=1', self::signedHeadersFor('bob', $this->bobsKey(), '/profile/' . $ids['bob'] . '?x=1') + $referer, 'visitor', null, self::scrubbed([]));
        $this->assertSame(501, $answer->status);
        $this->assertTrue($this->stored((string) $ids['bob']), 'a signed request did not delete the account');

        // LAST: an administrator's DELETE of the address that holds an account's id deletes the account (the page looked the id up as an entity's)
        $scrub = $this->scrubAll();
        $this->assertForwardedHome($this->record('delete-token-admin-id-account', 'DELETE', '/profile/' . $ids['carol'] . '?__bTk=' . $this->tokenOf('admin'), $referer, 'admin', null, $scrub), 'an administrator');
        $this->assertSame(200, $this->record('delete-token-admin-id-account-then', 'GET', '/profile/carol?_t=json', [], 'visitor', null, self::scrubbed([]))->status, 'the account is still there');
        $this->assertTrue($this->stored((string) $ids['carol']), 'an administrator did not delete the account by the profile address');
    }

    /**
     * A DELETE of the profile's address is no handler of the page: it is answered as one to any other address of the page is, and deletes nothing. The
     * legacy page looked the HANDLE up as an entity's id, so it deleted the account or the post whose id the address held, never the profile of a handle:
     * an administrator's DELETE of `/profile/<an account's id>` deleted the account. An account is deleted from the Users page (`/delete/<id>`) or from
     * its `/view/<id>` address, by an administrator's session only.
     */
    public function testADeleteOfAProfileIsAnUndeclaredHandler(): void
    {
        $ids = $this->seedProfiles()['ids'];
        $referer = ['Referer' => $this->baseUrl()];
        foreach (['admin', 'owner', 'member'] as $viewer) {
            foreach (['an account' => (string) $ids['carol'], 'another account' => (string) $ids['bob'], 'a post' => (string) $ids['bob-public']] as $what => $id) {
                $token = $this->tokenOf($viewer);
                $missing = $this->site()->request('DELETE', '/profile/nobody-has-this-handle?__bTk=' . $token, $this->asViewer($viewer, $referer));
                $answer = $this->site()->request('DELETE', '/profile/' . $id . '?__bTk=' . $token, $this->asViewer($viewer, $referer));
                $this->assertSame($missing->status, $answer->status, $viewer . ', ' . $what);
                $this->assertSame($missing->lines('location'), $answer->lines('location'), $viewer . ', ' . $what);
                $this->assertTrue($this->stored($id), $viewer . ' deleted ' . $what . ' by the profile address');
            }
        }
        // the address of the account by its id is the permalink's: the administrator's session deletes there, as the Users page lets them
        $answer = $this->site()->request('DELETE', '/view/' . $ids['carol'] . '?__bTk=' . $this->tokenOf('admin'), $this->asViewer('admin', $referer));
        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->stored((string) $ids['carol']), 'by the /view address, which is the permalink\'s');
    }

    /** A profile picture the owner uploads is stored as the account's icon. */
    public function testTheOwnersAvatarIsStored(): void
    {
        $this->seedProfiles();
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertNotSame('', $png);
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('owner'), 'name' => 'Bob Member'], ['name' => 'face.png', 'type' => 'image/png', 'content' => $png]);
        $answer = $this->site()->request('POST', '/profile/bob', $this->asViewer('owner', $type), $body);
        $this->assertSame(302, $answer->status);
        $this->assertStringContainsString('/profile/bob', (string) ($answer->lines('location')[0] ?? ''));
        $icon = $this->runInSite('return (string) (\Idno\Entities\User::getByHandle(\'bob\')->icon ?? \'\');');
        $this->assertNotSame('', $icon, 'the picture is the account\'s icon');
        // and another member's upload stores nothing
        [$type, $body] = self::multipart(['__bTk' => $this->tokenOf('member'), 'name' => 'Carol'], ['name' => 'face.png', 'type' => 'image/png', 'content' => $png]);
        $this->site()->request('POST', '/profile/ada', $this->asViewer('member', $type), $body);
        $this->assertSame('', $this->runInSite('return (string) (\Idno\Entities\User::getByHandle(\'ada\')->icon ?? \'\');'));
    }
}
