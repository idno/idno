<?php

declare(strict_types=1);

namespace Tests\Unit\Markdown;

use Idno\Markdown\MarkdownRequest;
use PHPUnit\Framework\TestCase;

final class MarkdownRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function paths(): iterable
    {
        yield 'a permalink' => ['/2026/hello-world.md', '/2026/hello-world'];
        yield 'an encoded slug' => ['/2026/caf%C3%A9.md', '/2026/caf%C3%A9'];
        yield 'two suffixes lose one' => ['/2026/a.md.md', '/2026/a.md'];
        yield 'no suffix' => ['/2026/hello-world', null];
        yield 'another suffix' => ['/2026/hello-world.markdown', null];
        yield 'upper case is not the suffix' => ['/2026/hello-world.MD', null];
        yield 'a trailing slash after it' => ['/2026/hello-world.md/', null];
        yield 'nothing but the suffix' => ['.md', null];
        yield 'a segment that is only the suffix' => ['/2026/.md', null];
        yield 'the root' => ['/', null];
        yield 'empty' => ['', null];
    }

    /**
     * @dataProvider paths
     */
    public function testThePathWithoutItsSuffix(string $path, ?string $expected): void
    {
        $this->assertSame($expected, MarkdownRequest::pathWithoutSuffix($path));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function acceptHeaders(): iterable
    {
        yield 'only Markdown' => ['text/markdown', true];
        yield 'Markdown first, by order' => ['text/markdown, text/html', true];
        yield 'named beside HTML with equal quality' => ['text/html, text/markdown', true];
        yield 'an agent: Markdown, HTML, anything' => ['text/markdown, text/html;q=0.9, */*;q=0.8', true];
        yield 'Markdown preferred by quality' => ['text/html;q=0.5, text/markdown;q=0.9', true];
        yield 'Markdown with a tie against anything' => ['text/markdown, */*', true];
        yield 'upper case and spaces' => ['TEXT/Markdown ; Q=1.0 , text/html ; q=0.2', true];
        yield 'a parameter that is not a quality' => ['text/markdown; charset=utf-8; variant=GFM', true];
        yield 'HTML preferred by quality' => ['text/html, text/markdown;q=0.9', false];
        yield 'anything preferred to Markdown' => ['text/markdown;q=0.5, */*;q=0.8', false];
        yield 'JSON preferred to Markdown' => ['application/json, text/markdown;q=0.5', false];
        yield 'Markdown refused' => ['text/markdown;q=0', false];
        yield 'Markdown refused, HTML wanted' => ['text/markdown;q=0, text/html', false];
        yield 'anything' => ['*/*', false];
        yield 'any text' => ['text/*', false];
        yield 'a browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', false];
        yield 'another type that holds the name' => ['text/markdown-extra, text/x-markdown', false];
        yield 'empty' => ['', false];
        yield 'commas only' => [' , ,', false];
        yield 'a quality that is no number' => ['text/markdown;q=abc', true];
        yield 'a quality above one is one' => ['text/markdown;q=0.5, text/html;q=7', false];
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testWhatAnAcceptHeaderPrefers(string $accept, bool $expected): void
    {
        $this->assertSame($expected, MarkdownRequest::acceptPrefersMarkdown($accept));
    }

    public function testAVeryLongAcceptHeaderIsNotRead(): void
    {
        $this->assertFalse(MarkdownRequest::acceptPrefersMarkdown('text/markdown, ' . str_repeat('a/b, ', 500)));
    }

    public function testTheSuffixAndTheQueryAskWhateverTheHeaderSays(): void
    {
        $this->assertTrue(MarkdownRequest::wanted(true, null, null, 'text/html'));
        $this->assertTrue(MarkdownRequest::wanted(false, 'markdown', null, 'text/html'));
        $this->assertTrue(MarkdownRequest::wanted(false, 'Markdown', null, null));
        $this->assertTrue(MarkdownRequest::wanted(false, null, 'markdown', 'text/html'));
        $this->assertTrue(MarkdownRequest::wanted(false, null, 'md', 'text/html'));
        $this->assertTrue(MarkdownRequest::wanted(false, null, 'MD', null));
        // Changed on purpose: an explicit template type that is not Markdown wins over the suffix
        $this->assertFalse(MarkdownRequest::wanted(true, null, 'rss', null), 'another type wins over the suffix');
        $this->assertFalse(MarkdownRequest::wanted(true, null, 'json', 'text/markdown'));
        $this->assertFalse(MarkdownRequest::wanted(true, null, ['json'], null));
        $this->assertTrue(MarkdownRequest::wanted(true, null, 'markdown', null));
        $this->assertTrue(MarkdownRequest::wanted(true, null, '', null), 'an empty type has chosen nothing');
    }

    public function testAnotherTypeOrFormatIsAChoiceTheHeaderDoesNotOverride(): void
    {
        $this->assertFalse(MarkdownRequest::wanted(false, null, 'rss', 'text/markdown'));
        $this->assertFalse(MarkdownRequest::wanted(false, null, 'json', 'text/markdown'));
        $this->assertFalse(MarkdownRequest::wanted(false, null, 'mdx', 'text/markdown'));
        $this->assertFalse(MarkdownRequest::wanted(false, 'html', null, 'text/markdown'));
        $this->assertFalse(MarkdownRequest::wanted(false, null, ['markdown'], 'text/markdown'));
        $this->assertFalse(MarkdownRequest::wanted(false, ['markdown'], null, 'text/markdown'));
    }

    public function testTheHeaderAsksWhenNothingElseDoes(): void
    {
        $this->assertTrue(MarkdownRequest::wanted(false, null, null, 'text/markdown'));
        $this->assertTrue(MarkdownRequest::wanted(false, '', '', 'text/markdown, text/html'));
        $this->assertFalse(MarkdownRequest::wanted(false, null, null, 'text/html'));
        $this->assertFalse(MarkdownRequest::wanted(false, null, null, '*/*'));
        $this->assertFalse(MarkdownRequest::wanted(false, null, null, null));
        $this->assertFalse(MarkdownRequest::wanted(false, null, null, ['text/markdown']));
    }
}
