<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * Who may delete a post (or a profile) with a DELETE to its address. The page that answers it asks the requester's rights as the page the
 * delete button uses does: a visitor and a member who may not edit the post are refused (the 403 page) and the post is still there; the owner and
 * an administrator delete it; a post the requester may not read is answered as a missing one is, so the answer does not say it exists.
 * Each test starts a site of its own, because the requests change it.
 */
final class PermalinkDeleteAccessTest extends PermalinkSiteCase
{
    /** This class asserts its answers itself and records none (the site case asks every class for the method). */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('PermalinkDeleteAccessTest records no answer.');
    }

    private const BOB_NOTE = '00000000000040008000000000000041';
    private const BOB_PRIVATE = '00000000000040008000000000000043';
    private const BOB_DRAFT = '00000000000040008000000000000044';

    /** Whether the record of an entity is stored. */
    private function stored(string $id): bool
    {
        $found = $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById(\'entities\', ' . $this->literal($id) . ') !== null;');

        return $found === true;
    }

    private function carolsKey(): string
    {
        $key = $this->runInSite('return \Idno\Entities\User::getByHandle(\'carol\')->getAPIkey();');
        $this->assertIsString($key);

        return $key;
    }

    /**
     * Each way a requester who is not allowed to edit the post can make the request.
     *
     * @return array<string, array{string, array<string, string>}> kind => [the path as sent, the headers]
     */
    private function nonOwnerRequests(string $path): array
    {
        $member = $path . '?__bTk=' . $this->tokenOf('member');
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $visitor = $path . '?__bTk=' . $this->tokenWith(['Cookie' => $cookie]);
        $signed = $path . '?x=1';

        return [
            'member' => [$member, $this->asViewer('member')],
            'visitor' => [$visitor, ['Cookie' => $cookie]],
            'signed' => [$signed, self::signedHeadersFor('carol', $this->carolsKey(), $signed)],
        ];
    }

    public function testNobodyButTheOwnerOrAnAdministratorDeletesAPostFromItsAddress(): void
    {
        $paths = $this->seedPermalinkContent();
        $targets = [
            'dated permalink' => ['/' . $paths['bob-public'], self::BOB_NOTE],
            'view address' => ['/view/' . self::BOB_NOTE, self::BOB_NOTE],
            'a plugin\'s permalink' => ['/' . $paths['article'], (string) $paths['ids']['article']],
            'a static page' => ['/' . $paths['page'], (string) $paths['ids']['page']],
        ];
        foreach ($targets as $shape => [$path, $id]) {
            foreach ($this->nonOwnerRequests($path) as $kind => [$sent, $headers]) {
                $answer = $this->site()->request('DELETE', $sent, $headers + ['Referer' => $this->baseUrl()]);
                $this->assertSame(403, $answer->status, "$kind, $shape: the answer is a refusal");
                $this->assertTrue($this->stored($id), "$kind, $shape: the post is still there");
            }
        }
    }

    public function testTheOwnerAndAnAdministratorDeleteAPost(): void
    {
        $paths = $this->seedPermalinkContent();
        $referer = ['Referer' => $this->baseUrl()];
        $owner = '/' . $paths['bob-public'] . '?__bTk=' . $this->tokenOf('owner');
        $answer = $this->site()->request('DELETE', $owner, $this->asViewer('owner', $referer));
        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->stored(self::BOB_NOTE), 'the owner deleted the post');

        $admin = '/view/' . self::BOB_PRIVATE . '?__bTk=' . $this->tokenOf('admin');
        $answer = $this->site()->request('DELETE', $admin, $this->asViewer('admin', $referer));
        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->stored(self::BOB_PRIVATE), 'an administrator deleted a post that is not theirs');

        $page = '/' . $paths['page'] . '?__bTk=' . $this->tokenOf('admin');
        $answer = $this->site()->request('DELETE', $page, $this->asViewer('admin', $referer));
        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->stored((string) $paths['ids']['page']), 'an administrator deleted a static page');
    }

    public function testAPostTheRequesterMayNotReadIsAnsweredAsAMissingPostIs(): void
    {
        $this->seedPermalinkContent();
        $referer = ['Referer' => $this->baseUrl()];
        $token = $this->tokenOf('member');
        $missing = $this->site()->request('DELETE', '/view/00000000000040008000000000000999?__bTk=' . $token, $this->asViewer('member', $referer));
        foreach ([self::BOB_PRIVATE => 'a private post', self::BOB_DRAFT => 'a draft'] as $id => $what) {
            $answer = $this->site()->request('DELETE', '/view/' . $id . '?__bTk=' . $token, $this->asViewer('member', $referer));
            $this->assertSame($missing->status, $answer->status, $what);
            $this->assertSame($missing->lines('location'), $answer->lines('location'), $what);
            $this->assertTrue($this->stored($id), $what . ' is still there');
        }
    }

    public function testAMemberDoesNotDeleteAnotherMembersProfile(): void
    {
        $paths = $this->seedPermalinkContent();
        $bob = (string) $paths['ids']['bob'];
        foreach (['/profile/' . $bob, '/view/' . $bob] as $path) {
            $answer = $this->site()->request('DELETE', $path . '?__bTk=' . $this->tokenOf('member'), $this->asViewer('member', ['Referer' => $this->baseUrl()]));
            $this->assertSame(403, $answer->status, $path);
            $this->assertTrue($this->stored($bob), $path . ': the account is still there');
        }
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $answer = $this->site()->request('DELETE', '/profile/' . $bob . '?__bTk=' . $this->tokenWith(['Cookie' => $cookie]), ['Cookie' => $cookie]);
        $this->assertSame(403, $answer->status);
        $this->assertTrue($this->stored($bob), 'a visitor did not delete the account');
    }

    public function testTheRedirectAfterADeleteStaysOnTheSite(): void
    {
        $paths = $this->seedPermalinkContent();
        $answer = $this->site()->request('DELETE', '/' . $paths['bob-public'] . '?__bTk=' . $this->tokenOf('owner'), $this->asViewer('owner', ['Referer' => 'https://evil.example/landing']));
        $this->assertSame(302, $answer->status);
        $location = $answer->lines('location')[0] ?? '';
        $this->assertStringStartsWith($this->baseUrl(), $location);
    }

    /** The `X-Requested-With` header: a request a script sends (the page does not forward it). */
    private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    public function testAnXhrDeleteStopsWhereAnOrdinaryOneDoes(): void
    {
        $paths = $this->seedPermalinkContent();
        $token = $this->tokenOf('member');
        $headers = $this->asViewer('member', self::XHR);
        $missing = $this->site()->request('DELETE', '/view/00000000000040008000000000000999?__bTk=' . $token, $headers);
        $this->assertLessThan(500, $missing->status, 'a missing post is no server error');
        foreach ([self::BOB_PRIVATE => 'a private post', self::BOB_DRAFT => 'a draft'] as $id => $what) {
            $answer = $this->site()->request('DELETE', '/view/' . $id . '?__bTk=' . $token, $headers);
            $this->assertSame($missing->status, $answer->status, $what . ' is answered as a missing post is');
            $this->assertSame($missing->body, $answer->body, $what . ': the same body');
            $this->assertTrue($this->stored($id), $what . ' is still there');
        }
        $answer = $this->site()->request('DELETE', '/' . $paths['bob-public'] . '?__bTk=' . $token, $headers);
        $this->assertSame(403, $answer->status, 'not yours');
        $this->assertTrue($this->stored(self::BOB_NOTE));
        $answer = $this->site()->request('DELETE', '/' . $paths['bob-public'] . '?__bTk=' . $this->tokenOf('owner'), $this->asViewer('owner', self::XHR));
        $this->assertLessThan(400, $answer->status, 'yours');
        $this->assertFalse($this->stored(self::BOB_NOTE), 'the owner deleted it');
    }

    public function testAnAccountIsDeletedOnlyByAnAdministratorsSession(): void
    {
        $paths = $this->seedPermalinkContent();
        $carol = (string) $paths['ids']['carol'];
        $own = '/profile/' . $carol . '?__bTk=' . $this->tokenOf('member');
        $answer = $this->site()->request('DELETE', $own, $this->asViewer('member'));
        $this->assertSame(403, $answer->status, 'a member does not delete their own account');
        $this->assertTrue($this->stored($carol));
        $signed = '/profile/' . $carol . '?x=1';
        $answer = $this->site()->request('DELETE', $signed, self::signedHeadersFor('carol', $this->carolsKey(), $signed));
        $this->assertSame(403, $answer->status, 'a signed request does not delete the account of its member');
        $this->assertTrue($this->stored($carol));
        $answer = $this->site()->request('DELETE', '/view/' . $carol . '?__bTk=' . $this->tokenOf('member'), $this->asViewer('member'));
        $this->assertSame(403, $answer->status, 'nor by its /view address');
        $this->assertTrue($this->stored($carol));

        $answer = $this->site()->request('DELETE', '/profile/' . $carol . '?__bTk=' . $this->tokenOf('admin'), $this->asViewer('admin', ['Referer' => $this->baseUrl()]));
        $this->assertSame(302, $answer->status);
        $this->assertFalse($this->stored($carol), 'an administrator deletes an account, as the Users page lets them');
    }

    public function testTheDeletedMessageEscapesTheTitle(): void
    {
        $paths = $this->seedPermalinkContent();
        $this->runInSite('$n = \IdnoPlugins\Status\Status::getByID(' . $this->literal(self::BOB_NOTE) . '); $n->body = \'<b id="canary">x</b>\'; $n->save(true); return true;');
        $owner = $this->asViewer('owner', ['Referer' => $this->baseUrl()]);
        $this->site()->request('DELETE', '/' . $paths['bob-public'] . '?__bTk=' . $this->tokenOf('owner'), $owner);
        $next = $this->site()->request('GET', '/', $this->asViewer('owner'));
        $this->assertStringNotContainsString('<b id="canary">', $next->body);
    }
}
