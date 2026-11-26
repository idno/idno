<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Webmention;

use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\WebmentionTarget;
use PHPUnit\Framework\TestCase;

/**
 * The page a webmention is aimed at: either a page a controller serves (its name, route and the route's arguments, handed to the page's receiver)
 * or a legacy page, which receives through the closure that builds it.
 */
final class WebmentionTargetTest extends TestCase
{
    private function mention(): ReceivedWebmention
    {
        return new ReceivedWebmention('https://a.example/post', 'https://example.com/x', ['response' => 200, 'content' => '<a href="https://example.com/x">x</a>'], ['items' => []]);
    }

    public function testALegacyTargetReceivesThroughItsClosure(): void
    {
        $seen = [];
        $target = new WebmentionTarget('Plugin\Page', '', ['abc'], static function (ReceivedWebmention $mention) use (&$seen): bool {
            $seen[] = $mention;

            return true;
        });

        $this->assertTrue($target->isLegacy());
        $this->assertSame('Plugin\Page', $target->page);
        $this->assertSame('', $target->route);
        $this->assertSame(['abc'], $target->arguments);
        $this->assertTrue($target->receiveLegacy($this->mention()));
        $this->assertEquals([$this->mention()], $seen);
    }

    public function testALegacyTargetsAnswerIsWhatItsPageSaid(): void
    {
        $target = new WebmentionTarget('Plugin\Page', '', [], static fn (): bool => false);

        $this->assertFalse($target->receiveLegacy($this->mention()));
    }

    public function testASiteTargetIsNotLegacy(): void
    {
        $target = new WebmentionTarget('Idno\Pages\Homepage', 'idno_home', []);

        $this->assertFalse($target->isLegacy());
        $this->assertSame('idno_home', $target->route);
    }

    public function testASiteTargetHasNoLegacyPageToReceive(): void
    {
        $this->expectException(\LogicException::class);

        (new WebmentionTarget('Idno\Pages\Homepage', 'idno_home', []))->receiveLegacy($this->mention());
    }

    public function testAReceivedWebmentionHoldsWhatTheEndpointFetched(): void
    {
        $mention = $this->mention();

        $this->assertSame('https://a.example/post', $mention->source);
        $this->assertSame('https://example.com/x', $mention->target);
        $this->assertSame(200, $mention->sourceResponse['response']);
        $this->assertSame(['items' => []], $mention->sourceMf2);
    }
}
