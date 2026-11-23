<?php

namespace Tests\Core;

use Idno\Core\Idno;
use Idno\Entities\Reader\Feed;
use Idno\Entities\Reader\Subscription;

/**
 * What a remote site tells us about itself (the title of a feed, the name, photo and
 * address in an h-card) and what the request that caused an error carries (User-Agent,
 * query string, referrer) is written by someone else. The templates that show it must
 * print it escaped, and must not put an address that is not an http or https one into
 * href or src.
 */
class RemoteFeedOutputTest extends \Tests\IdnoTestCase
{

    /// The template type in use before the test started
    private $previousTemplateType;

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        $this->swapUser($this->admin());
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
    }

    private function draw(string $template, array $vars, string $templateType = 'default'): string
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
            $this->assertNotContains($element->nodeName, ['script', 'iframe', 'object', 'embed', 'svg', 'style', 'link', 'meta', 'base'], 'Unexpected element in: ' . $html);
            if ($element->nodeName == 'img') {
                $this->assertTrue($element->hasAttribute('src'), 'An image with no address, so one that was injected, in: ' . $html);
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $this->assertStringStartsNotWith('on', $name, "Unexpected attribute \"$name\" in: " . $html);
                $this->assertNotContains($name, ['srcdoc', 'formaction'], "Unexpected attribute \"$name\" in: " . $html);
                if (in_array($name, ['href', 'src'])) {
                    $this->assertMatchesRegularExpression(
                        '~^($|(https?:)?//)~i',
                        trim($attribute->nodeValue),
                        "The address in $name is not an http or https one: " . $html
                    );
                }
            }
        }
    }

    private const HOSTILE_TEXT = 'Feed "title" <script>alert(1)</script><img src=x onerror=alert(2)>';

    public static function hostileAddressProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(3)'],
            'mixed case' => ['JavaScript:alert(3)'],
            'data'       => ['data:text/html,<script>alert(3)</script>'],
            'quote'      => ['https://evil.example/" onmouseover="alert(3)'],
            'tag'        => ['https://evil.example/"><script>alert(3)</script>'],
        ];
    }

    // ---- following/confirm ---------------------------------------------------------

    public function testFeedTitleIsEscapedOnTheConfirmationPage()
    {
        $feed = new Feed();
        $feed->setTitle(self::HOSTILE_TEXT);
        $feed->url = 'https://evil.example/feed';

        $html = $this->draw('following/confirm', ['feed' => $feed, 'items' => []]);

        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Feed &quot;title&quot; &lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
    }

    // ---- entity/Subscription -------------------------------------------------------

    private function subscriptionTo(Feed $feed): Subscription
    {
        return new class($feed) extends Subscription {
            private $feedObject;

            public function __construct($feed)
            {
                parent::__construct();
                $this->feedObject = $feed;
            }

            public function getFeedObject()
            {
                return $this->feedObject;
            }
        };
    }

    public function testSubscriptionEscapesTitleAndFeedAddress()
    {
        $feed = new Feed();
        $feed->setTitle(self::HOSTILE_TEXT);
        $feed->url = 'https://evil.example/site';
        $feed->setFeedURL('https://evil.example/feed?a=1&b="<script>alert(4)</script>');

        $html = $this->draw('entity/Subscription', ['object' => $this->subscriptionTo($feed)]);

        $this->assertMarkupIsSafe($html);
        $this->assertStringContainsString('Feed &quot;title&quot; &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('href="https://evil.example/site"', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(4)', $html);
    }

    /**
     * @dataProvider hostileAddressProvider
     */
    public function testSubscriptionDoesNotLinkToAnAddressThatIsNotHttp($address)
    {
        $feed = new Feed();
        $feed->setTitle('A feed');
        $feed->url = $address;
        $feed->setFeedURL($address);

        $html = $this->draw('entity/Subscription', ['object' => $this->subscriptionTo($feed)]);

        $this->assertMarkupIsSafe($html);
        $this->assertStringContainsString('A feed', $html);
    }

    public function testOrdinarySubscriptionIsDrawn()
    {
        $feed = new Feed();
        $feed->setTitle('Ben & friends');
        $feed->url = 'https://static.example.com/?a=1&b=2';
        $feed->setFeedURL('https://static.example.com/feed.xml');

        $html = $this->draw('entity/Subscription', ['object' => $this->subscriptionTo($feed)]);

        $this->assertStringContainsString('<a href="https://static.example.com/?a=1&amp;b=2">Ben &amp; friends</a>', $html);
        $this->assertStringContainsString('https://static.example.com/feed.xml', $html);
    }

    // ---- account/settings/following/mf2user ----------------------------------------

    private function hcard(string $name, string $photo, array $urls): array
    {
        return ['properties' => [
            'name'     => [$name],
            'photo'    => [$photo],
            'url'      => $urls,
            'email'    => [''],
            'nickname' => [''],
        ]];
    }

    public function testHcardNameIsEscaped()
    {
        $html = $this->draw('account/settings/following/mf2user', ['mf2' => $this->hcard(self::HOSTILE_TEXT, 'https://evil.example/p.png', ['https://evil.example/'])]);

        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Feed &quot;title&quot; &lt;script&gt;alert(1)', $html);
    }

    /**
     * @dataProvider hostileAddressProvider
     */
    public function testHcardPhotoAndAddressAreInert($address)
    {
        $html = $this->draw('account/settings/following/mf2user', ['mf2' => $this->hcard('Someone', $address, [$address])]);

        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<a href="' . $address, $html);
    }

    public function testHcardWithAQuoteInTheAddressCannotAddAnAttribute()
    {
        $address = 'https://evil.example/" onmouseover="alert(3)';
        $html = $this->draw('account/settings/following/mf2user', ['mf2' => $this->hcard('Someone', 'https://evil.example/p.png', [$address])]);

        $this->assertStringNotContainsString('onmouseover="alert(3)', $html);
        $this->assertMarkupIsSafe($html);
    }

    public function testOrdinaryHcardIsDrawn()
    {
        $html = $this->draw('account/settings/following/mf2user', ['mf2' => $this->hcard('Ann & Bo', 'https://static.example.com/ann.png', ['https://static.example.com/ann'])]);

        $this->assertStringContainsString('src="https://static.example.com/ann.png"', $html);
        $this->assertStringContainsString('Ann &amp; Bo', $html);
        $this->assertStringContainsString('<a href="https://static.example.com/ann" target="_blank">https://static.example.com/ann</a>', $html);
    }

    // ---- email/admin/oops ----------------------------------------------------------

    private function oopsVars(): array
    {
        return [
            'site'     => 'https://example.com/',
            'message'  => 'Call to <b>foo</b>() failed',
            'user'     => 'https://example.com/profile/ben',
            'agent'    => 'Mozilla/5.0 "><script>alert(1)</script>',
            'qs'       => 'a=1&b="><img src=x onerror=alert(2)>',
            'referrer' => 'https://evil.example/"><script>alert(3)</script>',
        ];
    }

    public function testErrorMailHtmlEscapesRequestValues()
    {
        $html = $this->draw('admin/oops', $this->oopsVars(), 'email');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>foo</b>', $html);
        $this->assertStringContainsString('Mozilla/5.0 &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('a=1&amp;b=&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringContainsString('https://evil.example/&quot;&gt;&lt;script&gt;', $html);
    }

    public function testErrorMailTextIsPlainText()
    {
        $text = $this->draw('admin/oops', $this->oopsVars(), 'email-text');

        // A text part is not HTML: nothing is escaped
        $this->assertStringContainsString('Mozilla/5.0 "><script>alert(1)</script>', $text);
        $this->assertStringNotContainsString('&quot;', $text);
        $this->assertStringContainsString('a=1&b=">', $text);
    }
}
