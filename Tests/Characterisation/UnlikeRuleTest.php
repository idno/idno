<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The like toggle of the post page (a second like is an unlike) removes a like by the rule that removes any annotation as its author: the like must be the member's own
 * and made on this site (it carries the mark `made_here`), and exactly that annotation is removed. A like a remote page sent as a webmention, whose h-card names the
 * member's profile, is not the member's: the toggle leaves it and adds the member's own like, as if they had none. The mention goes through the real endpoint (the source is
 * a page the test stores, fetched from a stand-in address).
 */
final class UnlikeRuleTest extends PermalinkSiteCase
{
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('UnlikeRuleTest records no answer.');
    }

    private const NOTE_ID = '00000000000040008000000000000041';

    protected function siteEnvironment(): array
    {
        return parent::siteEnvironment() + ['IDNO_TEST_REMOTE_STAND_IN' => '93.184.216.34:80'];
    }

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    private function uuid(): string
    {
        return (string) $this->runInSite('return \Idno\Common\Entity::getByID(' . $this->literal(self::NOTE_ID) . ')->getUUID();');
    }

    /** @return array<string, array<string, mixed>> the post's likes, by key */
    private function likes(): array
    {
        $annotations = $this->runInSite('return \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById(\'entities\', ' . $this->literal(self::NOTE_ID) . ')[\'annotations\'] ?? [];');

        return is_array($annotations) ? ($annotations['like'] ?? []) : [];
    }

    /** @return list<string> the owner addresses of the likes, in order */
    private function owners(): array
    {
        return array_values(array_map(static fn (array $like): string => (string) $like['owner_url'], $this->likes()));
    }

    private function toggle(string $viewer, array $headers = []): SiteResponse
    {
        $headers = $this->asViewer($viewer, $headers);
        $token = $viewer === 'visitor' ? str_repeat('0', 64) : $this->tokenOf($viewer);

        return $this->site()->request('POST', 'annotation/post/', $headers, ['__bTk' => $token, 'object' => $this->uuid(), 'type' => 'like']);
    }

    private function profile(string $handle): string
    {
        return (string) $this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getURL();');
    }

    private function webmentionLikeNaming(string $profile): void
    {
        $this->seedPermalinkContent();
        $target = (string) $this->runInSite('return \Idno\Common\Entity::getByID(' . $this->literal(self::NOTE_ID) . ')->getURL();');
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="http://93.184.216.34/forged-like">permalink</a><div class="p-author h-card"><a class="p-name u-url" href="' . $profile . '">Carol</a></div>'
            . '<a class="u-like-of" href="' . $target . '">liked</a></div></body></html>';
        $this->assertSame(204, $this->site()->request('PUT', '_test/remote/forged-like', ['Content-Type' => 'text/html'], $html)->status);
        $this->assertSame(202, $this->site()->request('POST', 'webmention/', [], ['source' => 'http://93.184.216.34/forged-like', 'target' => $target])->status);
    }

    public function testAMembersOwnLikeMadeOnTheSiteIsRemovedByTheToggle(): void
    {
        $this->seedPermalinkContent();
        $carol = $this->profile('carol');

        $this->assertSame(302, $this->toggle('member')->status);
        $this->assertSame([$carol], $this->owners());
        $this->assertTrue(array_values($this->likes())[0]['made_here']);

        $this->assertSame(302, $this->toggle('member')->status);
        $this->assertSame([], $this->owners(), 'the second like is an unlike');
    }

    public function testALikeAWebmentionSentInTheMembersNameIsNotTheirsAndTheToggleAddsTheirOwn(): void
    {
        $this->seedPermalinkContent();
        $carol = $this->profile('carol');
        $this->webmentionLikeNaming($carol);
        $this->assertSame([''], $this->owners(), 'a remote page cannot show a member as the author');
        $this->assertFalse(array_values($this->likes())[0]['made_here'], 'it was made elsewhere');

        $this->toggle('member');

        $likes = $this->likes();
        $this->assertCount(2, $likes, 'the webmention\'s like stays and the member\'s own is added, as if they had none');
        $this->assertSame([false, true], array_map(static fn (array $like): bool => (bool) $like['made_here'], array_values($likes)));

        $this->toggle('member');
        $left = $this->likes();
        $this->assertCount(1, $left, 'now the toggle removes the member\'s own, and only that');
        $this->assertFalse(array_values($left)[0]['made_here']);
        $this->assertSame('http://93.184.216.34/forged-like', array_values($left)[0]['permalink']);
    }

    public function testAnotherMembersLikeIsUntouched(): void
    {
        $this->seedPermalinkContent();
        $this->toggle('admin');
        $ada = $this->profile('ada');
        $this->assertSame([$ada], $this->owners());

        $this->toggle('member');
        $this->assertCount(2, $this->likes());
        $this->toggle('member');

        $this->assertSame([$ada], $this->owners(), 'only the member\'s own like was removed');
    }

    public function testAVisitorsTogglePersistsNothing(): void
    {
        $this->seedPermalinkContent();
        $this->toggle('admin');
        $before = $this->likes();

        $this->toggle('visitor');

        $this->assertSame($before, $this->likes());
    }

    public function testTheTogglesXhrAnswerCountsTheLikesAsTheBundledScriptAsksIt(): void
    {
        $this->seedPermalinkContent();
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];

        $liked = $this->toggle('member', $xhr);
        $this->assertSame(1, count($this->likes()));
        $this->assertStringContainsString('1 star', $liked->body);

        $unliked = $this->toggle('member', $xhr);
        $this->assertSame(0, count($this->likes()));
        $this->assertStringContainsString('0 stars', $unliked->body);
    }
}
