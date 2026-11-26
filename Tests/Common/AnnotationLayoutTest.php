<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Core\Idno;
use IdnoPlugins\Status\Status;

/**
 * A post's address has one layout (`/YYYY/<slug>`). An annotation a site made itself, while the post had an address in another layout (`/YYYY/MM/<slug>`,
 * `/YYYY/MM/DD/<slug>`), holds that address as its key and as its `permalink`. What the post prints of it is the address it has now, and the annotation is
 * still found and removed by either.
 */
final class AnnotationLayoutTest extends \Tests\IdnoTestCase
{
    private function post(): Status
    {
        $post = new Status();
        $post->_id = '00000000000040008000000000000a01';
        $post->slug = 'a-note';
        $post->created = (int) strtotime('2026-03-14 12:00:00 UTC');

        return $post;
    }

    private function base(): string
    {
        return Idno::site()->config()->getURL();
    }

    /**
     * @return array<string, array{string, string}> address => [the address as the post has it now, why]
     */
    public static function addresses(): array
    {
        return [
            'month layout' => ['2026/03/a-note/annotations/abc123', '2026/a-note/annotations/abc123'],
            'day layout' => ['2026/03/14/a-note/annotations/abc123', '2026/a-note/annotations/abc123'],
            'another year' => ['2024/11/a-note/annotations/abc123', '2026/a-note/annotations/abc123'],
            'the address it has now' => ['2026/a-note/annotations/abc123', '2026/a-note/annotations/abc123'],
            'another post' => ['2026/03/another-note/annotations/abc123', '2026/03/another-note/annotations/abc123'],
            'not an annotation' => ['2026/03/a-note', '2026/03/a-note'],
            'a dotted last part' => ['2026/03/a-note/annotations/abc.123', '2026/03/a-note/annotations/abc.123'],
            'a longer address' => ['2026/03/a-note/annotations/abc123/more', '2026/03/a-note/annotations/abc123/more'],
        ];
    }

    /**
     * @dataProvider addresses
     */
    public function testAnAnnotationsOwnAddressIsTheOneThePostHasNow(string $stored, string $now): void
    {
        $post = $this->post();

        $this->assertSame($this->base() . $now, $post->canonicalAnnotationAddress($this->base() . $stored));
    }

    public function testAnotherSitesAddressIsLeftAsItWas(): void
    {
        $post = $this->post();

        foreach (['https://example.org/2026/03/a-note/annotations/abc123', 'http://other.example/2026/a-note/annotations/abc', '', 'not an address'] as $address) {
            $this->assertSame($address, $post->canonicalAnnotationAddress($address), $address);
        }
    }

    public function testThePostShowsItsAnnotationsAtTheAddressesItHasNowAndKeepsTheirKeys(): void
    {
        $post = $this->post();
        $old = $this->base() . '2026/03/a-note/annotations/abc123';
        $remote = 'https://example.org/reply/1';
        $post->annotations = ['like' => [
            $old => ['permalink' => $old, 'owner_name' => 'Ada', 'owner_url' => 'https://example.org/ada', 'owner_image' => '', 'content' => '', 'time' => 1, 'title' => ''],
            $this->base() . '2026/a-note/annotations/def456' => ['permalink' => $remote, 'owner_name' => 'Bob', 'owner_url' => 'https://example.org/bob', 'owner_image' => '', 'content' => '', 'time' => 2, 'title' => ''],
        ]];

        $shown = $post->getAnnotations('like');

        $this->assertSame([$old, $this->base() . '2026/a-note/annotations/def456'], array_keys($shown), 'the keys are what the post stores them under');
        $this->assertSame($this->base() . '2026/a-note/annotations/abc123', $shown[$old]['permalink']);
        $this->assertSame($remote, $shown[$this->base() . '2026/a-note/annotations/def456']['permalink'], 'a remote address is not touched');
        $this->assertSame($old, $post->annotations['like'][$old]['permalink'], 'what is stored is not changed by showing it');
    }

    public function testAnAnnotationIsRemovedByTheAddressItIsShownAt(): void
    {
        $post = $this->post();
        $old = $this->base() . '2026/03/a-note/annotations/abc123';
        $post->annotations = ['like' => [$old => ['permalink' => $old, 'owner_name' => 'Ada', 'owner_url' => 'https://example.org/ada', 'owner_image' => '', 'content' => '', 'time' => 1, 'title' => '']]];

        // the key, as the delete link posts it
        $byKey = clone $post;
        $this->assertTrue($byKey->removeAnnotation($old));
        $this->assertSame([], $byKey->annotations['like']);

        // the address the post prints, as the like button's removal reads it from the annotation it was shown
        $byShownAddress = clone $post;
        $this->assertTrue($byShownAddress->removeAnnotation($this->base() . '2026/a-note/annotations/abc123'));
        $this->assertSame([], $byShownAddress->annotations['like']);

        $this->assertFalse((clone $post)->removeAnnotation($this->base() . '2026/a-note/annotations/other'), 'an address that names no annotation removes none');
        $this->assertFalse((clone $post)->removeAnnotation($this->base() . '2026/another/annotations/abc123'));
    }

