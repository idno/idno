<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TemplateName;
use Idno\Templating\TypeChain;
use PHPUnit\Framework\TestCase;

final class TypeChainTest extends TestCase
{
    public function testAWebPageTriesModernThenDefault(): void
    {
        $this->assertSame(['modern', 'default'], TypeChain::for('default'));
        $this->assertSame(['modern', 'default'], TypeChain::for('modern'), '`modern` is the web page too');
    }

    public function testAnyOtherTypeIsTriedBeforeClassicAndDefault(): void
    {
        $this->assertSame(['rss', 'classic', 'default'], TypeChain::for('rss'));
        $this->assertSame(['email-text', 'classic', 'default'], TypeChain::for('email-text'));
        $this->assertSame(['json', 'classic', 'default'], TypeChain::for('json'));
    }

    /**
     * A type is a directory name, and on a file system that ignores case (macOS, Windows) `DEFAULT` is the directory `default`
     * and `CLASSIC` the directory `classic`: the names are compared in lower case, so no spelling reaches `classic` for a page.
     */
    public function testTheNamesAreComparedInLowerCase(): void
    {
        $this->assertSame(['modern', 'default'], TypeChain::for('DEFAULT'));
        $this->assertSame(['modern', 'default'], TypeChain::for('Modern'));
        $this->assertFalse(TypeChain::selectable('CLASSIC'));
        $this->assertFalse(TypeChain::selectable('Classic'));
    }

    public function testClassicIsNeverTriedTwice(): void
    {
        $this->assertSame(['classic', 'default'], TypeChain::for('classic'));
    }

    public function testEveryTypeButClassicIsSelectable(): void
    {
        $this->assertTrue(TypeChain::selectable('default'));
        $this->assertTrue(TypeChain::selectable('modern'));
        $this->assertTrue(TypeChain::selectable('rss'));
        $this->assertTrue(TypeChain::selectable('email-text'));
        $this->assertFalse(TypeChain::selectable('classic'), 'the output that is not a web page reaches classic by fallback only');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notTypes(): array
    {
        return ['trailing slash' => ['classic/'], 'upper case with a slash' => ['CLASSIC/'], 'default with a slash' => ['default/'], 'leading slash' => ['/classic'], 'rss with a slash' => ['rss/'], 'nested' => ['a/b'], 'traversal' => ['../classic'], 'empty' => [''], 'newline' => ["classic\n"]];
    }

    /**
     * A template type is exactly one segment: `classic/` is the directory `classic` once the path is built, so it must never be
     * selectable, and a chain is never made from it.
     *
     * @dataProvider notTypes
     */
    public function testATypeIsOneSegment(string $type): void
    {
        $this->assertFalse(TemplateName::isType($type));
        $this->assertNull(TemplateName::normaliseType($type));
        $this->assertFalse(TypeChain::selectable($type));
        $this->assertFalse(TypeChain::requestable($type));
        $this->assertSame([], TypeChain::for($type));
    }

    public function testASingleSegmentIsAType(): void
    {
        foreach (['default', 'email-text', 'a_b', 'A1'] as $type) {
            $this->assertTrue(TemplateName::isType($type), $type);
            $this->assertSame($type, TemplateName::normaliseType($type), $type);
        }
    }

    /**
     * What a request may choose with `?_t=` is not what code may set: the mail types are set by the mail sender, and a plain-text
     * mail is not escaped, so a web page must never be drawn from it.
     */
    public function testAMailTypeIsNotRequestableButIsSelectable(): void
    {
        foreach (['email', 'email-text', 'EMAIL', 'Email-Text'] as $type) {
            $this->assertFalse(TypeChain::requestable($type), $type);
            $this->assertTrue(TypeChain::selectable($type), $type);
        }
        $this->assertTrue(TypeChain::requestable('rss'));
        $this->assertTrue(TypeChain::requestable('default'));
        $this->assertFalse(TypeChain::requestable('classic'));
    }

    /**
     * A request may ask any page for its Markdown form, and `md` is the short name of the type.
     */
    public function testMarkdownIsRequestableAndMdIsItsShortName(): void
    {
        $this->assertTrue(TypeChain::requestable('markdown'));
        $this->assertTrue(TypeChain::selectable('markdown'));
        $this->assertSame(['markdown', 'classic', 'default'], TypeChain::for('markdown'));
        $this->assertSame('markdown', TypeChain::requested('md'));
        $this->assertSame('markdown', TypeChain::requested('MD'));
        $this->assertSame('markdown', TypeChain::requested('markdown'));
        $this->assertSame('rss', TypeChain::requested('rss'));
        $this->assertSame('mdx', TypeChain::requested('mdx'));
        $this->assertSame('', TypeChain::requested(''));
    }
}
