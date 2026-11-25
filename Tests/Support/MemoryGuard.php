<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * A PHPUnit extension (phpunit.xml) that watches the memory of the one process the whole suite runs in.
 *
 * Around each test class it records the memory in use before the class's first test and, after a garbage
 * collection, after its last: what the class left behind. When the run ends it fails the run (exit status 1,
 * and a message on standard error naming the five classes that left the most behind) if the peak is over the
 * limit, so that a test class that keeps what it makes alive is found the day it is added, not when the
 * suite dies of memory_limit mid-run.
 *
 * IDNO_TEST_MEMORY_LIMIT_MB changes the limit (default LIMIT_MB; 0 turns the failure off);
 * IDNO_TEST_MEMORY_REPORT=<file> writes every class's figures as JSON, for ranking a whole run.
 */
final class MemoryGuard implements BeforeTestHook, AfterTestHook, AfterLastTestHook
{
    /** The peak, in MiB, that fails a run: the suite's own peak is well under it (see AGENTS.md). */
    public const LIMIT_MB = 380;

    /** @var array<string, array{tests: int, start: int, kept: int}> */
    private static array $classes = [];

    private static ?string $current = null;

    private static int $start = 0;

    private static int $tests = 0;

    public function executeBeforeTest(string $test): void
    {
        $class = explode('::', $test, 2)[0];
        if ($class !== self::$current) {
            self::close();
            self::$current = $class;
            self::$start = memory_get_usage();
            self::$tests = 0;
        }
    }

    public function executeAfterTest(string $test, float $time): void
    {
        self::$tests++;
    }

    public function executeAfterLastTest(): void
    {
        self::close();
        $report = getenv('IDNO_TEST_MEMORY_REPORT');
        if (is_string($report) && $report !== '') {
            file_put_contents($report, json_encode(['peak' => memory_get_peak_usage(true), 'classes' => self::$classes], JSON_PRETTY_PRINT));
        }
        $limit = getenv('IDNO_TEST_MEMORY_LIMIT_MB');
        $verdict = self::verdict(self::$classes, memory_get_peak_usage(true), $limit === false || $limit === '' ? self::LIMIT_MB : (int) $limit);
        if ($verdict !== null) {
            fwrite(STDERR, "\n" . $verdict . "\n");
            // PHPUnit exits with its own status after this hook: a shutdown function has the last word.
            register_shutdown_function(static function (): void {
                exit(1);
            });
        }
    }

    /**
     * The message that fails a run, or null when the peak is within the limit.
     *
     * @param array<string, array{tests: int, start: int, kept: int}> $classes
     */
    public static function verdict(array $classes, int $peakBytes, int $limitMegabytes): ?string
    {
        if ($limitMegabytes <= 0 || $peakBytes <= $limitMegabytes * 1048576) {
            return null;
        }
        uasort($classes, static fn (array $a, array $b): int => $b['kept'] <=> $a['kept']);
        $lines = [];
        foreach (array_slice($classes, 0, 5, true) as $class => $figures) {
            $lines[] = sprintf('  %6.1f MB kept by %s (%d tests)', $figures['kept'] / 1048576, $class, $figures['tests']);
        }

        return sprintf(
            "The suite's memory peaked at %.0f MB, over the %d MB the run is allowed (Tests\\Support\\MemoryGuard).\n"
            . "A test class that keeps what it makes alive (a property of the test case, a static, a listener, a kernel)\n"
            . "adds to the one process every test runs in. The classes that left the most behind:\n%s\n"
            . 'Find what holds it and release it; IDNO_TEST_MEMORY_REPORT=<file> writes every class\'s figures.',
            $peakBytes / 1048576,
            $limitMegabytes,
            implode("\n", $lines)
        );
    }

    private static function close(): void
    {
        if (self::$current === null) {
            return;
        }
        gc_collect_cycles();
        self::$classes[self::$current] = ['tests' => self::$tests, 'start' => self::$start, 'kept' => max(0, memory_get_usage() - self::$start)];
        self::$current = null;
    }
}
