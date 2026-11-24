<?php

declare(strict_types=1);

namespace Tests\Unit\Markdown;

use Idno\Markdown\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

final class HtmlToMarkdownTest extends TestCase
{
    private function convert(string $html, string $site = 'https://example.com/'): string
    {
        return (new HtmlToMarkdown($site))->convert($html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conversions(): iterable
    {
        yield 'paragraphs and emphasis' => ['<p>Hello <strong>bold</strong> and <em>soft</em>.</p><p>Second.</p>', "Hello **bold** and *soft*.\n\nSecond."];
        yield 'headings are ATX' => ['<h1>One</h1><h2>Two</h2><h3>Three</h3>', "# One\n\n## Two\n\n### Three"];
        yield 'a line break' => ['<p>one<br>two</p>', "one  \ntwo"];
        yield 'a list' => ['<ul><li>a</li><li>b</li></ul><ol><li>x</li><li>y</li></ol>', "- a\n- b\n\n1. x\n2. y"];
        yield 'a quotation' => ['<blockquote><p>said</p></blockquote>', '> said'];
        yield 'code' => ["<p>Run <code>ls -l</code></p><pre><code>a *b*\n  c</code></pre>", "Run `ls -l`\n\n```\na *b*\n  c\n```"];
        yield 'a rule' => ['<p>a</p><hr><p>b</p>', "a\n\n---\n\nb"];
        yield 'a table' => ['<table><tr><th>a</th><th>b</th></tr><tr><td>1</td><td>2</td></tr></table>', "| a | b |\n|---|---|\n| 1 | 2 |"];
        yield 'plain text' => ['just text', 'just text'];
        yield 'nothing' => ['', ''];
        yield 'white space' => [" \n ", ''];
        yield 'empty blocks leave one empty line' => ['<p>a</p><p></p><div><div></div></div><p>b</p>', "a\n\nb"];
        yield 'unknown elements keep their text' => ['<p><time datetime="2026-04-10">Friday</time> <data value="yes">Yes</data> <span class="x">span</span></p>', 'Friday Yes span'];
        yield 'entities and accents' => ['<p>Caf&eacute; &amp; cr&egrave;me 🎉</p>', 'Café &amp; crème 🎉'];

        yield 'a link' => ['<p><a href="https://other.example/page" rel="nofollow" class="x" title="T">text</a></p>', '[text](https://other.example/page)'];
        yield 'a link from the root' => ['<a href="/2026/post">post</a>', '[post](https://example.com/2026/post)'];
        yield 'a relative link' => ['<a href="tag/idno">#idno</a>', '[\\#idno](https://example.com/tag/idno)'];
        yield 'a link with no scheme' => ['<a href="//cdn.example/x">x</a>', '[x](https://cdn.example/x)'];
        yield 'a mail link' => ['<a href="mailto:ada@example.com">write</a>', '[write](mailto:ada@example.com)'];
        yield 'a bare address is an autolink' => ['<a href="https://example.org/garden">https://example.org/garden</a>', '<https://example.org/garden>'];
        yield 'a script link keeps its text' => ['<a href="javascript:alert(1)">click</a>', 'click'];
        yield 'a script link in capitals and spaces' => ['<a href="  JaVaScRiPt:alert(1)">click</a>', 'click'];
        yield 'a data link keeps its text' => ['<a href="data:text/html,<script>1</script>">click</a>', 'click'];
        yield 'a scheme with a control character' => ["<a href=\"java\tscript:alert(1)\">click</a>", 'click'];
        yield 'a fragment keeps its text' => ['<a href="#top">top</a>', 'top'];
        yield 'a link with no address keeps its text' => ['<a name="x">anchor</a> <a href="">empty</a>', 'anchor empty'];
        yield 'an http address with no host' => ['<a href="http:///x">x</a> <a href="https:x">y</a>', 'x y'];
        yield 'a space in an address' => ['<a href="https://example.org/a b">x</a>', '[x](https://example.org/a%20b)'];
        yield 'an address that would end the link' => ['<a href="https://example.org/a)b(c">x</a>', '[x](https://example.org/a%29b%28c)'];
        yield 'markup in a link' => ['<a href="/x"><strong>bold</strong> text</a>', '[**bold** text](https://example.com/x)'];

        yield 'a picture' => ['<img src="https://example.com/file/1/a.png" alt="A cat" class="u-photo" loading="lazy" title="t" onerror="x()">', '![A cat](https://example.com/file/1/a.png)'];
        yield 'a picture from the root' => ['<img src="/file/1/a.png" alt="">', '![](https://example.com/file/1/a.png)'];
        yield 'a picture in a link' => ['<a href="/file/1/a.png"><img src="/file/1/thumb.png" alt="A cat"></a>', '[![A cat](https://example.com/file/1/thumb.png)](https://example.com/file/1/a.png)'];
        yield 'a data picture keeps its alternative text' => ['<p><img src="data:image/png;base64,AAAA" alt="inline picture"></p>', 'inline picture'];
        yield 'a script picture is gone' => ['<p>a<img src="javascript:alert(1)">b</p>', 'ab'];
        yield 'alternative text that would end the picture' => ['<img src="/a.png" alt="a ] (b) [c">', '![a &#93; (b) &#91;c](https://example.com/a.png)'];

        yield 'a video is a link to each file' => ['<p><video controls><source src="/file/9/v.mp4" type="video/mp4"><source src="/file/9/v.webm">Sorry</video></p>', "[Video: v.mp4](https://example.com/file/9/v.mp4)  \n[Video: v.webm](https://example.com/file/9/v.webm)"];
        yield 'audio is a link' => ['<audio src="https://cdn.example/sound%20file.mp3" controls></audio>', '[Audio: sound file.mp3](https://cdn.example/sound%20file.mp3)'];
        yield 'a player with no file is gone' => ['<p>a</p><audio controls></audio><video src="javascript:x"></video>', 'a'];

        yield 'a script is removed with its text' => ['<p>a</p><script>alert(1)</script><p>b</p>', "a\n\nb"];
        yield 'a style is removed with its text' => ['<style>p { color: red }</style><p>a</p>', 'a'];
        yield 'a frame is removed' => ['<p>a</p><iframe src="https://video.example/embed/1">fallback</iframe>', 'a'];
        yield 'a form is removed' => ['<p>a</p><form action="/x"><input name="q" value="v"><button>Go</button><textarea>t</textarea></form>', 'a'];
        yield 'a comment is removed' => ['<p>a<!-- secret --></p>', 'a'];
        yield 'an event handler is not text' => ['<p onclick="alert(1)">a</p>', 'a'];
        yield 'markup written as text stays text' => ['<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', '&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'Markdown written as text stays text' => ['<p>*not soft* _nor this_ [not](a link) `</p>', '\\*not soft\\* \\_nor this\\_ \\[not\\](a link) &#96;'];
        yield 'a line that would be a heading or a list' => ['<p># not a heading</p><p>- not an item</p><p>1. nor this</p>', "\\# not a heading\n\n\\- not an item\n\n1\\. nor this"];
        yield 'a script inside an unclosed element' => ['<div><p>a<script>alert(1)', 'a'];
    }

    /**
     * @dataProvider conversions
     */
    public function testHtmlBecomesMarkdown(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->convert($html));
    }

    public function testTheWhiteSpaceOfTheHtmlsLayoutIsNotInTheMarkdown(): void
    {
        $html = "<div class=\"well\">\n    <p>\n        <strong>A meetup</strong>\n    </p>\n    <p>\n        Location: <span>The Library</span>\n    </p>\n</div>\n<div>\n    <p>Body</p>\n</div>\n";

        $this->assertSame("**A meetup**\n\nLocation: The Library\n\nBody", $this->convert($html));
        $this->assertSame("# Oops\n\n### Sign in", $this->convert("<h1>\n   Oops\n</h1><h3>\n Sign in</h3>"));
    }

    public function testCodeAndNestedListsKeepTheirWhiteSpace(): void
    {
        $this->assertSame("```\n one\n\n\n   two \n```", $this->convert("<pre><code> one\n\n\n   two </code></pre>"));
        $this->assertSame("- a\n    - b\n    - c\n- d", $this->convert('<ul><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ul>'));
        $this->assertSame("one  \ntwo", $this->convert('<p>one<br>two</p>'), 'the two spaces of a line break stay');
    }

    public function testASiteInASubdirectory(): void
    {
        $site = 'https://example.com/blog/';

        $this->assertSame('[a](https://example.com/2026/post)', $this->convert('<a href="/2026/post">a</a>', $site), 'from the root of the host');
        $this->assertSame('[a](https://example.com/blog/tag/idno)', $this->convert('<a href="tag/idno">a</a>', $site), 'under the site');
        $this->assertSame('[a](https://example.com/blog/?q=x)', $this->convert('<a href="?q=x">a</a>', $site));
    }

    public function testTheSitesSchemeAndPortAreKept(): void
    {
        $this->assertSame('![](http://127.0.0.1:8089/file/1/a.png)', $this->convert('<img src="/file/1/a.png">', 'http://127.0.0.1:8089/'));
        $this->assertSame('[x](http://cdn.example/x)', $this->convert('<a href="//cdn.example/x">x</a>', 'http://127.0.0.1:8089/'));
    }

    public function testTheSitesAddressMustBeAbsolute(): void
    {
        foreach (['', '/blog/', 'example.com', 'ftp://example.com/', 'javascript:alert(1)'] as $site) {
            try {
                new HtmlToMarkdown($site);
                $this->fail('accepted ' . $site);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNoHtmlElementIsLeftInTheMarkdownOfHostileHtml(): void
    {
        $markdown = $this->convert(
            '<h2 onmouseover="x()">T</h2><p>x <b>b</b> <svg onload="alert(1)"><text>svg</text></svg><object data="x.swf">o</object>'
            . '<embed src="x.swf"><math><mi>m</mi></math><details open ontoggle="x()"><summary>s</summary>d</details>'
            . '<img src=x onerror=alert(1)><a href="vbscript:x">v</a><base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example/">'
            . '<link rel="stylesheet" href="https://evil.example/x.css"><noscript><p>n</p></noscript><template><p>t</p></template></p>'
        );

        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z!\/]/', $markdown, $markdown);
        $this->assertStringNotContainsString('evil.example', $markdown);
        $this->assertStringNotContainsString('alert', $markdown);
        $this->assertStringContainsString('## T', $markdown);
    }

    public function testBytesThatAreNotUtf8DoNotStopTheConversion(): void
    {
        $markdown = $this->convert("<p>caf\xE9 ok</p>");

        $this->assertTrue(mb_check_encoding($markdown, 'UTF-8'));
        $this->assertStringContainsString('ok', $markdown);
    }
}
