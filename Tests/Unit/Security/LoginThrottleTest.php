<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\LoginThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Clock\MockClock;

final class LoginThrottleTest extends TestCase
{
    private function throttle(?MockClock $clock = null, ?ArrayAdapter &$pool = null): LoginThrottle
    {
        // The limiter reads the real clock for its counting; the pool's clock decides when a window's state is forgotten.
        $pool = new ArrayAdapter(0, true, 0, 0, $clock);

        return new LoginThrottle($pool, static fn (): int => 0);
    }

    private function attempts(LoginThrottle $throttle, string $key, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $throttle->attempt($key);
        }
    }

    public function testFiveAttemptsAreAcceptedAndTheSixthIsNot(): void
    {
        $throttle = $this->throttle();

        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($throttle->attempt('ada'), 'attempt ' . $i);
        }
        $this->assertFalse($throttle->attempt('ada'), 'the sixth is refused');
        $this->assertFalse($throttle->attempt('ada'), 'and so is the seventh');
    }

    public function testTheWindowExpires(): void
    {
        $clock = new MockClock();
        $throttle = $this->throttle($clock);
        $this->attempts($throttle, 'ada', 5);
        $this->assertFalse($throttle->attempt('ada'));

        $clock->sleep(LoginThrottle::WINDOW_SECONDS - 1);
        $this->assertFalse($throttle->attempt('ada'), 'still inside the window');
        $clock->sleep(2);
        $this->assertTrue($throttle->attempt('ada'), 'forgotten when the window is over');
    }

    public function testResetClears(): void
    {
        $throttle = $this->throttle();
        $this->attempts($throttle, 'ada', 5);

        $throttle->reset('ada');

        $this->assertTrue($throttle->attempt('ada'));
        $this->attempts($throttle, 'ada', 3);
        $this->assertTrue($throttle->attempt('ada'), 'the count began again: the fifth after the reset');
        $this->assertFalse($throttle->attempt('ada'));
    }

    public function testOtherKeysAreUnaffected(): void
    {
        $throttle = $this->throttle();
        $this->attempts($throttle, 'ada', 6);

        $this->assertTrue($throttle->attempt('grace'));
    }

    public function testTheKeysOfAnAccountAndOfAnUnknownNameNeverMeet(): void
    {
        $this->assertNotSame(LoginThrottle::keyForAccount('ada'), LoginThrottle::keyForUnknownName('ada'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', LoginThrottle::keyForAccount('urn:x'));
    }

    public function testAnUnknownNameIsFoldedByCaseWhiteSpaceAndUnicodeForm(): void
    {
        $key = LoginThrottle::keyForUnknownName('Ada');

        $this->assertSame($key, LoginThrottle::keyForUnknownName(' ADA '));
        $this->assertSame($key, LoginThrottle::keyForUnknownName("ada\n"));
        $this->assertSame(LoginThrottle::keyForUnknownName("caf\u{e9}"), LoginThrottle::keyForUnknownName("cafe\u{301}"), 'composed and decomposed are one name');
        $this->assertSame(LoginThrottle::keyForUnknownName("\u{ff21}da"), $key, 'a full-width A is an A (NFKC)');
        $this->assertNotSame($key, LoginThrottle::keyForUnknownName('grace'));
    }

    public function testTheKeyIsNeverTheNameInThePool(): void
    {
        $pool = null;
        $throttle = $this->throttle(null, $pool);
        $throttle->attempt(LoginThrottle::keyForUnknownName('ada@example.com'));

        $keys = array_keys((new \ReflectionProperty($pool, 'values'))->getValue($pool));
        $this->assertCount(1, $keys);
        $this->assertStringNotContainsString('ada', (string) $keys[0]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (string) $keys[0]);
    }

    public function testADisabledCacheThrottlesNothing(): void
    {
        $throttle = new LoginThrottle(new NullAdapter(), static fn (): int => 0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($throttle->attempt('ada'));
        }
    }

    public function testThePoolIsPrunedAboutOnceInEveryFewHundredAttempts(): void
    {
        $pool = new class () extends ArrayAdapter implements PruneableInterface {
            public int $pruned = 0;

            public function prune(): bool
            {
                $this->pruned++;

                return true;
            }
        };
        $draws = [250, 3, 1, 7];
        $throttle = new LoginThrottle($pool, static function (int $min, int $max) use (&$draws): int {
            return array_shift($draws) ?? 2;
        });

        for ($i = 0; $i < 4; $i++) {
            $throttle->attempt('key' . $i);
        }

        $this->assertSame(1, $pool->pruned, 'only the draw of 1 prunes');
    }

    public function testAPoolThatCannotPruneIsLeftAlone(): void
    {
        $pool = $this->createMock(AdapterInterface::class);
        $throttle = new LoginThrottle($pool, static fn (): int => 1);

        $this->expectNotToPerformAssertions();
        try {
            $throttle->attempt('x');
        } catch (\Throwable) {
            // the mock's items are not real: only that nothing asks it to prune matters here
        }
    }
}
