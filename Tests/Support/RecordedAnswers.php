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
 *
 * What a recording may hold. The comparison side normalises what varies: the site's address (in the body AND in every header value: `Location`,
 * `Link`, `X-Idno-Forward-Trace`, ...), the form token of an `__bTk` input and its time, and `Content-Length` is recorded only as whether it equals
 * the byte length of the answer's own body (the body's length varies with the digits of the site's port). `Date` and `Set-Cookie` are never
 * recorded (they differ by nature: a test never compares two separately generated times). A capture additionally REFUSES to write a recording that
 * holds a 64-digit hex run (a form token or other secret in markup the normaliser does not know) or the value of a cookie the response set (a session
 * id). Header names are folded to lower case and values are trimmed: a difference only in letter case of a name or in surrounding white space is
 * not a difference.
 */
trait RecordedAnswers
{
    /** @var array<string, string> the captures of this run: file => text */
    private static array $recordedCaptures = [];

    /** @var list<string> files a verify capture has compared and found the same */
    private static array $recordedVerified = [];

    /** @var list<string> the differences a verify capture found, each naming its file */
    private static array $recordedDifferences = [];

    /** @var array<string, true> the CHANGED cases this class has met */
    private static array $recordedChangedSeen = [];

    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        foreach ([$group, $case] as $part) {
            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $part) !== 1 || str_contains($part, '..')) {
                \PHPUnit\Framework\Assert::fail('A recording is named by a group and a case of lower case letters, digits, dots, hyphens and underscores: ' . $part);
            }
        }
        $mode = static::captureMode();
        if ($mode !== 'compare' && static::ciRefusesCapture()) {
            \PHPUnit\Framework\Assert::fail('IDNO_TEST_CAPTURE_GOLDENS is refused when CI is set: a recording is captured by a person, on purpose.');
        }
        $changed = defined(static::class . '::CHANGED') ? constant(static::class . '::CHANGED') : [];
        if (array_key_exists($case, $changed)) {
            if (trim((string) $changed[$case]) === '') {
                \PHPUnit\Framework\Assert::fail('The case ' . $case . ' is listed in CHANGED with no reason.');
            }
            self::$recordedChangedSeen[$case] = true;

            return;
        }
        $actual = self::normalise($response, $this->baseUrl());
        $name = $group . '/' . $case . '.txt';
        if ($mode !== 'compare') {
            self::refuseSecrets($name, $actual, $response);
        }
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
        // A case recorded again after it was verified (or again in a plain capture) must still be the same answer: a difference is reported
        if (self::$recordedCaptures[$name] !== $actual) {
            self::$recordedDifferences[] = $name . ' is not the same twice:' . "\n" . self::unifiedDiff(self::$recordedCaptures[$name], $actual);
        }
    }

    /** To be called from the using class's `tearDownAfterClass()`. */
    protected static function assertRecordedAnswersComplete(): void
    {
        $mode = static::captureMode();
        $changed = defined(static::class . '::CHANGED') ? constant(static::class . '::CHANGED') : [];
        $unseen = array_diff(array_keys($changed), array_keys(self::$recordedChangedSeen));
        if ($unseen !== []) {
            self::forgetRecordedAnswers();
            \PHPUnit\Framework\Assert::fail('CHANGED lists cases that no test recorded: ' . implode(', ', $unseen));
        }
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
                        if ($at >= 946684800 && $date === false) {
                            throw new \InvalidArgumentException('An Expires in the future cannot be recorded without the Date of the answer.');
                        }
                        $value = $at < 946684800 ? '<past>' : '<future: ' . (int) round(($at - (int) $date) / 86400) . ' days from the request>';
                    }
                } elseif ($name === 'content-length') {
                    $value = $value === (string) strlen($response->body) ? '<the length of the body>' : '<not the length of the body>';
                } else {
                    $value = PageNormaliser::normalise($value, $baseUrl);
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

    /** Whether a capture is refused: when `CI` is set. */
    protected static function ciRefusesCapture(): bool
    {
        return getenv('CI') !== false && getenv('CI') !== '';
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
        self::$recordedChangedSeen = [];
    }

    private static function refuseSecrets(string $name, string $text, SiteResponse $response): void
    {
        if (preg_match('/\b[0-9a-f]{64}\b/i', $text) === 1) {
            \PHPUnit\Framework\Assert::fail($name . ' holds a 64-digit hex value: a form token or another secret in markup the normaliser does not know. It is not written.');
        }
        foreach ($response->lines('set-cookie') as $cookie) {
            $pair = explode(';', $cookie, 2)[0];
            $value = urldecode(substr($pair, (int) strpos($pair, '=') + 1));
            if (strlen($value) >= 16 && str_contains($text, $value)) {
                \PHPUnit\Framework\Assert::fail($name . ' holds the value of a cookie the response set (a session id). It is not written.');
            }
        }
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
