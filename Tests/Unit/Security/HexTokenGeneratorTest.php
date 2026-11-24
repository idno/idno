<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\HexTokenGenerator;
use PHPUnit\Framework\TestCase;

final class HexTokenGeneratorTest extends TestCase
{
    public function testATokenIs64LowerCaseHexCharacters(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (new HexTokenGenerator())->generateToken());
    }

    public function testTokensDiffer(): void
    {
        $generator = new HexTokenGenerator();
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[$generator->generateToken()] = true;
        }

        $this->assertCount(50, $seen);
    }
}
