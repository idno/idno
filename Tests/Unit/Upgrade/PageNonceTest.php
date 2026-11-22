<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\TestCase;
use Tests\Upgrade\PageNonce;

final class PageNonceTest extends TestCase
{
    private const PATTERN = '#https?:|//[a-z0-9]#i';

    private const POLICY = "default-src 'none'; script-src 'nonce-yLqr//aGgngsdNLAvCSDlA'; style-src 'nonce-yLqr//aGgngsdNLAvCSDlA'";

    private const BODY = '<style nonce="yLqr//aGgngsdNLAvCSDlA">p{}</style><script nonce="yLqr//aGgngsdNLAvCSDlA">var url = "/_upgrade/step";</script>';

    public function testAPageWithANonceContainingTwoSlashesFailsTheNaiveCheck(): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, self::BODY), 'the check the end-to-end test used to make reads the nonce as a URL');
    }

    public function testTheCheckPassesOnceTheNonceIsRemoved(): void
    {
        $this->assertSame(0, preg_match(self::PATTERN, PageNonce::withoutNonce(self::BODY, self::POLICY)));
    }

    public function testARealUrlIsStillFound(): void
    {
        $body = self::BODY . '<a href="https://evil.example/x">x</a>';
        $this->assertSame(1, preg_match(self::PATTERN, PageNonce::withoutNonce($body, self::POLICY)));
        $this->assertSame(1, preg_match(self::PATTERN, PageNonce::withoutNonce(self::BODY . '<img src="//evil.example/x">', self::POLICY)));
    }
}
