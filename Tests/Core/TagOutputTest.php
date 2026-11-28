<?php

namespace Tests\Core;

use Idno\Core\Idno;
use Idno\Core\Tags;
use IdnoPlugins\Status\Status;

/**
 * The tags of a post are sent by whoever makes the post (a form field, an API request, the
 * categories of a Micropub request) and are drawn for every visitor, so they are printed as
 * text, whatever was stored, and are stored as plain text.
 */
class TagOutputTest extends \Tests\IdnoTestCase
{

    private $previousTemplateType;
    private $previousPage;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
    }

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        $this->previousPage = Idno::site()->currentPage();
        $this->swapUser($this->admin());
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
        Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
    }

    private function draw(string $template, array $vars, string $look): string
    {
        $t = Idno::site()->template();
        $t->setTemplateType($look);
        $html = $t->__($vars)->draw($template);
        foreach (array_keys($vars) as $name) {
            unset($t->vars[$name]);
        }

        return (string) $html;
    }

    /**
     * The names of the elements in a piece of markup, and fail on an attribute that runs script.
     *
     * @return string[]
     */
    private function elementsOf(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="markup-under-test">' . $html . '</div>');
        libxml_clear_errors();

        $names = [];
        foreach ($doc->getElementById('markup-under-test')->getElementsByTagName('*') as $element) {
            $names[] = strtolower($element->nodeName);
            foreach ($element->attributes as $attribute) {
                $this->assertStringStartsNotWith('on', strtolower($attribute->nodeName), 'A tag added an attribute in: ' . $html);
                if (strtolower($attribute->nodeName) === 'href') {
                    $this->assertMatchesRegularExpression('~^https?://~i', $attribute->nodeValue, 'A tag changed the address of its link in: ' . $html);
                }
            }
        }

        return array_values(array_unique($names));
    }

    public static function storedTagsProvider(): array
    {
        $stored = [
            'text with an element'      => '<script>alert(1)</script>',
            'text that ends the link'   => 'x</a><img src=x onerror=alert(1)>',
            'text with a quote'         => 'x" onmouseover="alert(1)',
            'list with an element'      => ['fine', '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>'],
            'list with things not text' => ['fine', ['<script>alert(1)</script>'], null, new \stdClass(), 7, '<b>bold</b>'],
        ];
        $cases = [];
        foreach (['default', 'modern'] as $look) {
            foreach ($stored as $name => $tags) {
                $cases["$look look, $name"] = [$look, $tags];
            }
        }

        return $cases;
    }

    /**
     * A post stored with markup in its tags (before tags were cleaned when stored) must not
     * add an element or an attribute to the page of anyone who reads the post.
     *
     * @dataProvider storedTagsProvider
     */
    public function testStoredTagsAreDrawnAsText($look, $tags)
    {
        $html = $this->draw('forms/output/tags', ['tags' => $tags], $look);

        $this->assertNotSame('', trim($html), 'The tags should be drawn.');
        $this->assertEmpty(array_diff($this->elementsOf($html), ['p', 'i', 'a']), 'A tag added an element to: ' . $html);
    }

    /**
     * The same through the template of a post, which adds the tags to the text of the post
     * and links the addresses and hashtags in the whole.
     *
     * @dataProvider storedTagsProvider
     */
    public function testAPostWithStoredTagsIsDrawnWithoutTheirMarkup($look, $tags)
    {
        $status = new Status();
        $status->body = 'A note about http://example.com/page and #things';
        $status->tags = $tags;

        $html = $this->draw('entity/Status', ['object' => $status], $look);

        $this->assertStringContainsString('A note about', $html);
        $names = $this->elementsOf($html);
        foreach (['script', 'img', 'svg', 'b'] as $name) {
            $this->assertNotContains($name, $names, "A tag added <$name> to: " . $html);
        }
    }

    /**
     * Tags must still be drawn: each as a link to its tag page, marked up as a category.
     */
    public function testTagsAreLinksToTheirTagPages()
    {
        foreach (['default', 'modern'] as $look) {
            $html = $this->draw('forms/output/tags', ['tags' => ['indieweb', 'Café & bar']], $look);

            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
            libxml_clear_errors();
            $links = [];
            foreach ($doc->getElementsByTagName('a') as $a) {
                $this->assertStringContainsString('p-category', $a->getAttribute('class'));
                $links[$a->textContent] = $a->getAttribute('href');
            }

            $base = Idno::site()->config()->getDisplayURL() . 'tag/';
            $this->assertSame(['indieweb' => $base . 'indieweb', 'Café & bar' => $base . urlencode('Café & bar')], $links, "In the $look look");
        }
    }

    public function testNothingIsDrawnForNoTags()
    {
        foreach (['default', 'modern'] as $look) {
            foreach ([null, '', [], [[], null, '  '], new \stdClass()] as $tags) {
                $this->assertStringNotContainsString('<a', $this->draw('forms/output/tags', ['tags' => $tags], $look));
            }
        }
    }

    public static function sentTagsProvider(): array
    {
        return [
            'one tag'                 => ['indieweb', 'indieweb'],
            'several in one text'     => ['#one, #two', '#one, #two'],
            'spaces and line breaks'  => ["  two\n words\t ", 'two words'],
            'an element'              => ['<script>alert(1)</script>', 'alert(1)'],
            'angle brackets'          => ['a < b > c', 'a b c'],
            'a list'                  => [[' #One', 'two', ''], ['#One', 'two']],
            'a list with other things' => [['one', ['two'], null, 3, '<img src=x onerror=alert(1)>', '<b>four</b>'], ['one', '3', 'four']],
            'a map'                   => [['$gt' => 'x'], ['x']],
            'nothing'                 => [null, null],
            'not text'                => [true, null],
        ];
    }

    /**
     * What a request sends as tags is stored as one line of plain text, or a list of them.
     *
     * @dataProvider sentTagsProvider
     */
    public function testTagsAreStoredAsPlainText($sent, $stored)
    {
        $this->assertSame($stored, Tags::clean($sent));
    }

    /**
     * Hashtags are read from the stored tags when a post is saved; a list that holds something
     * that is no text (stored by an earlier version) must not stop the post being saved or drawn.
     */
    public function testHashtagsAreReadFromTagsThatHoldMoreThanText()
    {
        $status = new Status();
        $status->body = 'A note';
        $status->tags = ['#one', ['#nested'], null, '#two'];

        $this->assertSame(['#one', '#two'], $status->getTags());
    }
}
