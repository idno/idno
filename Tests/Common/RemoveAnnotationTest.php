<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Common\Entity;
use IdnoPlugins\Status\Status;

/**
 * An annotation is removed by the key it is stored under. Its address is another way to name it only when it names one: two annotations that share a `permalink`
 * are never told apart by it, so naming it removes neither. And two annotations of one kind made in one second with the same words are two.
 */
final class RemoveAnnotationTest extends \Tests\IdnoTestCase
{
    private ?Status $post = null;

    public function setUp(): void
    {
        $this->swapUser($this->admin());
        $this->post = new Status();
        $this->post->body = 'A post with annotations';
        $this->post->setAccess('PUBLIC');
        $this->post->save();
    }

    public function tearDown(): void
    {
        $this->post?->delete();
        $this->post = null;
    }

    private function annotation(string $words): array
    {
        return ['permalink' => 'https://remote.example/shared', 'owner_name' => 'A', 'owner_url' => 'https://remote.example/', 'owner_image' => '', 'content' => $words, 'time' => 1, 'title' => '', 'made_here' => false];
    }

    public function testTwoAnnotationsThatShareAPermalinkAreNotToldApartByIt(): void
    {
        $base = $this->post->getURL() . '/annotations/';
        $this->post->annotations = ['reply' => [$base . 'one' => $this->annotation('first'), $base . 'two' => $this->annotation('second')]];

        $this->assertFalse($this->post->removeAnnotation('https://remote.example/shared'), 'the address names two: it removes neither');
        $this->assertCount(2, $this->post->annotations['reply']);

        $this->assertTrue($this->post->removeAnnotation($base . 'one'), 'the key names one');
        $this->assertSame([$base . 'two'], array_keys($this->post->annotations['reply']));
    }

    public function testAnAddressThatNamesOneAnnotationStillRemovesIt(): void
    {
        $base = $this->post->getURL() . '/annotations/';
        $this->post->annotations = ['reply' => [$base . 'one' => $this->annotation('first')]];

        $this->assertTrue($this->post->removeAnnotation('https://remote.example/shared'));
        $this->assertSame([], $this->post->annotations['reply']);
    }

    public function testTwoLikesWithTheSameWordsInOneSecondAreTwoLikes(): void
    {
        $post = Entity::getByID($this->post->getID());

        $post->addAnnotation('like', 'A', 'https://example.org/a', '', '', null, null, '', [], true, true);
        $post->addAnnotation('like', 'B', 'https://example.org/b', '', '', null, null, '', [], true, true);

        $this->assertCount(2, Entity::getByID($this->post->getID())->annotations['like']);
    }

    public function testAnnotationsOfTwoKindsWithTheSameWordsInOneSecondNeverShareAKey(): void
    {
        $post = Entity::getByID($this->post->getID());

        $post->addAnnotation('like', 'A', 'https://example.org/a', '', '', null, null, '', [], true, true);
        $post->addAnnotation('share', 'B', 'https://example.org/b', '', '', null, null, '', [], true, true);

        $likeKey = array_key_first($post->annotations['like']);
        $shareKey = array_key_first($post->annotations['share']);
        $this->assertNotSame($likeKey, $shareKey, 'a key names one annotation among all the kinds: unliking must never remove a share');
        $this->assertTrue($post->removeAnnotationByKey($likeKey));
        $this->assertCount(1, $post->annotations['share']);
    }
}
