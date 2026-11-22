<?php

namespace Tests\Core;

use Idno\Core\Idno;
use Idno\Core\LinkPreview;

/**
 * A link preview is built from a page on another site (its title, description and
 * og:image). The card must print all of it escaped, and must not print an image or a
 * link whose address is not an http or https one.
 */
class LinkPreviewCardTest extends \Tests\IdnoTestCase
{

    /// The template type in use before the test started
    private $previousTemplateType;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Twenty26 only has templates of the "modern" type
        \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
    }

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
    }

    public static function lookProvider(): array
    {
        return [
            'no theme' => ['default'],
            'Twenty26' => ['modern'],
        ];
    }

    private function drawCard(array $preview, string $templateType): string
    {
        $t = Idno::site()->template();
        $t->setTemplateType($templateType);
        $html = $t->__(['preview' => $preview])->draw('entity/LinkPreviewCard');
        unset($t->vars['preview']);

        return $html;
    }

    /**
     * Fail unless the markup has only the elements and attributes that the card
     * writes, and every address in it is an http or https one.
     */
    private function assertMarkupIsSafe(string $html): void
    {
        if (trim($html) === '') {
            return;
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="card-under-test">' . $html . '</div>');
        libxml_clear_errors();

        $root = $doc->getElementById('card-under-test');
        foreach ($root->getElementsByTagName('*') as $element) {
            $this->assertContains($element->nodeName, ['a', 'div', 'img'], 'Unexpected element in: ' . $html);
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $this->assertContains($name, ['href', 'src', 'alt', 'class', 'rel', 'target', 'loading'], "Unexpected attribute \"$name\" in: " . $html);
                if (in_array($name, ['href', 'src'])) {
                    $this->assertMatchesRegularExpression(
                        '~^https?://~i',
                        trim($attribute->nodeValue),
                        "The address in $name is not an http or https one: " . $html
                    );
                }
            }
        }
    }

    /**
     * The preview of a page whose metadata is hostile, built the way the
     * linkpreview/fetch job builds it.
     */
    private function previewOfHostilePage(string $image): array
    {
        $e = function ($value) {
            return htmlspecialchars($value, ENT_QUOTES);
        };
        $html = '<!doctype html><html><head>'
            . '<title>Plain title</title>'
            . '<meta property="og:title" content="' . $e('A "title" <script>alert(1)</script>') . '">'
            . '<meta property="og:description" content="' . $e('A \'description\' "><img src=x onerror=alert(2)>') . '">'
            . '<meta property="og:site_name" content="' . $e('"><script>alert(3)</script>') . '">'
            . '<meta property="og:image" content="' . $e($image) . '">'
            . '</head><body></body></html>';

        return LinkPreview::buildPreviewFromData('https://evil.example/page', \ogp\Parser::parse($html));
    }

    public static function hostileImageProvider(): array
    {
        $cases = [];
        $images = [
            'quote'      => 'https://evil.example/i.png" onerror="alert(4)',
            'tag'        => 'https://evil.example/i.png"><script>alert(4)</script>',
            'javascript' => 'javascript:alert(4)',
            'data'       => 'data:image/svg+xml,<svg onload=alert(4)>',
        ];
        foreach (self::lookProvider() as $lookName => $look) {
            foreach ($images as $imageName => $image) {
                $cases["$lookName, $imageName"] = [$look[0], $image];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider hostileImageProvider
     */
    public function testPreviewOfHostilePageIsDrawnSafely($look, $image)
    {
        $preview = $this->previewOfHostilePage($image);
        $this->assertSame('', $preview['image'], 'An image address that is not a plain http or https one should not be kept.');

        $html = $this->drawCard($preview, $look);

        $this->assertNotEmpty(trim($html), 'The card should be drawn: the page has a title.');
        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('A &quot;title&quot; &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('href="https://evil.example/page"', $html);
    }

    /**
     * Previews that are already stored are not rewritten, so the card must not trust them.
     *
     * @dataProvider hostileImageProvider
     */
    public function testStoredHostilePreviewIsDrawnSafely($look, $image)
    {
        $html = $this->drawCard(
            [
                'url'         => 'https://evil.example/page',
                'title'       => 'A "title" <script>alert(1)</script>',
                'description' => '"><img src=x onerror=alert(2)>',
                'image'       => $image,
                'domain'      => 'evil.example"><script>alert(5)</script>',
                'site_name'   => '"><script>alert(3)</script>',
            ],
            $look
        );

        $this->assertNotEmpty(trim($html));
        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('alert(4)', $html);
    }

    public static function hostileLinkProvider(): array
    {
        $cases = [];
        $links = [
            'javascript' => 'javascript:alert(1)',
            'mixed case' => 'JavaScript:alert(1)',
            'data'       => 'data:text/html,<script>alert(1)</script>',
            'ftp'        => 'ftp://evil.example/file',
            'quote'      => 'https://evil.example/" onmouseover="alert(1)',
        ];
        foreach (self::lookProvider() as $lookName => $look) {
            foreach ($links as $linkName => $link) {
                $cases["$lookName, $linkName"] = [$look[0], $link];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider hostileLinkProvider
     */
    public function testCardIsNotDrawnForALinkThatIsNotHttp($look, $link)
    {
        $stored = ['url' => $link, 'title' => 'Title', 'description' => 'Description', 'image' => '', 'domain' => 'evil.example'];
        $this->assertSame('', trim($this->drawCard($stored, $look)));

        $built = LinkPreview::buildPreviewFromData($link, ['title' => 'Title']);
        $this->assertSame('', $built['url']);
        $this->assertSame('', trim($this->drawCard($built, $look)));
    }

    /**
     * @dataProvider lookProvider
     */
    public function testOrdinaryPreviewIsDrawn($look)
    {
        $preview = LinkPreview::buildPreviewFromData(
            'https://example.com/a?b=1&c=2',
            ['og' => [
                'og:title'       => 'Fish & chips',
                'og:description' => 'Salt, vinegar',
                'og:image'       => 'https://example.com/i.png?w=1&h=2',
            ]]
        );
        $this->assertSame('https://example.com/a?b=1&c=2', $preview['url']);
        $this->assertSame('https://example.com/i.png?w=1&h=2', $preview['image']);
        $this->assertSame('example.com', $preview['domain']);

        $html = $this->drawCard($preview, $look);

        $this->assertMarkupIsSafe($html);
        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('src="https://example.com/i.png?w=1&amp;h=2"', $html);
        $this->assertStringContainsString('Fish &amp; chips', $html);
        $this->assertStringContainsString('Salt, vinegar', $html);
        $this->assertStringContainsString('example.com', $html);
    }

    /**
     * UrlValidator::httpUrl() once refused every host that begins with "s".
     *
     * @dataProvider lookProvider
     */
    public function testPreviewOfAPageOnAHostThatBeginsWithSIsDrawn($look)
    {
        $preview = LinkPreview::buildPreviewFromData(
            'https://substack.com/@someone/p/a-post?s=r',
            ['og' => [
                'og:title' => 'A post',
                'og:image' => 'https://substackcdn.com/image/a.png',
            ]]
        );
        $this->assertSame('https://substack.com/@someone/p/a-post?s=r', $preview['url']);
        $this->assertSame('https://substackcdn.com/image/a.png', $preview['image']);
        $this->assertSame('substack.com', $preview['domain']);

        $html = $this->drawCard($preview, $look);

        $this->assertMarkupIsSafe($html);
        $this->assertStringContainsString('href="https://substack.com/@someone/p/a-post?s=r"', $html);
        $this->assertStringContainsString('src="https://substackcdn.com/image/a.png"', $html);
        $this->assertStringContainsString('substack.com', $html);
    }
}
