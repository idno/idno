<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Idno;
use PHPUnit\Framework\TestCase;

/**
 * What a visitor may not see (a private post's body) is never kept by the purifier: no entry in the cache pool, and no memory of a text
 * between calls (a queue worker handles many messages in one process).
 */
final class PurifierKeepsNothingTest extends TestCase
{
    public function testPurifyingKeepsNoOutputAnywhere(): void
    {
        $purifier = Idno::site()->template()->purifier;
        $html = '<p onclick="x()">Private ' . bin2hex(random_bytes(6)) . '<script>alert(1)</script></p>';

        $output = $purifier->purify($html);

        $this->assertStringNotContainsString('script', $output);
        $this->assertStringNotContainsString('onclick', $output);
        $this->assertFalse(method_exists($purifier, 'cacheKey'), 'there is no key a text is kept under');
        foreach ((new \ReflectionObject($purifier))->getProperties() as $property) {
            $value = $property->getValue($purifier);
            $this->assertFalse(is_array($value) && in_array($output, $value, true), 'the property ' . $property->getName() . ' holds no output');
        }
        $this->assertNull(Idno::site()->cache()->load('purify_' . hash('sha256', $html)));
    }
}
