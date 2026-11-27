<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\LoginAttempts;
use Idno\Security\LoginThrottle;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class LoginAttemptsTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $logged = [];

    private function logger(): LoggerInterface
    {
        $this->logged = [];
        $log = &$this->logged;

        return new class ($log) extends AbstractLogger {
            /** @param list<array{string, string}> $log */
            public function __construct(private array &$log)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->log[] = [(string) $level, (string) $message];
            }
        };
    }

    private function attempts(\Closure $throttle): LoginAttempts
    {
        $logger = $this->logger();

        return new LoginAttempts($throttle, static fn (): LoggerInterface => $logger);
    }

    private function real(): LoginAttempts
    {
        $throttle = new LoginThrottle(new ArrayAdapter(), static fn (): int => 0);

        return $this->attempts(static fn (): LoginThrottle => $throttle);
    }

    public function testAnAttemptIsReservedAndTheSixthRefused(): void
    {
        $attempts = $this->real();

        for ($i = 1; $i <= LoginThrottle::LIMIT; $i++) {
            $this->assertTrue($attempts->attempt('ada'), 'attempt ' . $i);
        }
        $this->assertFalse($attempts->attempt('ada'));
        $this->assertTrue($attempts->attempt('bob'), 'another key has its own count');
        $this->assertSame([], $this->logged);
    }

    public function testAThrottleThatCannotBeBuiltRefusesNothingAndWarnsOnce(): void
    {
        $attempts = $this->attempts(static function (): LoginThrottle {
            throw new \RuntimeException('no pool');
        });

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($attempts->attempt('ada'));
        }
        $this->assertSame([['warning', 'The login throttle is unavailable: RuntimeException']], $this->logged);
    }

    public function testResetClearsTheCount(): void
    {
        $attempts = $this->real();
        for ($i = 0; $i < LoginThrottle::LIMIT; $i++) {
            $attempts->attempt('ada');
        }
        $this->assertFalse($attempts->attempt('ada'));

        $attempts->reset('ada');

        $this->assertTrue($attempts->attempt('ada'));
    }

    public function testAResetThatFailsIsLoggedNotThrown(): void
    {
        $attempts = $this->attempts(static function (): LoginThrottle {
            throw new \LogicException('no pool');
        });

        $attempts->reset('ada');

        $this->assertSame([['warning', 'The count of failed logins could not be reset: LogicException']], $this->logged);
    }

    public function testAnAttemptThatThrowsIsWarnedOfAndAllowed(): void
    {
        $attempts = $this->attempts(static fn (): object => new class () {
            public function attempt(string $key): bool
            {
                throw new \DomainException('broken');
            }
        });

        $this->assertTrue($attempts->attempt('ada'));
        $this->assertTrue($attempts->attempt('ada'));
        $this->assertSame([['warning', 'The login throttle is unavailable: DomainException']], $this->logged);
    }
}
