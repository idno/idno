<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\WebmentionTargets;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\MentionablePage;
use Tests\Fixtures\ThrowingMentionablePage;

/**
 * A mention that a plugin's legacy page receives: the page is built as the legacy router built it, and building it must leave the request being served as it was:
 * the same current page and the same exception handler, whether the page was built, or its constructor failed.
 */
final class LegacyWebmentionPageTest extends TestCase
{
    private function mention(string $target): ReceivedWebmention
    {
        return new ReceivedWebmention('https://remote.example/a', $target, ['response' => 200, 'content' => $target], ['items' => []]);
    }

    private static function exceptionHandler(): mixed
    {
        $handler = set_exception_handler(static function (): void {
        });
        restore_exception_handler();

        return $handler;
    }

    /** @return array{object, mixed} the current page and the exception handler of the request, as a test leaves them before it asks */
    private function stateBefore(): array
    {
        $base = Idno::site()->config()->getURL();
        Idno::site()->routes()->addRoute('/_test/webmention-legacy/:id/?', MentionablePage::class);
        Idno::site()->routes()->addRoute('/_test/webmention-legacy-throws/:id/?', ThrowingMentionablePage::class);

        return [new \Idno\Legacy\BarePage(), self::exceptionHandler()];
    }

    public function testThePageIsBuiltAndTheRequestIsLeftAsItWas(): void
    {
        [$current] = $this->stateBefore();
        Idno::site()->setCurrentPage($current);
        $handler = self::exceptionHandler();
        $target = Idno::site()->config()->getURL() . '_test/webmention-legacy/abc/';

        $resolved = Idno::container()->get(WebmentionTargets::class)->resolve($target);
        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->isLegacy());
        $this->assertTrue($resolved->receiveLegacy($this->mention($target)));

        $this->assertSame($current, Idno::site()->currentPage(), 'the page the request is serving is still the current page');
        $this->assertSame($handler, self::exceptionHandler(), 'and its exception handler is the one installed');
    }

    public function testAPageWhoseConstructorFailsLeavesTheRequestAsItWas(): void
    {
        [$current] = $this->stateBefore();
        Idno::site()->setCurrentPage($current);
        $handler = self::exceptionHandler();
        $target = Idno::site()->config()->getURL() . '_test/webmention-legacy-throws/abc/';

        $resolved = Idno::container()->get(WebmentionTargets::class)->resolve($target);
        $this->assertNotNull($resolved);
        try {
            $resolved->receiveLegacy($this->mention($target));
            $this->fail('the constructor failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('the constructor fails', $e->getMessage());
        }

        $this->assertSame($current, Idno::site()->currentPage());
        $this->assertSame($handler, self::exceptionHandler(), 'the exception handler the page installed is not left behind');
    }
}
