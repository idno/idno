<?php

namespace Tests\Core;

use Idno\Core\Idno;
use IdnoPlugins\Checkin\Checkin;
use IdnoPlugins\Like\Like;

/**
 * Values that a member types into a post (an image address in the text, the address of
 * a bookmark, the name of a place) are shown to every other member and visitor, so the
 * templates must print them escaped, and must not print an address that is not an http
 * or https one.
 */
class MemberContentOutputTest extends \Tests\IdnoTestCase
{

    /// Entities to remove after each test
    private $toDelete = [];

    /// The template type and the page in use before the test started
    private $previousTemplateType;
    private $previousPage;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Twenty26 only has templates of the "modern" type
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
        foreach ($this->toDelete as $entity) {
            $entity->delete();
        }
        $this->toDelete = [];
    }

    public static function lookProvider(): array
    {
        return [
            'no theme' => ['default'],
            'Twenty26' => ['modern'],
        ];
    }

    private function draw(string $template, array $vars, string $templateType): string
    {
        $t = Idno::site()->template();
        $t->setTemplateType($templateType);
        $html = $t->__($vars)->draw($template);
        foreach (array_keys($vars) as $name) {
            unset($t->vars[$name]);
        }

        return $html;
    }

    /**
     * Fail if the markup has an element or an attribute that runs script, or an
     * address that is not an http or https one.
     */
    private function assertMarkupIsSafe(string $html): void
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="markup-under-test">' . $html . '</div>');
        libxml_clear_errors();

        foreach ($doc->getElementById('markup-under-test')->getElementsByTagName('*') as $element) {
            $this->assertNotContains($element->nodeName, ['object', 'embed', 'svg', 'style', 'link', 'meta', 'base'], 'Unexpected element in: ' . $html);
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $this->assertStringStartsNotWith('on', $name, "Unexpected attribute \"$name\" in: " . $html);
                $this->assertNotContains($name, ['srcdoc', 'formaction'], "Unexpected attribute \"$name\" in: " . $html);
                if (in_array($name, ['href', 'src', 'action'])) {
                    $this->assertMatchesRegularExpression(
                        '~^($|#|/[^/]|(https?:)?//)~i',
                        trim($attribute->nodeValue),
                        "The address in $name is not an http or https one: " . $html
                    );
                }
            }
        }
    }

    // ---- content/embed -------------------------------------------------------------

    public static function hostileImageAddressProvider(): array
    {
        return [
            'quote'           => ['Look http://evil.example/a"onerror="alert(1)"x.y.png'],
            'quote and space' => ['http://evil.example/a.b.png"onload="alert(1)" x http://evil.example/c.d.png'],
            'tag'             => ['http://evil.example/a"><script>alert(1)</script>.b.png'],
            'iframe'          => ['http://evil.example/"><iframe/srcdoc="x"></iframe>.b.png'],
        ];
    }

    /**
     * @dataProvider hostileImageAddressProvider
     */
    public function testEmbeddedImageAddressIsEscaped($body)
    {
        foreach (self::lookProvider() as $look) {
            $html = $this->draw('content/embed', ['value' => $body], $look[0]);

            $this->assertMarkupIsSafe($html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('<iframe', $html);
            $this->assertStringNotContainsString('alert(1)', $html, 'An address that holds a quote or a tag is not an image address.');
        }
    }

    public function testOrdinaryImageAddressIsEmbedded()
    {
        foreach (self::lookProvider() as $look) {
            $html = $this->draw('content/embed', ['value' => 'A cat: https://example.com/pics/cat.small.png and a video https://www.youtube.com/watch?v=abc123'], $look[0]);

            $this->assertStringContainsString('<img src="https://example.com/pics/cat.small.png"', $html);
            $this->assertStringContainsString('youtube-nocookie.com/embed/abc123', $html);
        }
    }

    // ---- Like ----------------------------------------------------------------------

    public static function hostileBookmarkProvider(): array
    {
        $cases = [];
        $addresses = [
            'javascript'      => 'javascript:alert(1)',
            'javascript url'  => 'javascript://example.com/%0Aalert(1)',
            'mixed case'      => 'JavaScript:alert(1)',
            'data'            => 'data:text/html,<script>alert(1)</script>',
            'quote'           => 'http://evil.example/"onmouseover="alert(1)',
            'tag'             => '"><script>alert(1)</script>',
        ];
        foreach (self::lookProvider() as $lookName => $look) {
            foreach ($addresses as $name => $address) {
                $cases["$lookName, $name"] = [$look[0], $address];
            }
        }

        return $cases;
    }

    /**
     * A bookmark that is already stored with a hostile address.
     *
     * @dataProvider hostileBookmarkProvider
     */
    public function testStoredBookmarkAddressIsDrawnSafely($look, $address)
    {
        $like = new Like();
        $like->body = $address;
        $like->pageTitle = 'A "page" <b>title</b>';
        $like->description = 'Worth reading';

        $html = $this->draw('entity/Like', ['object' => $like], $look);

        $this->assertStringContainsString('A &quot;page&quot; title', $html, 'The bookmark should be drawn.');
        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    /**
     * @dataProvider lookProvider
     */
    public function testOrdinaryBookmarkIsDrawn($look)
    {
        $like = new Like();
        $like->body = 'https://example.com/a?b=1&c=2';
        $like->pageTitle = 'Example';

        $html = $this->draw('entity/Like', ['object' => $like], $look);

        $this->assertMarkupIsSafe($html);
        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $html);
    }

    public static function hostileBookmarkInputProvider(): array
    {
        return [
            'javascript in body'    => [['body' => 'javascript://example.com/%0Aalert(1)']],
            'javascript in like-of' => [['like-of' => 'javascript://example.com/%0Aalert(1)']],
            'tag beside a real url' => [['body' => 'https://example.com/', 'like-of' => '"><script>alert(1)</script>']],
            'tag in repost-of'      => [['body' => 'https://example.com/', 'repost-of' => '"><script>alert(1)</script>']],
            'tag in bookmark-of'    => [['body' => 'https://example.com/', 'bookmark-of' => 'x" onmouseover="alert(1)']],
        ];
    }

    /**
     * @dataProvider hostileBookmarkInputProvider
     */
    public function testBookmarkOfAnAddressThatIsNotHttpIsNotSaved($input)
    {
        $page = new \Idno\Pages\Homepage();
        foreach ($input + ['title' => 'A title', 'access' => 'PUBLIC', 'publish_status' => 'draft'] as $name => $value) {
            $page->setInput($name, $value);
        }
        Idno::site()->setCurrentPage($page);

        $like = new Like();
        $saved = $like->saveDataFromInput();
        if ($like->_id) {
            $this->toDelete[] = $like;
        }

        foreach (['body', 'likeof', 'repostof', 'bookmarkof'] as $field) {
            $this->assertMatchesRegularExpression('~^($|https?://[^"<>]*$)~', (string) $like->$field, "$field should be empty or an http or https address.");
        }
        if ($saved) {
            // The other field held a real address
            $this->assertSame('https://example.com/', $like->body);
        } else {
            $this->assertEmpty($like->_id, 'The bookmark should not have been saved.');
        }
        Idno::site()->session()->getAndFlushMessages();
    }

    public function testBookmarkOfAnHttpAddressIsSaved()
    {
        $page = new \Idno\Pages\Homepage();
        foreach (['body' => ' https://example.com/a?b=1&c=2 ', 'title' => 'A title', 'access' => 'PUBLIC', 'publish_status' => 'draft'] as $name => $value) {
            $page->setInput($name, $value);
        }
        Idno::site()->setCurrentPage($page);

        $like = new Like();
        $this->assertTrue($like->saveDataFromInput());
        $this->toDelete[] = $like;

        $this->assertSame('https://example.com/a?b=1&c=2', $like->body);
    }

    // ---- Checkin -------------------------------------------------------------------

    public static function hostilePlaceProvider(): array
    {
        $cases = [];
        $names = [
            'quote' => 'Cafe" onmouseover="alert(1)',
            'tag'   => 'Cafe <script>alert(1)</script>',
            'both'  => '"><img src=x onerror=alert(1)>',
        ];
        foreach (self::lookProvider() as $lookName => $look) {
            foreach ($names as $name => $place) {
                $cases["$lookName, $name"] = [$look[0], $place];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider hostilePlaceProvider
     */
    public function testPlaceNameIsEscaped($look, $place)
    {
        $checkin = new Checkin();
        $checkin->placename = $place;
        $checkin->lat = 51.75;
        $checkin->long = -1.25;
        $checkin->body = 'Coffee';
        $checkin->created = time() - 172800;

        $html = $this->draw('entity/Checkin', ['object' => $checkin], $look);

        // The template's own map script is expected; nothing of the place name may be markup
        $withoutMap = preg_replace('~<script>\s*\(function \(\) \{\s*var map = L\.map\(.*?</script>~s', '', $html);
        $this->assertStringNotContainsString('<script', $withoutMap);
        $this->assertStringNotContainsString('<img', $withoutMap);
        $this->assertMarkupIsSafe($withoutMap);
        $this->assertStringContainsString(htmlspecialchars($place), $html, 'The place name should be shown, escaped.');
    }

    /**
     * @dataProvider lookProvider
     */
    public function testOrdinaryPlaceNameIsShown($look)
    {
        $checkin = new Checkin();
        $checkin->placename = 'Fish & Chips';
        $checkin->lat = 51.75;
        $checkin->long = -1.25;
        $checkin->created = time() - 172800;

        $html = $this->draw('entity/Checkin', ['object' => $checkin], $look);

        $this->assertStringContainsString('value="Fish &amp; Chips"', $html);
        $this->assertStringContainsString('Checked into Fish &amp; Chips', $html);
        $this->assertStringContainsString('value="51.75"', $html);
    }
}
