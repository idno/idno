<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use PHPUnit\Framework\TestCase;

/**
 * `parseURLs()`, `parseHashtags()` and `parseUsers()` of the site's template object: what the `.tpl.php` templates call, what the
 * Twig filters `parse_urls`, `parse_hashtags` and `parse_users` delegate to. They link addresses, hashtags and mentions in TEXT:
 * never inside a tag (an attribute value of sanitized HTML can hold all three), never inside a link that is already there,
 * a script, a style or a textarea. Compared with the HTML5 parser a browser uses (PHP 8.4's `Dom\HTMLDocument`).
 */
final class TemplateLinkersTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    public static function inertHtml(): array
    {
        return [
            'an address in an alt' => ['<img src="https://a.example/i.png" alt="see http://x/onerror=alert(1)//">'],
            'an address in a title' => ['<p title="see http://x/onmouseover=alert(1)//">text</p>'],
            'an address after a quote and a space' => ['<p title="x" data-a=\'a http://x/y\'>t</p>'],
            'hashtags in attributes' => ['<img src="https://a.example/i.png" alt="a #tag b" title="x #tag" style="color:#ff0000"><a href="#top">top</a>'],
            'a mention in an attribute' => ['<p title="hello @bob @someone">x</p>'],
            'inside a link' => ['<a href="https://a.example/p">https://a.example/p and #tag and @someone</a>'],
            'inside a nested element of a link' => ['<a href="https://a.example/">see <b>https://a.example/p #tag @someone</b></a>'],
            'a script' => ['<script>var a = "http://x/p #tag @someone";</script>'],
            'a style' => ['<style>a { content: "http://x/p #tag"; }</style>'],
            'a textarea' => ['<textarea>http://x/p #tag @someone</textarea>'],
            'a comment' => ['<!-- http://x/p #tag @someone -->'],
            'a tag that is never closed' => ['<img alt="http://x/p #tag @someone'],
            'an attribute with a greater-than sign' => ['<p title="a > http://x/p #tag">t</p>'],
        ];
    }

    /**
     * @dataProvider inertHtml
     */
    public function testNothingInsideATagOrALinkOrAScriptIsLinked(string $html): void
    {
        $t = Idno::site()->template();

        foreach (['parseURLs' => $t->parseURLs($html), 'parseHashtags' => $t->parseHashtags($html), 'parseUsers (reply)' => $t->parseUsers($html, 'https://twitter.com/x/status/1'), 'parseUsers' => $t->parseUsers($html), 'all three' => $t->parseUsers($t->parseHashtags($t->parseURLs($html)), 'https://twitter.com/x/status/1')] as $method => $out) {
            $this->assertSame(self::shape($html), self::shape($out), $method . ': the elements and attributes are the input\'s');
            $this->assertSame($html, $out, $method . ': and nothing was changed');
        }
    }

    public function testAnAddressInTextIsLinkedAndOneInAnAttributeAroundItIsNot(): void
    {
        $out = Idno::site()->template()->parseURLs('<img src="https://a.example/i.png" alt="see http://x/onerror=alert(1)//"> and see https://b.example/p?x=1&amp;y=2.');

        $this->assertSame(['img[alt=see http://x/onerror=alert(1)//,src=https://a.example/i.png]', 'a[href=https://b.example/p?x=1&y=2,target=_blank]'], self::shape($out));
        $this->assertStringContainsString('</a>.', $out, 'the full stop is not part of the address');
    }

    public function testAnAddressWithPunctuationAroundIt(): void
    {
        $t = Idno::site()->template();

        $this->assertSame(['a[href=https://a.example/p,target=_blank]'], self::shape($t->parseURLs('(https://a.example/p)')));
        $this->assertStringEndsWith('</a>)', $t->parseURLs('(https://a.example/p)'));
        $this->assertStringContainsString('</a>!', $t->parseURLs('wow https://a.example/p!'));
        $this->assertSame(['a[href=https://a.example/p_(x),target=_blank]'], self::shape($t->parseURLs('https://a.example/p_(x)')));
        $this->assertSame(['a[href=https://a.example/a b,target=_blank]'], array_map(static fn (string $s): string => str_replace('%20', ' ', $s), self::shape($t->parseURLs('https://a.example/a%20b'))));
        $this->assertSame(2, count(self::shape($t->parseURLs('https://a.example/1 https://a.example/2'))));
    }

    public function testOtherSchemesAreNeverLinked(): void
    {
        $t = Idno::site()->template();

        foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'see javascript://x%0aalert(1)'] as $text) {
            $this->assertSame($text, $t->parseURLs($text), $text);
        }
    }

    public function testTheCodeArgumentOfParseUrlsIsStillAddedToTheLink(): void
    {
        $this->assertStringContainsString('rel="in-reply-to"', Idno::site()->template()->parseURLs('https://a.example/p', 'rel="in-reply-to"'));
    }

    public function testHashtagsInTextAreLinkedAndTheOnesThatAreNotTagsAreNot(): void
    {
        $t = Idno::site()->template();
        $base = Idno::site()->config()->getDisplayURL();

        $this->assertSame('<a href="' . $base . 'tag/tag" class="p-category" rel="tag">#tag</a>', $t->parseHashtags('#tag'));
        $this->assertSame('x <b><a href="' . $base . 'tag/tag" class="p-category" rel="tag">#tag</a></b>', $t->parseHashtags('x <b>#tag</b>'));
        $this->assertSame('#123 #ff0000', $t->parseHashtags('#123 #ff0000'), 'a number and a colour');
        $this->assertSame(['a[class=p-category,href=' . $base . 'tag/%C3%84pfel,rel=tag]'], self::shape($t->parseHashtags('#&Auml;pfel')), 'a letter is decoded, so the tag links');
    }

    /**
     * @return array<string, list<string>>
     */
    public static function structuralEntities(): array
    {
        $cases = [];
        foreach (['&quot;', '&#34;', '&#x22;', '&#X22;', '&#0034;', '&#x0022;', '&lt;', '&#60;', '&#x3c;', '&#X3C;', '&#060;', '&gt;', '&#62;', '&#x3e;', '&#X3E;', '&amp;', '&#38;', '&#x26;', '&#X26;', '&#39;', '&#x27;', '&#039;', '&#96;', '&#x60;', '&#X60;'] as $entity) {
            $cases[$entity] = [$entity];
        }

        return $cases;
    }

    /**
     * @dataProvider structuralEntities
     */
    public function testAnEntityForAMarkupCharacterIsNeverDecoded(string $entity): void
    {
        $out = Idno::site()->template()->parseHashtags('a ' . $entity . 'b ' . $entity . ' #tag');

        $this->assertStringContainsString('a ' . $entity . 'b ' . $entity . ' <a ', $out);
    }

    public function testSanitizedHtmlWithAnEscapedQuoteInAnAttributeStaysInert(): void
    {
        $html = '<a href="https://a.example/" title="x&quot; onmouseover=&quot;alert(1)">#tag</a> <p title="x&quot; onmouseover=&quot;alert(1)">#tag &#60;script&#62; &#x3C;b&#x3E;</p>';
        $t = Idno::site()->template();

        $out = $t->parseUsers($t->parseURLs($t->parseHashtags($html)), 'https://twitter.com/x');

        $this->assertStringContainsString('title="x&quot; onmouseover=&quot;alert(1)"', $out);
        $this->assertStringContainsString('&#60;script&#62; &#x3C;b&#x3E;', $out);
        $this->assertSame(['a[href=https://a.example/,title=x" onmouseover="alert(1)]', 'p[title=x" onmouseover="alert(1)]', 'a[class=p-category,href=' . Idno::site()->config()->getDisplayURL() . 'tag/tag,rel=tag]'], self::shape($out));
    }

    public function testMentionsInTextAreLinkedAndInAttributesAreNot(): void
    {
        $t = Idno::site()->template();

        $this->assertSame(['a[href=https://twitter.com/someone,target=_blank]'], self::shape($t->parseUsers('hi @someone', 'https://twitter.com/x/status/1')));
        $this->assertSame(['p[title=hi @someone]', 'a[href=https://twitter.com/other,target=_blank]'], self::shape($t->parseUsers('<p title="hi @someone">x @other</p>', 'https://twitter.com/x/status/1')));
        $this->assertSame('hi @nobody_xyz', $t->parseUsers('hi @nobody_xyz'), 'no such local user');
    }

    /**
     * Each element as `name[attribute=value,...]` (attributes by name), in document order, as an HTML5 parser reads the markup.
     *
     * @return list<string>
     */
    private static function shape(string $html): array
    {
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
        $shape = [];
        foreach ($document->body->querySelectorAll('*') as $element) {
            if ($element->localName === 'wbr') {
                continue; // the line-break opportunities parseURLs puts in a link's text
            }
            $attributes = [];
            foreach ($element->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->name . '=' . $attribute->value;
            }
            ksort($attributes);
            $shape[] = strtolower($element->localName) . '[' . implode(',', $attributes) . ']';
        }

        return $shape;
    }
}
