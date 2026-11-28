<?php

namespace Tests\Core;

use Idno\Core\Idno;

/**
 * What a feed draws for the tags of a post is text: a tag sent with markup must not become an element in an RSS item,
 * a JSON Feed, an ActivityPub object or a KML placemark, which other servers and feed readers show.
 */
class TagOutputTest extends \Tests\IdnoTestCase
{
    private $previousTemplateType;

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
    }

    private function draw(mixed $tags, string $type): string
    {
        $t = Idno::site()->template();
        $t->setTemplateType($type);
        $html = $t->__(['tags' => $tags])->draw('forms/output/tags');
        unset($t->vars['tags']);

        return $html;
    }

    public static function looks(): array
    {
        return ['Idno 2026' => ['default'], 'classic, through the rss chain' => ['rss']];
    }

    /** @dataProvider looks */
    public function testTheFeedPrintsATagAsText(string $type): void
    {
        $canary = '"><x-canary a="1">x</x-canary><b>';
        foreach ([$canary, [$canary, 'ok']] as $hostile) {
            $html = $this->draw($hostile, $type);

            $doc = \Dom\HTMLDocument::createFromString('<div id="r">' . $html . '</div>', LIBXML_NOERROR);
            $this->assertSame(0, $doc->getElementsByTagName('x-canary')->length, $html);
            foreach ($doc->getElementById('r')->getElementsByTagName('a') as $a) {
                $this->assertSame(['href', 'class', 'rel'], array_values(array_map(static fn ($attr) => $attr->name, iterator_to_array($a->attributes))), $html);
            }
        }
    }

    /** A feed of a post whose stored tags hold a list does not stop at a PHP error. */
    public function testATagListThatHoldsAListDoesNotBreakAFeed(): void
    {
        $this->assertStringContainsString('>ok<', $this->draw(['ok', ['nested']], 'rss'));
    }
}
