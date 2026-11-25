<?php

declare(strict_types=1);

namespace Tests\Support;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Tests\Characterisation\SiteResponse;
use Tests\Templating\PageNormaliser;

/**
 * Holds a controller's answer to what a legacy page answered, from a recording of the legacy page's answer: the status, the headers and the
 * body, normalised, in a text file under `Tests/Characterisation/fixtures/answers/<group>/<case>.txt` (`*.json` is gitignored).
 *
 * A using test class calls `assertRecordedAnswer($group, $case, $response)` for each answer and `assertRecordedAnswersComplete()` from its
 * `tearDownAfterClass()`, and provides `baseUrl()` (the site's address, as `PrivateSiteTestCase` does). A case listed in the class's `CHANGED`
 * constant (`array<string, string>`: case => reason) is neither compared nor written: the test asserts it itself, and says why it differs.
 *
 * Capturing. `IDNO_TEST_CAPTURE_GOLDENS=1` writes the files instead of comparing, then the class FAILS naming every file it wrote (a capture is
 * never a green run); it is refused when `CI` is set. The rule for a recording:
 *  - seed every entity, file, annotation and setting the recording shows with fixed `_id`, uuid, slug, `created` and `updated`, so that the answer
 *    is the same every time (`Last-Modified` is kept as sent);
 *  - capture on the commit BEFORE the change that moves the page;
 *  - run the capture twice and require the second to equal the first: `IDNO_TEST_CAPTURE_GOLDENS=verify` compares two captures in one run (the
 *    test records each case twice, on two sites or two runs of its scenario): the first is kept in memory, the second is compared with it, and only
 *    when every case matches are the files written (a difference fails the run and writes nothing);
 *  - commit the recordings in a commit of their own.
 */
trait RecordedAnswers
{
    /** @var array<string, string> the captures of this run: file => text */
    private static array $recordedCaptures = [];

    /** @var list<string> files a verify capture has compared and found the same */
    private static array $recordedVerified = [];

