<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Idno;
use PHPUnit\Framework\TestCase;

/**
 * Purifying a text is the most expensive thing a page does for a post's body (about 100 microseconds), and it was done again in every request:
 * the output is kept in the site's cache, keyed by the text, the library's version and the settings that change it.
 */
final class PurifierCacheTest extends TestCase
{
    private function purifier(): \Idno\Core\Purifier
    {
        return Idno::site()->template()->purifier;
    }

    public function testAPurifiedTextIsKeptAndTheKeptOutputIsTheSame(): void
    {
        $purifier = $this->purifier();
        $html = '<p onclick="x()">Hello ' . bin2hex(random_bytes(6)) . '<script>alert(1)</script></p>';
        $key = $purifier->cacheKey($html);
        Idno::site()->cache()->delete($key);

        $first = $purifier->purify($html);

        $this->assertSame($first, Idno::site()->cache()->load($key), 'the output is kept in the cache');
        $this->assertStringNotContainsString('script', $first);
        $this->assertStringNotContainsString('onclick', $first);

        Idno::site()->cache()->store($key, 'from the cache', 60);
        $fresh = clone $purifier;
        (new \ReflectionProperty($fresh, 'memo'))->setValue($fresh, []);
        $this->assertSame('from the cache', $fresh->purify($html), 'a kept output is what a later request gets');
    }

    public function testTheBasicOutputAndTheSettingsHaveKeysOfTheirOwn(): void
    {
        $purifier = $this->purifier();

        $this->assertNotSame($purifier->cacheKey('<p style="color:red">x</p>'), $purifier->cacheKey('<p style="color:red">x</p>', true));
        $this->assertNotSame($purifier->cacheKey('a'), $purifier->cacheKey('b'));
        $this->assertStringNotContainsString('style', $purifier->purify('<p style="color:red">x</p>', true));
    }

    public function testNonTextIsPurifiedAsBefore(): void
    {
        $this->assertSame('', $this->purifier()->purify(''));
    }
}
