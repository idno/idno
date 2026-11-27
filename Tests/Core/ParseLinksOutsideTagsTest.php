<?php

namespace Tests\Core;

use Idno\Core\Idno;

/**
 * parseURLs(), parseHashtags() and parseUsers() are applied to HTML that has already been
 * sanitized, so they may link what is in the text, and nothing else: the inside of a tag
 * (an attribute value), an existing link and script or style content are left as they are.
 */
class ParseLinksOutsideTagsTest extends \Tests\IdnoTestCase
{

    /**
     * Elements with their attributes, as sorted strings; the links the parsers add to text
     * are left out when $ignoreAddedLinks is set.
     */
    private function structure(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="markup-under-test">' . $html . '</div>');
        libxml_clear_errors();

        $found = [];
        foreach ($doc->getElementById('markup-under-test')->getElementsByTagName('*') as $element) {
            $attributes = [];
            foreach ($element->attributes as $attribute) {
                $attributes[] = $attribute->nodeName . '=' . $attribute->nodeValue;
            }
            sort($attributes);
            $found[] = $element->nodeName . '[' . implode(',', $attributes) . ']';
        }
        sort($found);

        return $found;
    }

    private function parseAll(string $html): string
    {
        $t = Idno::site()->template();

        return $t->parseURLs($t->parseHashtags($t->parseUsers($html)));
    }

    public static function attributeProvider(): array
    {
        return [
            'img alt url'      => ['<img src="https://a.example/i.png" alt="see http://x/onerror=alert(1)//">'],
            'img alt url 2'    => ['<img src="https://a.example/i.png" alt="x http://x/a b=c">'],
            'p title url'      => ['<p title="look http://x/onmouseover=alert(1)//">text</p>'],
            'title hashtag'    => ['<p title="x #tag">text</p>'],
            'title mention'    => ['<p title="hi @someone and @admin">text</p>'],
            'a href fragment'  => ['<a href="#top">up</a>'],
            'colour'           => ['<span style="color:#ff0000; background: #abc">x</span>'],
            'link in link'     => ['<a href="https://example.com/">https://example.com/</a>'],
            'link text tag'    => ['<a href="https://example.com/">see #tag and @admin</a>'],
            'script'           => ['<script>var u = "x http://example.com/a"; // #tag</script>'],
            'style'            => ['<style>a { color: #fff } /* http://example.com/ */</style>'],
            'textarea'         => ['<textarea>http://example.com/ #tag</textarea>'],
            'gt in attribute'  => ['<p title="a &gt; b http://x/y z=1">t</p>'],
            'single quotes'    => ["<p title='see http://x/onclick=alert(1)//'>t</p>"],
            'comment'          => ['<!-- http://example.com/ #tag --><p>t</p>'],
            'data address'     => ['<img src="data:image/png;base64,AAAA" alt="x ftp://h/i">'],
        ];
    }

    /**
     * @dataProvider attributeProvider
     */
    public function testNothingIsAddedInsideTags($html)
    {
        $out = $this->parseAll($html);

        $this->assertSame($this->structure($html), $this->structure($out), "Markup changed:\n$html\n$out");
        $this->assertSame($html, $out);
    }

    public function testTextIsStillLinked()
    {
        $t = Idno::site()->template();

        $out = $t->parseURLs('<p>See https://example.com/page, then <b>http://example.org/x</b>.</p>');
        $this->assertStringContainsString('<a href="https://example.com/page" target="_blank" >', $out);
        $this->assertStringContainsString('<b><a href="http://example.org/x"', $out);
        $this->assertStringContainsString('</a>,', $out);

        $out = $t->parseHashtags('<p>#first <b>#second</b></p>');
        $this->assertSame(2, substr_count($out, 'rel="tag"'));

        $out = $t->parseURLs('plain text http://example.com/ here');
        $this->assertStringContainsString('<a href="http://example.com/"', $out);
        $out = $t->parseHashtags('plain #tag here');
        $this->assertStringContainsString('>#tag</a>', $out);
    }

    public function testMentionsInTextStillLink()
    {
        $t = Idno::site()->template();

        $out = $t->parseUsers('<p>hi @someone</p>', 'https://twitter.com/x/status/1');
        $this->assertStringContainsString('<a href="https://twitter.com/someone"', $out);
        $out = $t->parseUsers('<p>hi <b>@someone</b></p>', 'https://github.com/x/y/issues/1');
        $this->assertStringContainsString('<a href="https://github.com/someone"', $out);
    }

    public function testAddressIsNotLinkedTwice()
    {
        $t = Idno::site()->template();
        $once = $t->parseURLs('go to http://example.com/ now');

        $this->assertSame($once, $t->parseURLs($once));
    }

    public function testOnlyHttpAndFtpAddressesLink()
    {
        $t = Idno::site()->template();

        $this->assertStringNotContainsString('<a ', $t->parseURLs('javascript:alert(1) data:text/html,x vbscript:x'));
    }

    public function testAnAddressInTheTextOfAParagraphWithAttributesStillLinks()
    {
        $t = Idno::site()->template();
        $out = $t->parseURLs('<p title="x http://t.example/a b=c">visit http://example.com/ok</p>');

        $this->assertStringContainsString('title="x http://t.example/a b=c"', $out);
        $this->assertStringContainsString('<a href="http://example.com/ok"', $out);
    }
}
