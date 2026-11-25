<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\MemoryGuard;

final class MemoryGuardTest extends TestCase
{
    private const MB = 1048576;

    /** @return array<string, array{tests: int, start: int, kept: int}> */
    private static function classes(): array
    {
        $kept = ['A' => 1, 'B' => 90, 'C' => 5, 'D' => 40, 'E' => 3, 'F' => 60, 'G' => 20];
        $classes = [];
        foreach ($kept as $name => $megabytes) {
            $classes['Tests\\' . $name . 'Test'] = ['tests' => 2, 'start' => 0, 'kept' => $megabytes * self::MB];
        }

        return $classes;
    }

    public function testAPeakWithinTheLimitIsNoFailure(): void
    {
        $this->assertNull(MemoryGuard::verdict(self::classes(), 380 * self::MB, 380));
    }

    public function testALimitOfZeroTurnsTheFailureOff(): void
    {
        $this->assertNull(MemoryGuard::verdict(self::classes(), 900 * self::MB, 0));
    }

    public function testAPeakOverTheLimitNamesTheFiveClassesThatKeptTheMost(): void
    {
        $message = (string) MemoryGuard::verdict(self::classes(), 400 * self::MB, 380);

        $this->assertStringContainsString('peaked at 400 MB', $message);
        $this->assertStringContainsString('over the 380 MB', $message);
        $positions = [];
        foreach (['BTest', 'FTest', 'DTest', 'GTest', 'CTest'] as $name) {
            $this->assertStringContainsString('Tests\\' . $name, $message);
            $positions[] = strpos($message, 'Tests\\' . $name);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the classes are listed from the one that kept the most');
        $this->assertStringNotContainsString('Tests\\ETest', $message);
        $this->assertStringNotContainsString('Tests\\ATest', $message);
    }

    public function testTheLimitComesFromTheEnvironmentOrTheDefault(): void
    {
        $this->assertSame(MemoryGuard::LIMIT_MB, MemoryGuard::limitFrom(false));
        $this->assertSame(MemoryGuard::LIMIT_MB, MemoryGuard::limitFrom(''));
        $this->assertSame(0, MemoryGuard::limitFrom('0'));
        $this->assertSame(450, MemoryGuard::limitFrom(' 450 '));
    }

    /** @dataProvider notNumbers */
    public function testALimitThatIsNotAWholeNumberIsAnErrorNotAnOffSwitch(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IDNO_TEST_MEMORY_LIMIT_MB');
        MemoryGuard::limitFrom($value);
    }

    /** @return array<string, array{string}> */
    public static function notNumbers(): array
    {
        return ['letters' => ['abc'], 'negative' => ['-5'], 'fraction' => ['1.5'], 'unit' => ['400MB'], 'hex' => ['0x10']];
    }

    public function testAFailingRunKeepsItsOwnExitStatusAndAClearOneBecomesOne(): void
    {
        $this->assertSame(2, MemoryGuard::exitStatus(true, true));
        $this->assertSame(2, MemoryGuard::exitStatus(true, false));
        $this->assertSame(1, MemoryGuard::exitStatus(false, true));
        $this->assertSame(1, MemoryGuard::exitStatus(false, false));
    }

    public function testARunOverTheLimitExitsWithStatusOneAndSaysWhy(): void
    {
        $this->inTemporaryDirectory(['OneTest.php' => self::testClass('OneTest', 'public function testOne(): void { $this->assertTrue(true); }')], function (string $directory): void {
            [$exit, , $stderr] = $this->runPhpUnit($directory, ['IDNO_TEST_MEMORY_LIMIT_MB' => '1']);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('Tests\\Support\\MemoryGuard', $stderr);
            $this->assertStringContainsString('OneTest', $stderr);

            [$exit, $stdout] = $this->runPhpUnit($directory, ['IDNO_TEST_MEMORY_LIMIT_MB' => '0']);
            $this->assertSame(0, $exit, $stdout);
        });
    }

    public function testAnErroringRunOverTheLimitStillExitsWithPhpUnitsStatusOfTwo(): void
    {
        $this->inTemporaryDirectory(['ErrorTest.php' => self::testClass('ErrorTest', 'public function testError(): void { throw new \\RuntimeException("boom"); }')], function (string $directory): void {
            [$exit, , $stderr] = $this->runPhpUnit($directory, ['IDNO_TEST_MEMORY_LIMIT_MB' => '1']);
            $this->assertSame(2, $exit);
            $this->assertStringContainsString('MemoryGuard', $stderr);
        });
    }

    public function testAFailingRunOverTheLimitExitsWithOne(): void
    {
        $this->inTemporaryDirectory(['FailTest.php' => self::testClass('FailTest', 'public function testFail(): void { $this->assertTrue(false); }')], function (string $directory): void {
            [$exit] = $this->runPhpUnit($directory, ['IDNO_TEST_MEMORY_LIMIT_MB' => '1']);
            $this->assertSame(1, $exit);
        });
    }

    public function testALimitThatIsNotANumberStopsTheRunBeforeAnyTest(): void
    {
        $this->inTemporaryDirectory(['OneTest.php' => self::testClass('OneTest', 'public function testOne(): void { $this->assertTrue(true); }')], function (string $directory): void {
            [$exit, $stdout, $stderr] = $this->runPhpUnit($directory, ['IDNO_TEST_MEMORY_LIMIT_MB' => 'abc']);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('IDNO_TEST_MEMORY_LIMIT_MB', $stderr);
            $this->assertStringNotContainsString('OK (', $stdout);
        });
    }

    public function testWhatAClassAllocatesInItsSetUpBeforeClassIsChargedToItselfNotToThePreviousClass(): void
    {
        $files = [
            'AFirstTest.php' => self::testClass('AFirstTest', 'public function testOne(): void { $this->assertTrue(true); }'),
            'BHoldsTest.php' => self::testClass('BHoldsTest', 'private static array $held = []; public static function setUpBeforeClass(): void { self::$held = array_fill(0, 1000000, "x"); } public function testOne(): void { $this->assertTrue(true); }'),
            'CFreesTest.php' => self::testClass('CFreesTest', 'private static array $held = []; public static function setUpBeforeClass(): void { self::$held = array_fill(0, 1000000, "y"); } public static function tearDownAfterClass(): void { self::$held = []; } public function testOne(): void { $this->assertTrue(true); }'),
            'DLastTest.php' => self::testClass('DLastTest', 'public function testOne(): void { $this->assertTrue(true); }'),
        ];
        $this->inTemporaryDirectory($files, function (string $directory): void {
            $report = $directory . '/report.json';
            [$exit, $stdout] = $this->runPhpUnit($directory, ['IDNO_TEST_MEMORY_REPORT' => $report]);
            $this->assertSame(0, $exit, $stdout);
            $classes = json_decode((string) file_get_contents($report), true)['classes'];

            $this->assertLessThan(2 * self::MB, $classes['AFirstTest']['kept'], 'the class before it is not charged for what the next one allocates');
            $this->assertGreaterThan(10 * self::MB, $classes['BHoldsTest']['kept'], 'what a class allocates in setUpBeforeClass and keeps is its own');
            $this->assertLessThan(2 * self::MB, $classes['CFreesTest']['kept'], 'what it frees in tearDownAfterClass it did not keep');
            $this->assertLessThan(2 * self::MB, $classes['DLastTest']['kept']);
            $this->assertSame(1, $classes['BHoldsTest']['tests']);
        });
    }

    private static function testClass(string $name, string $body): string
    {
        return "<?php\nfinal class {$name} extends \\PHPUnit\\Framework\\TestCase { {$body} }\n";
    }

    /**
     * @param array<string, string> $files
     */
    private function inTemporaryDirectory(array $files, callable $work): void
    {
        $directory = sys_get_temp_dir() . '/idno-memory-guard-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            foreach ($files as $name => $contents) {
                file_put_contents($directory . '/' . $name, $contents);
            }
            $root = dirname(__DIR__, 3);
            file_put_contents($directory . '/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="' . $root . '/Tests/_bootstrap_unit.php"><extensions><extension class="Tests\\Support\\MemoryGuard"/></extensions><listeners><listener class="Tests\\Support\\MemoryGuardListener"/></listeners><testsuites><testsuite name="t"><directory suffix="Test.php">' . $directory . '</directory></testsuite></testsuites></phpunit>');
            $work($directory);
        } finally {
            foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
                unlink($directory . '/' . $file);
            }
            rmdir($directory);
        }
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{int, string, string}
     */
    private function runPhpUnit(string $directory, array $environment): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', $root . '/vendor/bin/phpunit', '-c', $directory . '/phpunit.xml'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment + ['PATH' => (string) getenv('PATH')]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
