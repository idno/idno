<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * An annotation's author may delete it. The author is the member whose own profile address the annotation records as its owner AND whose annotation the
 * site made itself (an annotation's `permalink` is its own key, the address the site invents for what a member writes on it; a webmention's `permalink` is the
 * remote page, and its owner is whatever that page's h-card claimed, which no remote party may use to choose who may delete). The post's editor and an
 * administrator keep their right. Each test starts a site of its own, because the requests change it.
 */
final class AnnotationAuthorDeleteTest extends PermalinkSiteCase
{
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('AnnotationAuthorDeleteTest records no answer.');
    }

    private const NOTE_ID = '0000000000004000800000000000006b';

    /** Seeds, on Bob's annotated note: Carol's own like and reply, Ada's own reply, and a webmention that claims Carol's profile as its author. */
    private function seed(): string
    {
        $paths = $this->seedPermalinkContent();
        $code = <<<'PHP'
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $record = $store->findById('entities', '0000000000004000800000000000006b');
        $base = \Idno\Core\Idno::site()->config()->getURL();
        $carol = \Idno\Entities\User::getByHandle('carol');
        $ada = \Idno\Entities\User::getByHandle('ada');
        $make = static fn (string $permalink, string $ownerUrl, string $content): array => ['permalink' => $permalink, 'owner_name' => 'Someone', 'owner_url' => $ownerUrl, 'owner_image' => '', 'content' => $content, 'time' => (int) strtotime('2026-02-12 10:00:00 UTC'), 'title' => ''];
        $key = static fn (string $name): string => $base . %NOTE% . '/annotations/' . md5($name);
        $record['annotations'] = ['reply' => [], 'like' => [], 'mention' => []];
        $record['annotations']['like'][$key('carol-like')] = $make($key('carol-like'), $carol->getURL(), '');
        $record['annotations']['reply'][$key('carol-reply')] = $make($key('carol-reply'), $carol->getURL(), 'Carol wrote this herself.');
        $record['annotations']['reply'][$key('ada-reply')] = $make($key('ada-reply'), $ada->getURL(), 'Ada wrote this herself.');
        // a webmention: its address is the remote page's, and the author is whatever its h-card said
        $record['annotations']['reply'][$key('claim')] = $make('https://remote.example/post', $carol->getURL(), 'A remote page that says Carol wrote it.');
        $store->save('entities', $record);

        return true;
        PHP;
        $this->assertTrue($this->runInSite(str_replace('%NOTE%', $this->literal(ltrim('/' . $paths['bob-annotated'], '/')), $code)));

        return '/' . $paths['bob-annotated'];
    }

    private function removeAs(string $viewer, string $note, string $name, array $headers = [], string $how = 'permalink'): SiteResponse
    {
        $key = $this->baseUrl() . ltrim($note, '/') . '/annotations/' . md5($name);
        $address = $how === 'permalink' ? $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($key) : $note . '/annotations/' . md5($name) . '/delete';

        return $this->site()->request('POST', $address, $this->asViewer($viewer, $headers), ['__bTk' => $this->tokenOf($viewer)]);
    }

    private function listBody(string $note, string $viewer = 'visitor'): string
    {
        return $this->site()->request('GET', $note . '/annotations/', $this->asViewer($viewer))->body;
    }

    public function testTheAuthorDeletesTheirOwnLikeAndReplyOnSomeoneElsesPost(): void
    {
        $note = $this->seed();
        $this->assertStringContainsString('Carol wrote this herself.', $this->listBody($note));

        $this->assertSame(302, $this->removeAs('member', $note, 'carol-reply')->status);
        $this->assertStringNotContainsString('Carol wrote this herself.', $this->listBody($note), 'the reply is gone');
        $this->assertStringContainsString('Ada wrote this herself.', $this->listBody($note), 'and no other');

        $this->assertSame(302, $this->removeAs('member', $note, 'carol-like', [], 'id')->status, 'by the address of its own id too');
        $this->assertStringNotContainsString('liked this post', $this->listBody($note));
    }

    public function testTheAuthorDeletesNothingOfAThirdMembersAndNothingTheyOnlyClaimed(): void
    {
        $note = $this->seed();
        $this->removeAs('member', $note, 'ada-reply');
        $this->assertStringContainsString('Ada wrote this herself.', $this->listBody($note), 'another member\'s annotation stays');
        $this->removeAs('member', $note, 'claim');
        $this->assertStringContainsString('A remote page that says Carol wrote it.', $this->listBody($note), 'a webmention that names her profile is not hers');
        // by the remote address it came from too
        $by = $this->site()->request('POST', $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode('https://remote.example/post'), $this->asViewer('member'), ['__bTk' => $this->tokenOf('member')]);
        $this->assertSame(302, $by->status);
        $this->assertStringContainsString('A remote page that says Carol wrote it.', $this->listBody($note));
    }

    public function testTheEditorAndAnAdministratorStillRemoveWhatTheyDid(): void
    {
        $note = $this->seed();
        $this->removeAs('owner', $note, 'claim');
        $this->assertStringNotContainsString('A remote page that says Carol wrote it.', $this->listBody($note));
        $this->removeAs('admin', $note, 'ada-reply');
        $this->assertStringNotContainsString('Ada wrote this herself.', $this->listBody($note));
    }

    public function testAVisitorDeletesNothing(): void
    {
        $note = $this->seed();
        $cookie = self::sessionCookie($this->site()->request('GET', '/'));
        $key = $this->baseUrl() . ltrim($note, '/') . '/annotations/' . md5('carol-reply');
        $answer = $this->site()->request('POST', $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($key), ['Cookie' => $cookie], ['__bTk' => $this->tokenWith(['Cookie' => $cookie])]);
        $this->assertSame(403, $answer->status);
        $this->assertStringContainsString('Carol wrote this herself.', $this->listBody($note));
    }

    public function testASignedRequestOfTheAuthorDeletesAsTheAuthorDoes(): void
    {
        $note = $this->seed();
        $key = $this->baseUrl() . ltrim($note, '/') . '/annotations/' . md5('carol-reply');
        $address = $note . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($key);
        $answer = $this->site()->request('POST', $address, self::signedHeadersFor('carol', $this->storedKeyOf('carol'), $address));
        $this->assertSame(200, $answer->status, 'the JSON that says where to go');
        $this->assertStringNotContainsString('Carol wrote this herself.', $this->listBody($note), 'a signed request acts as the member it is signed for, as for the post\'s owner');
    }

    public function testAnXhrRequestOfTheAuthorDeletes(): void
    {
        $note = $this->seed();
        $this->removeAs('member', $note, 'carol-reply', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertStringNotContainsString('Carol wrote this herself.', $this->listBody($note));
    }

    public function testAPostTheAuthorMayNoLongerReadIsAMissingPostAndNothingIsRemoved(): void
    {
        $note = $this->seed();
        $this->assertTrue($this->runInSite("\$store = \\Idno\\Core\\Idno::container()->get(\\Idno\\Storage\\RecordStore::class); \$r = \$store->findById('entities', '" . self::NOTE_ID . "'); \$r['access'] = \\Idno\\Entities\\User::getByHandle('bob')->getUUID(); \$store->save('entities', \$r); return true;"));
        $this->assertSame(410, $this->removeAs('member', $note, 'carol-reply')->status);
        $this->assertStringContainsString('Carol wrote this herself.', $this->listBody($note, 'owner'));
    }

    /** The link is drawn exactly where it works. */
    public function testTheDeleteLinkIsDrawnOnlyForThoseWhoCanUseIt(): void
    {
        $note = $this->seed();
        $links = static fn (string $body): int => substr_count($body, '>Delete</a>');
        $this->assertSame(0, $links($this->listBody($note)), 'a visitor');
        $this->assertSame(2, $links($this->listBody($note, 'member')), 'Carol: her own like and reply, not Ada\'s or the claim');
        $this->assertSame(4, $links($this->listBody($note, 'owner')), 'the post\'s owner: every annotation');
        $this->assertSame(4, $links($this->listBody($note, 'admin')));
    }
}
