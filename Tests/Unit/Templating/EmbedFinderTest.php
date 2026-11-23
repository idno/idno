<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\EmbedFinder;
use PHPUnit\Framework\TestCase;

final class EmbedFinderTest extends TestCase
{
    public function testTextWithNothingToEmbedFindsNothing(): void
    {
        $this->assertSame([], EmbedFinder::find('Just words, and https://example.org/page'));
        $this->assertSame([], EmbedFinder::find(''));
    }

    public function testEachKindIsFoundWithItsIdentifier(): void
    {
        $this->assertSame([['kind' => 'image', 'id' => 'https://img.example/a.b.png']], EmbedFinder::find('See https://img.example/a.b.png done'));
        $this->assertSame([['kind' => 'archive', 'id' => 'some-item_1']], EmbedFinder::find('https://archive.org/embed/some-item_1'));
        $this->assertSame([['kind' => 'bitchute', 'id' => 'AbC-1_']], EmbedFinder::find('https://www.bitchute.com/video/AbC-1_/'));
        $this->assertSame([['kind' => 'vimeo', 'id' => '123456'], ['kind' => 'vimeo', 'id' => '789']], EmbedFinder::find('https://vimeo.com/123456 and vimeo.com/789'));
        $this->assertSame([['kind' => 'instagram', 'id' => 'AbC123_-']], EmbedFinder::find('https://www.instagram.com/p/AbC123_-/'));
    }

    public function testYouTubeIsFoundInTheOrderOfPreference(): void
    {
        $this->assertSame([['kind' => 'youtube', 'id' => 'abc-1_']], EmbedFinder::find('https://www.youtube.com/watch?v=abc-1_ https://youtu.be/other'), 'a watch address wins over a plain one');
        $this->assertSame([], EmbedFinder::find('https://www.youtube.com/channel/UC123'), 'a channel has no player');
        $this->assertSame([], EmbedFinder::find('https://www.youtube.com/c/Name'));
        $this->assertSame([['kind' => 'youtube-user', 'id' => 'Someone']], EmbedFinder::find('https://www.youtube.com/user/Someone'));
        $this->assertSame([['kind' => 'youtube', 'id' => 'xyz123']], EmbedFinder::find('https://youtu.be/xyz123'));
    }

    public function testAPictureAddressThatIsNotHttpIsNeverFound(): void
    {
        $this->assertSame([], EmbedFinder::find('javascript:alert(1).a.png'));
        $found = EmbedFinder::find('See https://img.example/x"><script>.y.gif');
        $this->assertSame([], $found, 'an address with a quote or a tag in it is not an address');
        $this->assertSame([['kind' => 'image', 'id' => 'https://img.example/a.b.png']], EmbedFinder::find('&lt;https://img.example/a.b.png'), 'HTML entities in the text are decoded first');
    }

    public function testTheFindsComeInTheOrderTheyAreDrawn(): void
    {
        $kinds = array_column(EmbedFinder::find('https://youtu.be/q https://www.instagram.com/p/Q/ https://vimeo.com/1 https://archive.org/embed/x https://img.example/a.b.png'), 'kind');

        $this->assertSame(['image', 'archive', 'youtube', 'vimeo', 'instagram'], $kinds);
    }
}
