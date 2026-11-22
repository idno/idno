<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TemplateName;
use PHPUnit\Framework\TestCase;

final class TemplateNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['only-core', 'only-core'],
            'nested' => ['entity/default', 'entity/default'],
            'underscore and digits' => ['a_b-1/c', 'a_b-1/c'],
            'a leading slash is accepted' => ['/only-core', '/only-core'],
            'the prefix stops at the hyphen' => ['_ab-tail', '-tail'],
            'the prefix takes letters and slashes up to the hyphen' => ['_ABC/only-core', '-core'],
            'the prefix takes a letter, the slash and the letters after it' => ['_A/only-core', '-core'],
            'the prefix takes a name of letters whole' => ['_onlycore', null],
            'the prefix takes a name of letters and slashes whole' => ['_ABC/onlycore', null],
            'path traversal' => ['../x', null],
            'path traversal inside' => ['a/../x', null],
            'a dot' => ['only-core.php', null],
            'a space' => ['only core', null],
            'a colon' => ['a:b', null],
            'empty' => ['', null],
            'a backslash' => ['a\\b', null],
            'a null byte' => ["a\0b", null],
        ];
    }

    /**
     * @dataProvider names
     */
    public function testNormalise(string $name, ?string $expected): void
    {
        $this->assertSame($expected, TemplateName::normalise($name));
    }

    public function testATrailingNewlineIsNotRefused(): void
    {
        $this->assertSame("only-core\n", TemplateName::normalise("only-core\n"), 'the pattern is the one the engine has always had: it names no file');
    }

    public function testIsSafe(): void
    {
        $this->assertTrue(TemplateName::isSafe('a/b-c_d'));
        $this->assertFalse(TemplateName::isSafe('a/../b'));
        $this->assertFalse(TemplateName::isSafe(''));
        $this->assertFalse(TemplateName::isSafe('a.b'));
    }
}
