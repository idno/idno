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

    public function testARunOverTheLimitExitsWithStatusOneAndSaysWhy(): void
    {
        $directory = sys_get_temp_dir() . '/idno-memory-guard-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            file_put_contents($directory . '/OneTest.php', "<?php\nfinal class OneTest extends \\PHPUnit\\Framework\\TestCase { public function testOne(): void { \$this->assertTrue(true); } }\n");
            $root = dirname(__DIR__, 3);
            file_put_contents($directory . '/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="' . $root . '/Tests/_bootstrap_unit.php"><extensions><extension class="Tests\\Support\\MemoryGuard"/></extensions><testsuites><testsuite name="t"><directory>' . $directory . '</directory></testsuite></testsuites></phpunit>');

            $run = static function (string $limit) use ($root, $directory): array {
                $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', $root . '/vendor/bin/phpunit', '-c', $directory . '/phpunit.xml'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['IDNO_TEST_MEMORY_LIMIT_MB' => $limit, 'PATH' => (string) getenv('PATH')]);
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                return [proc_close($process), $stdout, $stderr];
            };

            [$exit, , $stderr] = $run('1');
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('Tests\\Support\\MemoryGuard', $stderr);
            $this->assertStringContainsString('OneTest', $stderr);

            [$exit, $stdout] = $run('0');
            $this->assertSame(0, $exit, $stdout);
        } finally {
            foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
                unlink($directory . '/' . $file);
            }
            rmdir($directory);
        }
    }
}
