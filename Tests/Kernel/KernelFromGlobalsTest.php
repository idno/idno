<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Kernel::fromGlobals() is the one place the entry points (index.php, Idno/start.php,
 * idno.php) read APP_ENV and APP_DEBUG.
 */
final class KernelFromGlobalsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG'] as $name) {
            if (array_key_exists($name, $_SERVER)) {
                $this->saved[$name] = $_SERVER[$name];
            }
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_ENV'], $_SERVER['APP_DEBUG']);
        foreach ($this->saved as $name => $value) {
            $_SERVER[$name] = $value;
        }
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function debugValues(): array
    {
        return [
            "'1'" => ['1', true],
            "'true'" => ['true', true],
            "'on'" => ['on', true],
            "'0'" => ['0', false],
            "'false'" => ['false', false],
            "'off'" => ['off', false],
            "''" => ['', false],
            'unset' => [null, false],
        ];
    }

    /**
     * @dataProvider debugValues
     */
    public function testDebugFlag(?string $value, bool $expected): void
    {
        $_SERVER['APP_ENV'] = 'test';
        unset($_SERVER['APP_DEBUG']);
        if ($value !== null) {
            $_SERVER['APP_DEBUG'] = $value;
        }

        $this->assertSame($expected, Kernel::fromGlobals()->isDebug());
    }

    public function testEnvironmentDefaultsToProd(): void
    {
        unset($_SERVER['APP_ENV']);

        $this->assertSame('prod', Kernel::fromGlobals()->getEnvironment());
    }

    public function testEnvironmentIsRead(): void
    {
        $_SERVER['APP_ENV'] = 'test';

        $this->assertSame('test', Kernel::fromGlobals()->getEnvironment());
    }

    /**
     * The entry points' kernel boots the legacy application, subject to the schema gate (which
     * boot() asks: SchemaGateBootTest); before boot() it has not booted it.
     */
    public function testTheEntryPointsKernelBootsTheLegacyApplication(): void
    {
        $_SERVER['APP_ENV'] = 'test';
        $_SERVER['APP_DEBUG'] = '1';

        $kernel = Kernel::fromGlobals();
        $this->assertTrue($kernel->bootsLegacy());
        $this->assertFalse($kernel->legacyBooted());
        $this->assertSame(['test', true], [$kernel->getEnvironment(), $kernel->isDebug()]);
    }
}
