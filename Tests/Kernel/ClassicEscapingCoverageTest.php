<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Templating\TemplateLocator;
use Tests\IdnoTestCase;

/**
 * The escaping tests (`AnnotationOutputTest`, `MemberContentOutputTest`, `RSVPOutputTest`, `LinkPreviewCardTest`) run on the web
 * page look and, where the template has one, on the `classic` template that feeds, JSON and mail draw, reached through the rss type's
 * chain. This says which names that is, derived from the file system, so a name that gains or loses a classic file changes this test.
 */
final class ClassicEscapingCoverageTest extends IdnoTestCase
{
    /** @return array<string, array{string}> */
    public static function drawnWithClassic(): array
    {
        return ['annotations' => ['content/end/annotations'], 'like' => ['entity/Like'], 'checkin' => ['entity/Checkin'], 'rsvp' => ['entity/RSVP']];
    }

    /** @dataProvider drawnWithClassic */
    public function testTheNameIsDrawnFromClassicByAFeed(string $name): void
    {
        $locator = \Idno\Core\Idno::container()->get(TemplateLocator::class);

        $this->assertSame('classic', $locator->locate($name, 'rss')?->type);
        $this->assertSame('default', $locator->locate($name, 'default')?->type);
    }

    /** @return array<string, array{string}> */
    public static function drawnWithoutClassic(): array
    {
        return ['link preview card' => ['entity/LinkPreviewCard'], 'embed' => ['content/embed']];
    }

    /** @dataProvider drawnWithoutClassic */
    public function testTheNameHasNoClassicFileSoItsEscapingIsCoveredOnce(string $name): void
    {
        $locator = \Idno\Core\Idno::container()->get(TemplateLocator::class);

        $this->assertNotSame('classic', $locator->locate($name, 'rss')?->type, 'it has one: add the classic case to its escaping test');
    }
}
