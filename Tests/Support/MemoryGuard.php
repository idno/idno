<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\AfterTestErrorHook;
use PHPUnit\Runner\AfterTestFailureHook;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * A PHPUnit extension (phpunit.xml) that watches the memory of the one process the whole suite runs in.
 *
 * Around each test class (its bounds are the suite events `MemoryGuardListener` forwards: they fall before
 * the class's `setUpBeforeClass()` and after its `tearDownAfterClass()`, which the test hooks do not) it
 * records the memory in use at the start and, after a garbage collection, at the end: what the class left
 * behind, what it allocated in `setUpBeforeClass()` included. When the run ends it fails the run (exit status 1,
 * and a message on standard error naming the five classes that left the most behind) if the peak is over the
 * limit, so that a test class that keeps what it makes alive is found the day it is added, not when the
 * suite dies of memory_limit mid-run.
 *
 * IDNO_TEST_MEMORY_LIMIT_MB changes the limit (default LIMIT_MB; 0 turns the failure off; anything that is not a
 * whole number of megabytes stops the run before it starts: it never turns the failure off by accident);
 * IDNO_TEST_MEMORY_REPORT=<file> writes every class's figures as JSON, for ranking a whole run.
 */
final class MemoryGuard implements BeforeTestHook, AfterTestHook, AfterTestErrorHook, AfterTestFailureHook, AfterLastTestHook
{
    /** The peak, in MiB, that fails a run: the suite's own peak is well under it (see AGENTS.md). */
    public const LIMIT_MB = 380;

    /** @var array<string, array{tests: int, start: int, kept: int}> */
    private static array $classes = [];

    private static ?string $current = null;

    private static int $start = 0;

    private static int $tests = 0;

    private static bool $errored = false;

    private readonly int $limit;

    public function __construct()
    {
        try {
            $this->limit = self::limitFrom(getenv('IDNO_TEST_MEMORY_LIMIT_MB'));
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }

    /**
     * The limit in MiB the environment asks for: the default when it names none, 0 for no limit.
     *
     * @throws \InvalidArgumentException for a value that is not a whole number
     */
    public static function limitFrom(string|false $value): int
    {
        $value = $value === false ? '' : trim($value);
        if ($value === '') {
            return self::LIMIT_MB;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('IDNO_TEST_MEMORY_LIMIT_MB must be a whole number of megabytes (0 turns the memory check off), not "%s".', substr($value, 0, 40)));
        }

        return (int) $value;
    }

    /**
     * The exit status of a run the memory check fails: PHPUnit's own (2 when a test errored, else 1) and never 0. An error that no test reports
     * (one from `setUpBeforeClass()` or a data provider, which is an error to PHPUnit and sets no `AfterTestError`) is not seen here: the status is
     * then 1, in place of PHPUnit's 2, which the memory failure and its message make no less a failed run.
     */
    public static function exitStatus(bool $errored): int
    {
        return $errored ? 2 : 1;
    }

    /** A class's tests are about to start: called with the name of each suite PHPUnit starts. */
    public static function suiteStarted(string $name): void
    {
        if (self::$current === null && class_exists($name, false)) {
            self::$current = $name;
            self::$start = memory_get_usage();
            self::$tests = 0;
        }
    }

    /** A class's tests, and its tearDownAfterClass(), are over. */
    public static function suiteEnded(string $name): void
    {
        if (self::$current === $name) {
            self::close();
        }
    }

    public function executeBeforeTest(string $test): void
    {
    }

    public function executeAfterTest(string $test, float $time): void
    {
        self::$tests++;
    }

    public function executeAfterTestError(string $test, string $message, float $time): void
    {
        self::$errored = true;
    }

    public function executeAfterTestFailure(string $test, string $message, float $time): void
    {
    }

    public function executeAfterLastTest(): void
    {
        self::close();
        $report = getenv('IDNO_TEST_MEMORY_REPORT');
        if (is_string($report) && $report !== '') {
            file_put_contents($report, json_encode(['peak' => memory_get_peak_usage(true), 'classes' => self::$classes], JSON_PRETTY_PRINT));
        }
        $verdict = self::verdict(self::$classes, memory_get_peak_usage(true), $this->limit);
        if ($verdict !== null) {
            fwrite(STDERR, "\n" . $verdict . "\n");
            $status = self::exitStatus(self::$errored);
            // PHPUnit exits with its own status after this hook: a shutdown function has the last word.
            register_shutdown_function(static function () use ($status): void {
                exit($status);
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
        $kept = max(0, memory_get_usage() - self::$start);
        $before = self::$classes[self::$current] ?? ['tests' => 0, 'start' => self::$start, 'kept' => 0];
        // A class whose tests are not run together (a random order) is closed more than once: its figures add up.
        self::$classes[self::$current] = ['tests' => $before['tests'] + self::$tests, 'start' => $before['start'], 'kept' => $before['kept'] + $kept];
        self::$current = null;
    }
}
