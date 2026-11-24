<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\LoginThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Clock\MockClock;

final class LoginThrottleTest extends TestCase
{
    private function throttle(?MockClock $clock = null, ?ArrayAdapter &$pool = null): LoginThrottle
    {
        // The limiter reads the real clock for its counting; the pool's clock decides when a window's state is forgotten.
        $pool = new ArrayAdapter(0, true, 0, 0, $clock);

        return new LoginThrottle($pool);
    }

    private function failures(LoginThrottle $throttle, string $login, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $throttle->recordFailure($login);
        }
    }

    public function testASiteStartsOpen(): void
    {
        $this->assertTrue($this->throttle()->allows('ada'));
    }

    public function testSixthFailureIsRefused(): void
    {
        $throttle = $this->throttle();

        $this->failures($throttle, 'ada', 4);
        $this->assertTrue($throttle->allows('ada'), 'four failures still leave an attempt');
        $throttle->recordFailure('ada');
        $this->assertFalse($throttle->allows('ada'), 'after five failures the sixth attempt is refused');
        $this->assertFalse($throttle->allows('ada'), 'asking does not use anything up');
    }

    public function testTheWindowExpires(): void
    {
        $clock = new MockClock();
        $throttle = $this->throttle($clock);
        $this->failures($throttle, 'ada', 5);
        $this->assertFalse($throttle->allows('ada'));

        $clock->sleep(LoginThrottle::WINDOW_SECONDS - 1);
        $this->assertFalse($throttle->allows('ada'), 'still inside the window');
        $clock->sleep(2);
        $this->assertTrue($throttle->allows('ada'), 'forgotten when the window is over');
    }

    public function testResetClears(): void
    {
        $throttle = $this->throttle();
        $this->failures($throttle, 'ada', 5);

        $throttle->reset('ada');

        $this->assertTrue($throttle->allows('ada'));
        $this->failures($throttle, 'ada', 4);
        $this->assertTrue($throttle->allows('ada'), 'the count began again');
    }

    public function testLoginsDifferingOnlyInCaseShareACount(): void
    {
        $throttle = $this->throttle();

        $this->failures($throttle, 'Ada', 2);
        $this->failures($throttle, ' ADA ', 2);
        $this->failures($throttle, "ada\n", 1);

        $this->assertFalse($throttle->allows('ada'));
        $this->assertFalse($throttle->allows('aDa'));
    }

    public function testOtherLoginsAreUnaffected(): void
    {
        $throttle = $this->throttle();
        $this->failures($throttle, 'ada', 5);

        $this->assertTrue($throttle->allows('grace'));
        $this->assertTrue($throttle->allows('ada@example.com'), 'a handle and an email address of the same account are two logins');
    }

    public function testTheLoginIsNeverAKeyOfThePool(): void
    {
        $pool = null;
        $throttle = $this->throttle(null, $pool);
        $this->failures($throttle, 'ada@example.com', 1);

        $keys = array_keys((new \ReflectionProperty($pool, 'values'))->getValue($pool));
        $this->assertCount(1, $keys);
        $this->assertStringNotContainsString('ada', (string) $keys[0]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (string) $keys[0]);
        $this->assertSame(hash('sha256', 'ada@example.com'), LoginThrottle::keyFor(' Ada@Example.com '));
    }

    public function testADisabledCacheThrottlesNothing(): void
    {
        $throttle = new LoginThrottle(new NullAdapter());

        $this->failures($throttle, 'ada', 50);

        $this->assertTrue($throttle->allows('ada'));
    }
}
