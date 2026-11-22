<?php

namespace Tests\Common;

use Idno\Core\Idno;
use Idno\Core\Webmention;
use IdnoPlugins\Status\Status;

/**
 * The owner's address, the owner's image and the address of an annotation come from
 * whoever sent the webmention. They must be stored only when they are http or https
 * addresses, and the annotation templates of both looks (the core one and Twenty26)
 * must print them escaped, and must not print one with another scheme even when it
 * is already stored.
 */
class AnnotationOutputTest extends \Tests\IdnoTestCase
{

    /// Entities to remove after each test
    private $toDelete = [];

    /// The template type in use before the test started
    private $previousTemplateType;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Twenty26 only has templates of the "modern" type, so this changes nothing
        // for the tests that draw the default type.
        \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
    }

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        $this->swapUser($this->admin());
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
        foreach ($this->toDelete as $entity) {
            $entity->delete();
        }
        $this->toDelete = [];
    }

    /**
     * The two looks: the template type, and the template that draws a post's annotations.
     */
    public static function lookProvider(): array
    {
        return [
            'no theme' => ['default'],
            'Twenty26' => ['modern'],
        ];
    }

    /**
     * Hostile values for the owner's address, the owner's image and the annotation's address.
     */
    public static function hostileUrls(): array
    {
        return [
            'quote'      => [
                'http://evil.example/u"onmouseover="alert(1)',
                'http://evil.example/i.png"onerror="alert(2)',
                'http://evil.example/p"onclick="alert(3)',
            ],
            'javascript' => [
                'javascript:alert(1)',
                'javascript:alert(2)',
                'javascript:alert(3)',
            ],
            'mixed case' => [
                'JavaScript:alert(1)',
                'data:text/html,<script>alert(2)</script>',
                'vbscript:alert(3)',
            ],
        ];
    }

    public static function lookAndUrlProvider(): array
    {
        $cases = [];
        foreach (self::lookProvider() as $lookName => $look) {
            foreach (self::hostileUrls() as $urlName => $urls) {
                foreach (['reply', 'like', 'share', 'mention', 'rsvp'] as $type) {
                    $cases["$lookName, $urlName, $type"] = array_merge($look, $urls, [$type]);
                }
            }
        }
        return $cases;
    }

    private function post(): Status
    {
        $post = new Status();
        $post->body = 'A post that receives webmentions';
        $post->setAccess('PUBLIC');
        $post->save();
        $this->toDelete[] = $post;

        return $post;
    }

    /**
     * Add an annotation to a post the way a received webmention does: from the
     * microformats of the page that mentions it.
     */
    private function receiveWebmention(Status $post, string $ownerUrl, string $ownerImage, string $permalink, string $type): void
    {
        $target = $post->getURL();
        $source = 'http://evil.example/source';
        $e = function ($value) {
            return htmlspecialchars($value, ENT_QUOTES);
        };
        $verb = [
            'reply'   => '<a class="u-in-reply-to" href="' . $e($target) . '">in reply to</a>',
            'like'    => '<a class="u-like-of" href="' . $e($target) . '">liked</a>',
            'share'   => '<a class="u-repost-of" href="' . $e($target) . '">reposted</a>',
            'mention' => '<a href="' . $e($target) . '">mentioned</a>',
            'rsvp'    => '<a class="u-in-reply-to" href="' . $e($target) . '">event</a><data class="p-rsvp" value="yes">yes</data>',
        ][$type];
        $html = '<!doctype html><html><head><title>A "quoted" <title></title></head><body>'
            . '<div class="h-entry">'
            . '<a class="u-url" href="' . $e($permalink) . '">permalink</a>'
            . '<div class="p-author h-card">'
            . '<a class="p-name u-url" href="' . $e($ownerUrl) . '">Eve "the" &lt;Evil&gt;</a>'
            . '<img class="u-photo" src="' . $e($ownerImage) . '" alt="">'
            . '</div>'
            . $verb
            . '<div class="e-content">Hello "there" http://evil.example/x</div>'
            . '</div></body></html>';

        $mf2 = Webmention::parseContent($html, $source);
        $this->assertTrue(
            $post->addWebmentions($source, $target, ['response' => 200, 'content' => $html], $mf2),
            'The webmention should have been accepted.'
        );
    }

    private function drawAnnotations(Status $post, string $templateType): string
    {
        // Drawn for a visitor: the owner also gets a delete link, which needs a page
        $owner = $this->swapUser(false);
        $t = Idno::site()->template();
        $t->setTemplateType($templateType);
        $html = $t->__(['object' => $post])->draw('content/end/annotations');
        unset($t->vars['annotations']);
        $this->swapUser($owner);
        $this->assertNotEmpty(trim($html), 'The annotations should have been drawn.');

        return $html;
    }

    /**
     * Fail unless every element of the markup has only attributes that the annotation
     * templates write, and every address in it is an http or https one.
     */
    private function assertMarkupIsSafe(string $html): void
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();

        $allowed = ['href', 'src', 'alt', 'class', 'rel', 'id', 'style', 'datetime', 'target',
            'action', 'method', 'type', 'name', 'value', 'title', 'aria-label', 'role',
            'data-confirm', 'width', 'height', 'viewbox', 'fill', 'stroke', 'd', 'xmlns',
            'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'points', 'x1', 'x2', 'y1', 'y2', 'aria-hidden'];

        foreach ($doc->getElementsByTagName('*') as $element) {
            $this->assertNotContains($element->nodeName, ['script', 'iframe', 'object', 'embed'], 'No such element should be drawn: ' . $html);
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $this->assertContains($name, $allowed, "Unexpected attribute \"$name\" in: " . $html);
                if (in_array($name, ['href', 'src', 'action'])) {
                    $value = trim($attribute->nodeValue);
                    $this->assertMatchesRegularExpression(
                        '~^($|#|https?://)~i',
                        $value,
                        "The address in $name is not an http or https one: " . $html
                    );
                }
            }
        }
    }

    /**
     * @dataProvider lookAndUrlProvider
     */
    public function testWebmentionWithHostileAddressesIsDrawnSafely($look, $ownerUrl, $ownerImage, $permalink, $type)
    {
        $post = $this->post();
        $this->receiveWebmention($post, $ownerUrl, $ownerImage, $permalink, $type);

        $html = $this->drawAnnotations($post, $look);

        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('alert(', $html, 'Nothing of the hostile addresses should be drawn.');
    }

    /**
     * @dataProvider hostileUrls
     */
    public function testOnlyHttpAddressesAreStored($ownerUrl, $ownerImage, $permalink)
    {
        $post = $this->post();
        $this->assertTrue($post->addAnnotation('reply', 'Eve', $ownerUrl, $ownerImage, 'Hello', $permalink));

        $annotations = $post->getAnnotations('reply');
        $this->assertCount(1, $annotations);
        $annotation = reset($annotations);

        foreach (['owner_url', 'owner_image', 'permalink'] as $field) {
            $this->assertStringNotContainsString('"', $annotation[$field], "$field should not hold a quote.");
            $this->assertMatchesRegularExpression('~^($|https?://)~', $annotation[$field], "$field should be empty or an http or https address.");
        }
        $this->assertSame('', $annotation['owner_url']);
        $this->assertSame('', $annotation['owner_image']);
        // An annotation always has an address: one is made up when the given one is refused
        $this->assertStringStartsWith($post->getURL() . '/annotations/', $annotation['permalink']);
    }

    public function testOrdinaryAddressesAreStoredAsGiven()
    {
        $post = $this->post();
        $post->addAnnotation(
            'reply',
            'Alice',
            'https://alice.example/?a=1&b=2',
            'http://alice.example/photo.jpg',
            'Hello',
            'https://alice.example/notes/1#c2'
        );
        $annotations = $post->getAnnotations('reply');
        $annotation = reset($annotations);

        $this->assertSame('https://alice.example/?a=1&b=2', $annotation['owner_url']);
        $this->assertSame('http://alice.example/photo.jpg', $annotation['owner_image']);
        $this->assertSame('https://alice.example/notes/1#c2', $annotation['permalink']);

        foreach (self::lookProvider() as $look) {
            $html = $this->drawAnnotations($post, $look[0]);
            $this->assertMarkupIsSafe($html);
            $this->assertStringContainsString('href="https://alice.example/?a=1&amp;b=2"', $html);
            $this->assertStringContainsString('src="http://alice.example/photo.jpg"', $html);
            $this->assertStringContainsString('href="https://alice.example/notes/1#c2"', $html);
        }
    }

    /**
     * A webmention whose own address is refused is filed under the address of the page
     * that sent it, so that the same page sending again replaces it.
     */
    public function testRefusedPermalinkFallsBackToTheSource()
    {
        $post = $this->post();
        $this->receiveWebmention($post, 'http://evil.example/', 'http://evil.example/i.png', 'javascript:alert(3)', 'reply');
        $this->receiveWebmention($post, 'http://evil.example/', 'http://evil.example/i.png', 'javascript:alert(3)', 'reply');

        $annotations = $post->getAnnotations('reply');
        $this->assertCount(1, $annotations);
        $this->assertSame('http://evil.example/source', reset($annotations)['permalink']);
    }

    /**
     * Annotations stored before addresses were checked are not rewritten, so the
     * templates must not trust what is stored.
     *
     * @dataProvider lookAndUrlProvider
     */
    public function testStoredHostileAnnotationIsDrawnSafely($look, $ownerUrl, $ownerImage, $permalink, $type)
    {
        $post = $this->post();
        $local = $post->getURL() . '/annotations/' . md5($permalink);
        $annotation = [
            'permalink'   => $permalink,
            'owner_name'  => 'Eve "the" <b>Evil</b>',
            'owner_url'   => $ownerUrl,
            'owner_image' => $ownerImage,
            'content'     => 'Hello "there"',
            'time'        => time(),
            'title'       => 'A "quoted" <i>title</i>',
        ];
        if ($type == 'rsvp') {
            $annotation['rsvp'] = 'yes';
        }
        $post->annotations = [$type => [$local => $annotation]];
        $post->save();

        $html = $this->drawAnnotations($post, $look);

        $this->assertMarkupIsSafe($html);
        $this->assertStringNotContainsString('alert(', $html, 'Nothing of the hostile addresses should be drawn.');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
    }

    /**
     * The page of a single annotation draws it through entity/annotations/shell.
     *
     * @dataProvider hostileUrls
     */
    public function testSingleAnnotationPageIsDrawnSafely($ownerUrl, $ownerImage, $permalink)
    {
        $post = $this->post();
        $local = $post->getURL() . '/annotations/' . md5($permalink);
        $annotation = [
            'permalink'   => $permalink,
            'owner_name'  => 'Eve',
            'owner_url'   => $ownerUrl,
            'owner_image' => $ownerImage,
            'content'     => 'Hello',
            'time'        => time(),
            'title'       => '',
        ];
        $this->swapUser(false);
        foreach (self::lookProvider() as $look) {
            foreach (['reply', 'like', 'mentions', 'shares', 'rsvps'] as $subtype) {
                $t = Idno::site()->template();
                $t->setTemplateType($look[0]);
                $html = $t->__(['annotation' => $annotation, 'subtype' => $subtype, 'permalink' => $local, 'object' => $post])
                    ->draw('entity/annotations/shell');
                unset($t->vars['annotations']);

                $this->assertNotEmpty(trim($html));
                $this->assertMarkupIsSafe($html);
                $this->assertStringNotContainsString('alert(', $html);
            }
        }
    }
}
