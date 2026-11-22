<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Core\Language;
use Idno\Storage\QuoteNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The search text of every record is passed through this function, so what it returns for a given input
 * is stored data: the expectations are literals, and Language::uncurlQuotes() is held to the same ones.
 */
final class QuoteNormaliserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'plain text' => ['Hello world', 'Hello world'];
        yield 'empty' => ['', ''];
        yield 'curly double' => ["\u{201C}Hello\u{201D}", '"Hello"'];
        yield 'curly single' => ["\u{2018}it\u{2019}s\u{2019}", "'it's'"];
        yield 'the storage services test' => ["\u{201C}a\u{2019}", "\"a'"];
        yield 'low and reversed' => ["\u{201A}\u{201B}\u{201E}\u{201F}", "''\"\""];
        yield 'angle quotes' => ["\u{00AB}x\u{00BB} \u{2039}y\u{203A}", "\"x\" 'y'"];
        yield 'windows 1252 as c1 controls' => ["\u{0082}\u{0084}\u{008B}\u{0091}\u{0092}\u{0093}\u{0094}\u{009B}", "'\"'''\"\"'"];
        yield 'named entities' => ['&amp; &quot;x&quot; &lt;b&gt;', '& "x" <b>'];
        yield 'single quote entities' => ['it&#039;s &apos;x&apos;', "it's &apos;x&apos;"];
        yield 'entity that makes a curly quote' => ['&ldquo;x&rdquo; &rsquo;', '"x" \''];
        yield 'numeric entity' => ['&#8217;', "'"];
        yield 'not an entity' => ['a & b &nope; &', 'a & b &nope; &'];
        yield 'accents untouched' => ["Caf\u{00E9} \u{201C}na\u{00EF}ve\u{201D}", 'Café "naïve"'];
        yield 'emoji untouched' => ["\u{1F600} \u{2019}", "\u{1F600} '"];
        yield 'tags untouched' => ['<b>"x"</b>', '<b>"x"</b>'];
        yield 'invalid utf-8' => ["a\xFFb \u{2019}", "a\xFFb '"];
    }

    /**
     * @dataProvider cases
     */
    public function testNormalise(string $input, string $expected): void
    {
        $this->assertSame($expected, QuoteNormaliser::normalise($input));
    }

    /**
     * @dataProvider cases
     */
    public function testLanguageDelegatesToIt(string $input, string $expected): void
    {
        // uncurlQuotes() uses nothing of the instance: the import calls it on one made without its constructor.
        $language = (new \ReflectionClass(Language::class))->newInstanceWithoutConstructor();

        $this->assertSame($expected, $language->uncurlQuotes($input));
    }
}
