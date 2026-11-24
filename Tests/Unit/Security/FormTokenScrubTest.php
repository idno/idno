<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\FormToken;
use PHPUnit\Framework\TestCase;

final class FormTokenScrubTest extends TestCase
{
    private const TOKEN = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    public function testACsrfKeyIsRemovedWhateverItHolds(): void
    {
        $this->assertSame(['title' => 'x'], FormToken::scrub(['title' => 'x', 'csrf' => [['token' => 'anything']]], self::TOKEN));
    }

    public function testAValueHoldingTheTokenIsRemovedAtAnyDepth(): void
    {
        $vars = [
            'title' => 'x',
            'link' => 'https://example.com/?t=' . self::TOKEN,
            'nested' => ['keep' => 'y', 'drop' => self::TOKEN, 'deeper' => [['a' => strtoupper(self::TOKEN)]]],
            'list' => ['one', self::TOKEN, 'three'],
        ];

        $this->assertSame(['title' => 'x', 'nested' => ['keep' => 'y', 'deeper' => [[]]], 'list' => ['one', 'three']], FormToken::scrub($vars, self::TOKEN));
    }

    public function testAnObjectAndOtherScalarsAreLeftAlone(): void
    {
        $object = new \stdClass();

        $this->assertSame(['o' => $object, 'n' => 5, 'b' => true, 'z' => null], FormToken::scrub(['o' => $object, 'n' => 5, 'b' => true, 'z' => null], self::TOKEN));
    }

    public function testWithNoTokenOnlyTheKeyIsRemoved(): void
    {
        $this->assertSame(['a' => 'b'], FormToken::scrub(['a' => 'b', 'csrf' => 1], ''));
    }
}
