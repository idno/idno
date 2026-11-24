<?php

declare(strict_types=1);

namespace Tests\Unit\Markdown;

use Idno\Markdown\FrontMatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The front matter is written by hand and read here by a YAML parser (symfony/yaml, a development dependency): whatever a
 * value holds, the parser reads the same keys and the same strings back.
 */
final class FrontMatterTest extends TestCase
{
    public function testTheBlock(): void
    {
        $this->assertSame(
            "---\ntitle: \"Hello\"\nlink: \"https://example.com/2026/hello\"\ncategory:\n  - \"one\"\n  - \"two\"\n---\n",
            FrontMatter::render(['title' => 'Hello', 'author' => null, 'link' => 'https://example.com/2026/hello', 'category' => ['one', 'two'], 'empty' => []])
        );
    }

    public function testAnEmptyStringIsWrittenAndNullIsNot(): void
    {
        $this->assertSame("---\ntitle: \"\"\n---\n", FrontMatter::render(['title' => '', 'description' => null]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function values(): iterable
    {
        yield 'plain' => ['Hello world'];
        yield 'a colon' => ['Idno: the sequel'];
        yield 'a double quote' => ['She said "hello"'];
        yield 'a single quote' => ["It's here"];
        yield 'a backslash' => ['C:\\path\\n'];
        yield 'a line break' => ["one\ntwo"];
        yield 'a line break that adds a key' => ["x\nadmin: true"];
        yield 'a quote, a line break and a key' => ["x\"\nadmin: \"true"];
        yield 'the end of the block' => ["x\n---\n# heading\n"];
        yield 'a carriage return and a tab' => ["a\r\n\tb"];
        yield 'a leading dash' => ['- not a list'];
        yield 'a leading hash' => ['# not a comment'];
        yield 'a hash after a space' => ['title # comment'];
        yield 'a leading bracket' => ['[not, a, list]'];
        yield 'a leading brace' => ['{not: a map}'];
        yield 'an anchor and an alias' => ['&anchor *alias'];
        yield 'a tag' => ['!!python/object:os.system'];
        yield 'a block scalar mark' => ['| literal'];
        yield 'a percent sign' => ['%YAML 1.2'];
        yield 'a word YAML reads as a boolean' => ['yes'];
        yield 'null' => ['null'];
        yield 'a tilde' => ['~'];
        yield 'a number' => ['0123'];
        yield 'a date' => ['2026-03-01'];
        yield 'spaces around' => ['  padded  '];
        yield 'empty' => [''];
        yield 'emoji and accents' => ['Café 🎉 ñ'];
        yield 'a NUL and a bell' => ["a\0b\x07c"];
        yield 'a next line and separators' => ["a\u{85}b\u{2028}c\u{2029}d"];
        yield 'a byte order mark' => ["\u{FEFF}start"];
        yield 'an escape that is already written' => ['\\" \\n \\x41'];
    }

    /**
     * @dataProvider values
     */
    public function testAValueIsReadBackAsTheSameString(string $value): void
    {
        $block = FrontMatter::render(['title' => $value, 'link' => 'https://example.com/', 'category' => [$value, 'last']]);

        $this->assertSame(1, preg_match('/\A---\n(.*\n)---\n\z/s', $block, $match));
        $this->assertSame(5, substr_count($match[1], "\n"), 'one line a field, one the list and one a list item, whatever the value holds');
        $parsed = Yaml::parse($match[1]);
        $this->assertSame(['title' => $value, 'link' => 'https://example.com/', 'category' => [$value, 'last']], $parsed);
    }

    public function testBytesThatAreNotUtf8AreReplaced(): void
    {
        $block = FrontMatter::render(['title' => "caf\xE9 \xFF"]);

        $this->assertTrue(mb_check_encoding($block, 'UTF-8'));
        $this->assertSame(['title' => 'caf? ?'], Yaml::parse(substr($block, 4, -4)));
    }

    public function testAKeyIsANameAndNothingElse(): void
    {
        foreach (['', 'a b', 'a:b', "a\nb", '1a', 'a-b', '# a'] as $key) {
            try {
                FrontMatter::render([$key => 'x']);
                $this->fail('accepted the key ' . json_encode($key));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame("---\npubDate: \"x\"\n---\n", FrontMatter::render(['pubDate' => 'x']));
    }
}