    public function testAnAnnotationIsFoundByTheKeyOfThisPostAlone(): void
    {
        $post = $this->post();
        $old = $this->base() . '2026/03/a-note/annotations/abc123';
        $foreign = $this->base() . '2026/03/another-note/annotations/def456';
        $post->annotations = ['like' => [
            $old => ['permalink' => $old, 'owner_name' => 'Ada', 'owner_url' => 'https://example.org/ada', 'owner_image' => '', 'content' => '', 'time' => 1, 'title' => ''],
            $foreign => ['permalink' => $foreign, 'owner_name' => 'Bob', 'owner_url' => 'https://example.org/bob', 'owner_image' => '', 'content' => '', 'time' => 2, 'title' => ''],
        ]];
        $annotations = new \Idno\Legacy\LegacyAnnotations();

        $this->assertSame($old, $annotations->keyOf($post, 'abc123'));
        $this->assertNull($annotations->keyOf($post, 'def456'), 'a key of another post\'s address is not this post\'s annotation');
        $this->assertNull($annotations->keyOf($post, 'nothing'));
    }

    public function testTheAuthorIsTheMemberTheSiteRecordedAsHavingMadeTheAnnotationHere(): void
    {
        $member = $this->swapUser($this->admin());
        $mine = $member instanceof \Idno\Entities\User ? $member : \Idno\Core\Idno::site()->session()->currentUser();
        $post = $this->post();
        $key = $this->base() . '2026/03/a-note/annotations/own1';
        $remote = $this->base() . '2026/a-note/annotations/own2';
        $make = static fn (string $permalink, string $owner): array => ['permalink' => $permalink, 'owner_name' => 'X', 'owner_url' => $owner, 'owner_image' => '', 'content' => '', 'time' => 1, 'title' => ''];
        $post->annotations = ['reply' => [
            $key => $make($key, $mine->getURL()),
            $remote => $make('https://remote.example/claim', $mine->getURL()),
            $this->base() . '2026/a-note/annotations/own3' => $make($this->base() . '2026/a-note/annotations/own3', 'https://example.org/someone'),
        ]];

        $this->assertTrue($post->isAnnotationAuthor($key), 'made here, by this member');
        $this->assertTrue($post->isAnnotationAuthor($this->base() . '2026/a-note/annotations/own1'), 'by the address it is shown at');
        $this->assertTrue($post->isAnnotationAuthor(['permalink' => $this->base() . '2026/a-note/annotations/own1']), 'by the annotation as a template holds it');
        $this->assertFalse($post->isAnnotationAuthor($remote), 'a webmention that names the member\'s profile is not theirs');
        $this->assertFalse($post->isAnnotationAuthor(['permalink' => 'https://remote.example/claim']));
        $this->assertFalse($post->isAnnotationAuthor($this->base() . '2026/a-note/annotations/own3'), 'another member\'s');
        $this->assertFalse($post->isAnnotationAuthor(''));
        $this->assertFalse($post->isAnnotationAuthor('https://remote.example/nothing'));
    }

    public function testTheMarkDecidesWhereItIsPresentAndTheOldInferenceWhereItIsNot(): void
    {
        $mine = $this->swapUser($this->admin());
        $mine = \Idno\Core\Idno::site()->session()->currentUser();
        $post = $this->post();
        $base = $this->base() . '2026/a-note/annotations/';
        $make = fn (string $key, array $more): array => ['permalink' => $key, 'owner_name' => 'X', 'owner_url' => $mine->getURL(), 'owner_image' => '', 'content' => '', 'time' => 1, 'title' => ''] + $more;
        $post->annotations = ['reply' => [
            $base . 'marked' => $make($base . 'marked', ['made_here' => true]),
            $base . 'unmarked-forged' => $make($base . 'unmarked-forged', ['made_here' => false]),
            $base . 'old' => $make($base . 'old', []),
            $base . 'old-remote' => $make('https://remote.example/x', []),
        ]];

        $this->assertSame($base . 'marked', $post->authoredAnnotationKey($base . 'marked'));
        $this->assertNull($post->authoredAnnotationKey($base . 'unmarked-forged'), 'marked as not made here: the address is no proof');
        $this->assertSame($base . 'old', $post->authoredAnnotationKey($base . 'old'), 'stored before the mark: the old inference');
        $this->assertNull($post->authoredAnnotationKey($base . 'old-remote'));
        $this->assertTrue($post->removeAnnotationByKey($base . 'marked'));
        $this->assertArrayNotHasKey($base . 'marked', $post->annotations['reply']);
        $this->assertCount(3, $post->annotations['reply'], 'exactly that key');
        $this->assertFalse($post->removeAnnotationByKey($base . 'none'));
    }

    public function testAnAnnotationMadeHereIsMarkedAndAWebmentionsFieldsCannotMarkIt(): void
    {
        $this->swapUser($this->admin());
        $post = $this->post();
        $this->assertTrue($post->addAnnotation('reply', 'A', 'https://example.org/a', '', 'Made here', null, 1, '', ['made_here' => true], false, true));
        $this->assertTrue($post->addAnnotation('reply', 'B', 'https://example.org/b', '', 'Received', 'https://remote.example/b', 2, '', ['made_here' => true], false, false));
        $flags = array_column(array_values($post->annotations['reply']), 'made_here', 'content');

        $post->delete();

        $this->assertSame(['Made here' => true, 'Received' => false], $flags);
    }
}
