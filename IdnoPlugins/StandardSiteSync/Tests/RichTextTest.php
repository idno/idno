<?php

namespace IdnoPlugins\StandardSiteSync\Tests;

use IdnoPlugins\StandardSiteSync\RichText;
use IdnoPlugins\StandardSiteSync\Tid;

/**
 * The text of a Bluesky post and its facets. Facets name BYTES of the UTF-8 text, and
 * a post holds 300 graphemes: both are easy to get wrong with emoji, combining
 * characters and scripts whose letters take several bytes.
 */
class RichTextTest extends \PHPUnit\Framework\TestCase
{

    /// A family: seven code points, 25 bytes, one grapheme
    const FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";

    /// A flag: two code points, 8 bytes, one grapheme
    const FLAG = "\u{1F1F3}\u{1F1F1}";

    /// "e" and a combining acute accent: two code points, 3 bytes, one grapheme
    const E_ACUTE = "e\u{0301}";

    /**
     * What a facet covers, read the way a reader of the record does: by bytes.
     */
    private function covered(string $text, array $facet): string
    {
        return substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);
    }

    public function testGraphemesAreCountedAsAReaderSeesThem()
    {
        $this->assertSame(1, RichText::length(self::FAMILY));
        $this->assertSame(1, RichText::length(self::FLAG));
        $this->assertSame(1, RichText::length(self::E_ACUTE));
        $this->assertSame(4, RichText::length('日本語!'));
        $this->assertSame(0, RichText::length(''));
        $this->assertSame(5, RichText::length('a' . self::FAMILY . 'b' . self::FLAG . self::E_ACUTE));
    }

    public function testALinkAfterEmojiIsIndexedInBytes()
    {
        $text = self::FAMILY . ' see https://example.com/a?b=c.';
        $facets = RichText::facets($text);

        $this->assertCount(1, $facets);
        $this->assertSame(25 + 5, $facets[0]['index']['byteStart']);
        $this->assertSame('https://example.com/a?b=c', $this->covered($text, $facets[0]));
        $this->assertSame([['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a?b=c']], $facets[0]['features']);
    }

    public function testFacetsInNonLatinTextAndAfterCombiningCharacters()
    {
        $text = 'Cafe' . "\u{0301}" . ' を見て https://example.jp/パス #日本語 と #Äpfel';
        $facets = RichText::facets($text);

        $this->assertCount(3, $facets);
        $this->assertSame('https://example.jp/パス', $this->covered($text, $facets[0]));
        $this->assertSame('#日本語', $this->covered($text, $facets[1]));
        $this->assertSame('日本語', $facets[1]['features'][0]['tag']);
        $this->assertSame('#Äpfel', $this->covered($text, $facets[2]));
        $this->assertSame('Äpfel', $facets[2]['features'][0]['tag']);

        foreach ($facets as $facet) {
            // Every range is a whole number of characters
            $this->assertTrue(mb_check_encoding($this->covered($text, $facet), 'UTF-8'));
            $this->assertTrue(mb_check_encoding(substr($text, 0, $facet['index']['byteStart']), 'UTF-8'));
        }
    }

    public function testArabicAndCyrillicHashtags()
    {
        $text = 'مرحبا #صحافة و #Новости 123';
        $facets = RichText::facets($text);

        $this->assertSame(['#صحافة', '#Новости'], array_map(function ($facet) use ($text) {
            return $this->covered($text, $facet);
        }, $facets));
    }

    public function testWhatIsNotAHashtag()
    {
        foreach (['Issue #123', 'a#b', 'https://example.com/#top', 'x &#39; y', '# alone', '##'] as $text) {
            $tags = array_filter(RichText::facets($text), function ($facet) {
                return $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#tag';
            });
            $this->assertSame([], $tags, $text);
        }
    }

    public function testAMentionIsAFacetOnlyWhenTheHandleHasADid()
    {
        $text = self::FLAG . ' thanks @Alice.Example.com and @bob.example.org, not me@mail.example.com';
        $this->assertSame(['alice.example.com', 'bob.example.org'], RichText::handles($text));

        $facets = RichText::facets($text, [], ['alice.example.com' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz']);

        $this->assertCount(1, $facets);
        $this->assertSame('@Alice.Example.com', $this->covered($text, $facets[0]));
        $this->assertSame([['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz']], $facets[0]['features']);
    }

    public function testAMentionWithSomethingThatIsNotADidIsNotAFacet()
    {
        $this->assertSame([], RichText::facets('hi @alice.example.com', [], ['alice.example.com' => 'https://example.com/']));
    }

    public function testPunctuationAroundAnAddressIsNotPartOfIt()
    {
        $text = 'Read (https://en.wikipedia.org/wiki/Idno_(software)), then https://example.com/x!';
        $facets = RichText::facets($text);

        $this->assertSame('https://en.wikipedia.org/wiki/Idno_(software)', $facets[0]['features'][0]['uri']);
        $this->assertSame('https://example.com/x', $facets[1]['features'][0]['uri']);
    }

    public function testFacetsDoNotOverlapAndAreInOrder()
    {
        $text = '#one https://example.com/#two @carol.example.com #three';
        $facets = RichText::facets($text, [], ['carol.example.com' => 'did:web:carol.example.com']);

        $end = 0;
        foreach ($facets as $facet) {
            $this->assertGreaterThanOrEqual($end, $facet['index']['byteStart']);
            $end = $facet['index']['byteEnd'];
        }
        $this->assertSame(['#one', 'https://example.com/#two', '@carol.example.com', '#three'], array_map(function ($facet) use ($text) {
            return $this->covered($text, $facet);
        }, $facets));
    }

    public function testHtmlBecomesTextWithItsLinks()
    {
        $html = '<p>Zo&euml; ' . self::FAMILY . ' wrote <a href="https://example.com/story">the <em>story</em></a>.</p>'
            . "\n" . '<p>Second<br>line &amp; <a href="javascript:alert(1)">no</a> <script>alert(2)</script></p>';
        $parsed = RichText::fromHtml($html);

        $this->assertSame('Zoë ' . self::FAMILY . " wrote the story.\n\nSecond\nline & no", $parsed['text']);
        $this->assertCount(1, $parsed['links']);
        $this->assertSame('the story', substr($parsed['text'], $parsed['links'][0]['start'], $parsed['links'][0]['end'] - $parsed['links'][0]['start']));
        $this->assertSame('https://example.com/story', $parsed['links'][0]['uri']);

        $facets = RichText::facets($parsed['text'], $parsed['links']);
        $this->assertSame('the story', $this->covered($parsed['text'], $facets[0]));
    }

    public function testALinkWhoseTextIsAnAddressIsOneFacet()
    {
        $parsed = RichText::fromHtml('<p>See <a href="https://example.com/long/address?x=1">https://example.com/long…</a></p>');
        $facets = RichText::facets($parsed['text'], $parsed['links']);

        $this->assertCount(1, $facets);
        $this->assertSame('https://example.com/long/address?x=1', $facets[0]['features'][0]['uri']);
    }

    public function testPlainTextKeepsItsAngleBracketsAndLines()
    {
        $parsed = RichText::fromText("1 < 2 &amp; 3 > 2\r\n\r\n\r\n\r\nnext");

        $this->assertSame("1 < 2 & 3 > 2\n\nnext", $parsed['text']);
    }

    public function testTextThatFitsIsNotChanged()
    {
        $text = str_repeat(self::FAMILY, 100);
        $this->assertSame(100, RichText::length($text));

        $fitted = RichText::fit($text, [], 'https://example.com/p/1');

        $this->assertFalse($fitted['truncated']);
        $this->assertSame($text, $fitted['text']);
    }

    public function testLongTextIsCutBetweenGraphemesAndLinksBack()
    {
        $permalink = 'https://example.com/2026/a-post';
        // No spaces: the cut cannot fall back on the end of a word
        $text = str_repeat(self::E_ACUTE . self::FLAG, 400);

        $fitted = RichText::fit($text, [], $permalink);

        $this->assertTrue($fitted['truncated']);
        $this->assertLessThanOrEqual(300, LexiconValidator::graphemes($fitted['text']));
        $this->assertLessThanOrEqual(3000, strlen($fitted['text']));
        $this->assertTrue(mb_check_encoding($fitted['text'], 'UTF-8'));

        $this->assertStringEndsWith('… example.com/2026/a-post', $fitted['text']);
        $kept = substr($fitted['text'], 0, -strlen('… example.com/2026/a-post'));
        // What is kept is whole graphemes: no half flag, and no "e" without its accent
        $this->assertMatchesRegularExpression('/\A(?:e\x{0301}\x{1F1F3}\x{1F1F1})+(?:e\x{0301})?\z/u', $kept);
        $this->assertSame(300 - LexiconValidator::graphemes('… example.com/2026/a-post'), LexiconValidator::graphemes($kept));

        $link = end($fitted['facets']);
        $this->assertSame('example.com/2026/a-post', $this->covered($fitted['text'], $link));
        $this->assertSame($permalink, $link['features'][0]['uri']);
    }

    public function testTextOfHeavyGraphemesIsCutByBytesToo()
    {
        // 299 graphemes, but 7475 bytes
        $text = str_repeat(self::FAMILY, 299);

        $fitted = RichText::fit($text, [], 'https://example.com/p');

        $this->assertTrue($fitted['truncated']);
        $this->assertLessThanOrEqual(3000, strlen($fitted['text']));
        $kept = substr($fitted['text'], 0, -strlen('… example.com/p'));
        $this->assertSame(0, strlen($kept) % 25);
    }

    public function testLongTextIsCutAtTheEndOfAWord()
    {
        $text = trim(str_repeat('журналистика ', 60));

        $fitted = RichText::fit($text, [], 'https://example.com/p');

        $this->assertStringEndsWith('журналистика… example.com/p', $fitted['text']);
        $this->assertLessThanOrEqual(300, LexiconValidator::graphemes($fitted['text']));
    }

    public function testTheCutNeverFallsInsideAFacet()
    {
        $address = 'https://example.com/' . str_repeat('a', 60);
        $text = str_repeat('x', 240) . ' ' . $address . ' ' . str_repeat('y', 100);
        $facets = RichText::facets($text);
        $this->assertCount(1, $facets);

        $fitted = RichText::fit($text, $facets, 'https://example.com/p');

        $this->assertStringNotContainsString('https://example.com/aaa', $fitted['text']);
        $this->assertCount(1, $fitted['facets']);
        $this->assertSame('https://example.com/p', $fitted['facets'][0]['features'][0]['uri']);
        foreach ($fitted['facets'] as $facet) {
            $this->assertLessThanOrEqual(strlen($fitted['text']), $facet['index']['byteEnd']);
        }
    }

    public function testFacetsBeforeTheCutAreKept()
    {
        $text = '#news ' . str_repeat('word ', 100);
        $fitted = RichText::fit($text, RichText::facets($text), 'https://example.com/p');

        $this->assertSame('#news', $this->covered($fitted['text'], $fitted['facets'][0]));
        $this->assertCount(2, $fitted['facets']);
    }

    public function testTruncateEndsWithAnEllipsisAndKeepsWholeGraphemes()
    {
        $this->assertSame('short', RichText::truncate(' short ', 10));

        $cut = RichText::truncate(str_repeat(self::FLAG, 20), 10);
        $this->assertSame(str_repeat(self::FLAG, 9) . '…', $cut);
    }

    public function testInvalidUtf8IsMadeValid()
    {
        $parsed = RichText::fromText("ok \xC3\x28 \xF0\x9F text");

        $this->assertTrue(mb_check_encoding($parsed['text'], 'UTF-8'));
        $this->assertStringStartsWith('ok ', $parsed['text']);
    }

    public function testATidIsThirteenCharactersAndStable()
    {
        $tid = Tid::forEntity(1795564800, '6741f0a2c3d4e5f601234567');

        $this->assertTrue(Tid::isValid($tid));
        $this->assertSame($tid, Tid::forEntity(1795564800, '6741f0a2c3d4e5f601234567'));
        $this->assertNotSame($tid, Tid::forEntity(1795564800, '6741f0a2c3d4e5f601234568'));
        $this->assertSame(1795564800, intdiv(Tid::microseconds($tid), 1000000));
    }

    public function testTidsSortByTime()
    {
        $earlier = Tid::forEntity(1700000000, 'b');
        $later = Tid::forEntity(1700000001, 'a');

        $this->assertLessThan(0, strcmp($earlier, $later));
        $this->assertSame('2222222222222', Tid::fromMicroseconds(0, 0));
        $this->assertFalse(Tid::isValid('3jzfcijpj2z2aa'));
        $this->assertFalse(Tid::isValid('zzzzzzzzzzzzz'));
        $this->assertFalse(Tid::isValid('6741f0a2c3d4e5f601234567'));
    }
}
