<?php

namespace Tests\Core;

use Idno\Core\Idno;
use IdnoPlugins\Photo\Photo;

/**
 * parseHashtags() is applied to HTML that has already been sanitized or escaped, so it
 * must not turn an escaped character back into markup. It decodes named letters such as
 * &auml; so that "#Äpfel" links, and nothing that stands for < > & " ' or a backtick.
 */
class ParseHashtagsTest extends \Tests\IdnoTestCase
{

    private $toDelete = [];
    private $previousTemplateType;
    private $previousPage;

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        $this->previousPage = Idno::site()->currentPage();
        $this->swapUser($this->admin());
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
        Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Legacy\BarePage());
        foreach ($this->toDelete as $entity) {
            $entity->delete();
        }
        $this->toDelete = [];
    }

    /**
     * Every element, with its attributes, as a list of strings, for comparing the
     * structure of two pieces of markup. Hashtag links are left out.
     */
    private function structure(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="markup-under-test">' . $html . '</div>');
        libxml_clear_errors();

        $found = [];
        foreach ($doc->getElementById('markup-under-test')->getElementsByTagName('*') as $element) {
            if ($element->getAttribute('rel') === 'tag' && $element->getAttribute('class') === 'p-category') {
                continue;
            }
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

    public static function hostileEntityProvider(): array
    {
        $spellings = [
            '&quot;', '&QUOT;', '&#34;', '&#034;', '&#0034;', '&#x22;', '&#X22;', '&#x022;',
            '&#39;', '&#039;', '&#x27;', '&#X27;', '&apos;',
            '&#60;', '&#x3c;', '&#x3C;', '&#X3c;', '&#062;', '&#62;', '&#x3e;', '&lt;', '&gt;', '&LT;', '&GT;',
            '&#38;', '&#x26;', '&amp;', '&AMP;',
            '&#96;', '&#x60;', '&grave;', '&DiacriticalGrave;',
            '&#00060;', '&#x0003c;',
        ];
        $cases = [];
        foreach ($spellings as $entity) {
            // In an attribute value, as the sanitizer writes it
            $cases["attribute $entity"] = ['<a href="https://example.com/" title="x' . $entity . ' onmouseover=' . $entity . 'alert(1) y' . $entity . '">link</a>'];
            // In text
            $cases["text $entity"] = ['<p>Say ' . $entity . 'hello' . $entity . ' and ' . $entity . 'img src=x onerror=alert(1)' . $entity . ' #tag</p>'];
            // Next to a hashtag
            $cases["hashtag $entity"] = ['<p>' . $entity . '#tag ' . $entity . 'script' . $entity . '</p>'];
        }

        return $cases;
    }

    /**
     * @dataProvider hostileEntityProvider
     */
    public function testEntitiesThatStandForMarkupAreNotDecoded($html)
    {
        $out = Idno::site()->template()->parseHashtags($html);

        $this->assertSame($this->structure($html), $this->structure($out), 'parseHashtags() changed the markup of: ' . $html . ' into: ' . $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testEntitiesAreStillThereAfterwards()
    {
        $html = '<p title="a&quot;b&#39;c&#60;d&#x3e;e&amp;f&#96;">1 &lt; 2 &amp;&amp; 3 &gt; 2, &quot;quoted&quot;, &#x3C;</p>';

        $this->assertSame($html, Idno::site()->template()->parseHashtags($html));
    }

    public function testNamedLettersAreDecodedSoThatHashtagsLink()
    {
        $out = Idno::site()->template()->parseHashtags('<p>Eat #&Auml;pfel and #Caf&eacute; now</p>');

        $this->assertStringContainsString('class="p-category" rel="tag">#Äpfel</a>', $out);
        $this->assertStringContainsString('tag/' . urlencode('Äpfel'), $out);
        $this->assertStringContainsString('>#Café</a>', $out);
    }

    public function testHashtagAfterANonBreakingSpaceLinks()
    {
        $out = Idno::site()->template()->parseHashtags('<p>so&nbsp;#tag</p>');

        $this->assertStringContainsString('>#tag</a>', $out);
    }

    public function testHashtagsAreNotLinkedInsideAttributes()
    {
        // (This branch never links inside an existing <a>, so #yes stands outside it: see Parsing::mapText().)
        $html = '<p><a href="#top" title="#nope">top</a> #yes <span style="color: #ff0000">x</span> #123 #abcdef</p>';
        $out = Idno::site()->template()->parseHashtags($html);

        $this->assertStringContainsString('href="#top"', $out);
        $this->assertStringContainsString('title="#nope"', $out);
        $this->assertStringContainsString('color: #ff0000', $out);
        $this->assertStringContainsString('>#yes</a>', $out);
        $this->assertSame(1, substr_count($out, 'rel="tag"'), 'Only #yes should link in: ' . $out);
    }

    /**
     * A photo's text is sanitized when it is saved; the page must still show it as
     * the sanitizer wrote it.
     */
    public function testSavedPostRendersInertInBothLooks()
    {
        $input = '<a href="https://example.com/" title="x&quot; onmouseover=&quot;alert(1)&quot; y">link</a> '
               . '<a href="https://example.com/" title="p&#34;onfocus=&#34;alert(2)">two</a> #tag';
        $photo = new Photo();
        $photo->body = Idno::site()->template()->sanitize_html($input);
        $photo->setOwner($this->admin());
        $photo->save(true);
        $this->toDelete[] = $photo;
        $saved = Photo::getByID($photo->getID());

        foreach (['default', 'rss'] as $look) {
            $t = Idno::site()->template();
            $t->setTemplateType($look);
            $html = $t->__(['object' => $saved])->draw('entity/Photo');
            unset($t->vars['object']);

            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
            foreach ($doc->getElementsByTagName('a') as $a) {
                foreach ($a->attributes as $attribute) {
                    $this->assertStringStartsNotWith('on', $attribute->nodeName, "Injected attribute in the $look look: " . $html);
                }
            }
            $this->assertStringContainsString('>#tag</a>', $html);
        }
    }
}
