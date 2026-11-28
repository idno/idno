<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Idno\Core\Tags;
use PHPUnit\Framework\TestCase;

final class TagsTest extends TestCase
{
    /** A tag sent with markup is stored as text, so no feed reader or other server is handed an element. */
    public function testMarkupInATagIsTextWhenStored(): void
    {
        $this->assertSame('x', Tags::clean('<script>x</script>'));
        $this->assertSame('a b', Tags::clean("a\n\t b"));
        $this->assertSame(['one', 'two'], Tags::clean(['<b>one</b>', 'two', 'two', '  ', ['nested']]));
        $this->assertStringNotContainsString('<', (string) Tags::clean('"><x-canary a=\'1'));
        $this->assertNull(Tags::clean(new \stdClass()));
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
}
