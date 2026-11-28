<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Idno\Core\Tags;
use PHPUnit\Framework\TestCase;

final class TagsTest extends TestCase
{
    /** A tag is stored as the plain text that was sent: markup is kept as text (and escaped where it is printed), `<3` and joined emoji survive, control characters go. */
    public function testATagIsStoredAsPlainText(): void
    {
        $this->assertSame('<script>x</script>', Tags::clean('<script>x</script>'));
        $this->assertSame('I <3 cats', Tags::clean('I <3 cats'));
        $this->assertSame("\u{1F469}\u{200D}\u{1F4BB}", Tags::clean("\u{1F469}\u{200D}\u{1F4BB}"));
        $this->assertSame('a b', Tags::clean("a\n\t b\x00"));
        $this->assertSame(['one', 'two'], Tags::clean(['one', ' two ', 'two', '  ', ['nested'], null]));
        $this->assertSame('C++ & #tag', Tags::clean('C++ & #tag'));
        $this->assertNull(Tags::clean(new \stdClass()));
    }

    /** A tag's page address is the tag, encoded, after the site's address. */
    public function testATagsAddressIsEncoded(): void
    {
        $this->assertSame('https://site.example/tag/%22%3E%3Cb%3E', Tags::url('"><b>', 'https://site.example/'));
    }

    /** A tag sent as a list of lists must not break the post's page ("Array to string conversion"). */
    public function testATagSentAsAListIsText(): void
    {
        $this->assertSame(['a', 'b'], Tags::texts(['a', ['x'], 'b', null, '']));
        $this->assertSame(['#one #two'], Tags::texts(' #one #two '));
        $this->assertSame([], Tags::texts(['x' => ['y']]));
    }

    /** A long tag is cut, so one request cannot fill a page with it. */
    public function testATagIsCutToItsLimit(): void
    {
        $this->assertSame(Tags::MAX_LENGTH, mb_strlen((string) Tags::clean(str_repeat('é', 1000))));
        $this->assertCount(Tags::MAX_TAGS, Tags::clean(array_map('strval', range(1, 500))));
    }

    /** A tag cannot carry a bidirectional override or isolate that would flip how the rest of the tag row reads; joined emoji keep their joiner. */
    public function testBidirectionalControlsAreRemovedWhenStoredAndWhenRead(): void
    {
        $tag = "cats\u{202E}gnp.exe\u{2066}x\u{2069}\u{202A}\u{202C}";

        $this->assertSame('catsgnp.exex', Tags::clean($tag));
        $this->assertSame(['catsgnp.exex'], Tags::texts([$tag]));
        $this->assertSame("\u{1F469}\u{200D}\u{1F4BB}", Tags::clean("\u{1F469}\u{200D}\u{1F4BB}"));
        $this->assertSame(["\u{1F469}\u{200D}\u{1F4BB}"], Tags::texts("\u{1F469}\u{200D}\u{1F4BB}"));
    }
}