    /** @var list<string> the differences a verify capture found, each naming its file */
    private static array $recordedDifferences = [];

    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        if (defined(static::class . '::CHANGED') && array_key_exists($case, constant(static::class . '::CHANGED'))) {
            return;
        }
        $mode = static::captureMode();
        if ($mode !== 'compare' && getenv('CI') !== false && getenv('CI') !== '') {
            \PHPUnit\Framework\Assert::fail('IDNO_TEST_CAPTURE_GOLDENS is refused when CI is set: a recording is captured by a person, on purpose.');
        }
        $actual = self::normalise($response, $this->baseUrl());
        $name = $group . '/' . $case . '.txt';
        if ($mode === 'compare') {
            $file = static::recordedAnswersDirectory() . '/' . $name;
            if (!is_file($file)) {
                \PHPUnit\Framework\Assert::fail($name . ': there is no recording (capture it with IDNO_TEST_CAPTURE_GOLDENS=1 on the code that answers it today)');
            }
            $expected = (string) file_get_contents($file);
            \PHPUnit\Framework\Assert::assertSame($expected, $actual, $name . " differs from its recording:\n" . self::unifiedDiff($expected, $actual));

            return;
        }
        if (!isset(self::$recordedCaptures[$name])) {
            self::$recordedCaptures[$name] = $actual;

            return;
        }
        if ($mode === 'verify' && !in_array($name, self::$recordedVerified, true)) {
            if (self::$recordedCaptures[$name] === $actual) {
                self::$recordedVerified[] = $name;
            } else {
                self::$recordedDifferences[] = $name . ' is not the same twice:' . "\n" . self::unifiedDiff(self::$recordedCaptures[$name], $actual);
            }

            return;
        }
        // A capture (mode 1) that records a case again keeps the last answer; a verified case's third answer must match too
        if (self::$recordedCaptures[$name] !== $actual) {
            self::$recordedDifferences[] = $name . ' is not the same twice:' . "\n" . self::unifiedDiff(self::$recordedCaptures[$name], $actual);
        }
    }

    /** To be called from the using class's `tearDownAfterClass()`. */
    protected static function assertRecordedAnswersComplete(): void
    {
        $mode = static::captureMode();
        if ($mode === 'compare') {
            return;
        }
        $captures = self::$recordedCaptures;
        $differences = self::$recordedDifferences;
        if ($mode === 'verify') {
            foreach (array_keys($captures) as $name) {
                if (!in_array($name, self::$recordedVerified, true) && !self::hasDifference($name)) {
                    $differences[] = $name . ' was recorded once: a verify capture needs each case twice';
                }
            }
        }
        if ($differences !== []) {
            self::forgetRecordedAnswers();
            \PHPUnit\Framework\Assert::fail("IDNO_TEST_CAPTURE_GOLDENS={$mode}: nothing was written.\n" . implode("\n", $differences));
        }
        $directory = static::recordedAnswersDirectory();
        foreach ($captures as $name => $text) {
            if (!is_dir(dirname($directory . '/' . $name))) {
                mkdir(dirname($directory . '/' . $name), 0775, true);
            }
            file_put_contents($directory . '/' . $name, $text);
        }
        $written = array_keys($captures);
        self::forgetRecordedAnswers();
        \PHPUnit\Framework\Assert::fail('IDNO_TEST_CAPTURE_GOLDENS=' . $mode . ': ' . count($written) . ' files were WRITTEN, not compared, so this run proves nothing: ' . implode(', ', $written) . '. Review git diff, then run again without it.');
    }

    /**
     * The answer as it is recorded: `status <n>`, each header by lower-case name (names sorted, values in the order received) less the ones that
     * vary by nature, then a blank line and the body (through PageNormaliser, as HTML for a `text/html` answer).
     */
    public static function normalise(SiteResponse $response, string $baseUrl): string
    {
        $values = [];
        if ($response->lines !== []) {
            foreach ($response->lines as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $values[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
            }
        } else {
            foreach ($response->headers as $name => $value) {
                $values[strtolower((string) $name)][] = (string) $value;
            }
        }
        $date = isset($values['date'][0]) ? strtotime($values['date'][0]) : false;
        ksort($values);
        $out = 'status ' . $response->status . "\n";
        foreach ($values as $name => $list) {
            if (in_array($name, ['date', 'set-cookie', 'x-idno-build-fingerprint', 'connection', 'keep-alive', 'host'], true)) {
                continue;
            }
            foreach ($list as $value) {
                if ($name === 'x-powered-by' && $value !== 'https://idno.co') {
                    continue;
                }
                if ($name === 'expires') {
                    $at = strtotime($value);
                    if ($at !== false) {
                        $value = $at < 946684800 ? '<past>' : '<future: ' . (int) round(($at - ($date !== false ? $date : time())) / 86400) . ' days from the request>';
                    }
                }
                $out .= $name . ': ' . $value . "\n";
            }
        }
        $html = str_contains(strtolower(implode(' ', $values['content-type'] ?? [])), 'text/html');

        return $out . "\n" . PageNormaliser::normalise($response->body, $baseUrl, $html);
    }

    /** `compare` (the default), `1` (capture) or `verify`. */
    protected static function captureMode(): string
    {
        return match (getenv('IDNO_TEST_CAPTURE_GOLDENS')) {
            '1' => 'capture',
            'verify' => 'verify',
            default => 'compare',
        };
    }

    protected static function recordedAnswersDirectory(): string
    {
        return dirname(__DIR__) . '/Characterisation/fixtures/answers';
    }

    protected static function forgetRecordedAnswers(): void
    {
        self::$recordedCaptures = [];
        self::$recordedVerified = [];
        self::$recordedDifferences = [];
    }

    private static function hasDifference(string $name): bool
    {
        foreach (self::$recordedDifferences as $difference) {
            if (str_starts_with($difference, $name . ' ')) {
                return true;
            }
        }

        return false;
    }

    private static function unifiedDiff(string $expected, string $actual): string
    {
        return (new Differ(new UnifiedDiffOutputBuilder("--- recorded\n+++ answered\n")))->diff($expected, $actual);
    }
}
