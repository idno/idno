<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Characterisation\SiteResponse;
use Tests\Support\RecordedAnswers;

final class RecordedAnswersTest extends TestCase
{
    private const BASE = 'http://127.0.0.1:55438/';

    /** @param list<string> $lines */
    private static function response(array $lines, string $body = '', int $status = 200): SiteResponse
    {
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . trim($value) : trim($value);
        }

        return new SiteResponse($status, $headers, $body, $lines);
    }

    private function normalise(SiteResponse $response): string
    {
        $probe = new class {
            use RecordedAnswers;
        };

        return $probe::normalise($response, self::BASE);
    }

    public function testTheStatusComesFirstAndHeadersAreSortedByName(): void
    {
        $text = $this->normalise(self::response(['X-Zed: 1', 'Content-Type: text/plain', 'Allow: GET'], 'hi', 404));

        $this->assertSame("status 404\nallow: GET\ncontent-type: text/plain\nx-zed: 1\n\nhi", $text);
    }

    public function testValuesKeepTheirOrderWithinAName(): void
    {
        $text = $this->normalise(self::response(['Link: <b>; rel="b"', 'Vary: Accept', 'Link: <a>; rel="a"']));

        $this->assertStringContainsString("link: <b>; rel=\"b\"\nlink: <a>; rel=\"a\"\nvary: Accept", $text);
    }

    public function testTheVolatileHeadersAreLeftOut(): void
    {
        $text = $this->normalise(self::response([
            'Date: Wed, 25 Nov 2026 10:00:00 GMT', 'Set-Cookie: a=b', 'X-Idno-Build-Fingerprint: abc', 'Connection: close', 'Keep-Alive: timeout=5', 'Host: x',
            'X-Powered-By: PHP/8.4', 'Content-Type: text/plain',
        ]));

        $this->assertSame("status 200\ncontent-type: text/plain\n\n", $text);
        $this->assertStringContainsString('x-powered-by: https://idno.co', $this->normalise(self::response(['X-Powered-By: https://idno.co'])));
    }

    public function testAnExpiresInThePastIsPast(): void
    {
        $text = $this->normalise(self::response(['Date: Wed, 25 Nov 2026 10:00:00 GMT', 'Expires: Thu, 19 Nov 1981 08:52:00 GMT']));

        $this->assertStringContainsString("expires: <past>\n", $text);
    }

    public function testAnExpiresInTheFutureIsCountedInDaysFromTheDate(): void
    {
        $text = $this->normalise(self::response(['Date: Wed, 25 Nov 2026 10:00:00 GMT', 'Expires: Fri, 25 Dec 2026 10:00:00 GMT']));

        $this->assertStringContainsString("expires: <future: 30 days from the request>\n", $text);
    }

    public function testLastModifiedIsKept(): void
    {
        $text = $this->normalise(self::response(['Last-Modified: Tue, 14 Nov 2023 22:13:20 GMT']));

        $this->assertStringContainsString("last-modified: Tue, 14 Nov 2023 22:13:20 GMT\n", $text);
    }

    public function testAnHtmlBodyIsNormalisedAsAPageIs(): void
    {
        $body = "<a href=\"http://127.0.0.1:55438/x\">x</a>\n   <input name=\"__bTk\" value=\"" . str_repeat('a', 64) . '">';
        $text = $this->normalise(self::response(['Content-Type: text/html; charset=utf-8'], $body));

        $this->assertStringContainsString('<a href="{site}/x">x</a>', $text);
        $this->assertStringContainsString('value="{token}"', $text);
        $this->assertStringNotContainsString('55438', $text);
    }

    public function testATextBodyIsNotReflowed(): void
    {
        $text = $this->normalise(self::response(['Content-Type: text/plain'], "a  <b>\n   c\n"));

        $this->assertStringEndsWith("\n\na  <b>\n   c\n", $text);
    }

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-recorded-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') ?: [] as $dir) {
            rmdir($dir);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** A recorder in a mode, in a temporary directory; `$ci` is what the CI hook says (never the environment's). */
    private function recorder(string $mode, bool $ci = false): object
    {
        $recorder = new class {
            use RecordedAnswers;

            public static string $mode = 'compare';
            public static bool $ci = false;
            public static string $directory = '';

            public static function reset(): void
            {
                self::forgetRecordedAnswers();
            }

            public function record(string $group, string $case, SiteResponse $response): void
            {
                $this->assertRecordedAnswer($group, $case, $response);
            }

            public function finish(): void
            {
                self::assertRecordedAnswersComplete();
            }

            protected static function captureMode(): string
            {
                return self::$mode;
            }

            protected static function ciRefusesCapture(): bool
            {
                return self::$ci;
            }

            protected static function recordedAnswersDirectory(): string
            {
                return self::$directory;
            }

            protected function baseUrl(): string
            {
                return 'http://127.0.0.1:55438/';
            }
        };
        $recorder::$mode = $mode;
        $recorder::$ci = $ci;
        $recorder::$directory = $this->directory;
        $recorder::reset();

        return $recorder;
    }

    private function failureOf(callable $work): string
    {
        try {
            $work();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }
        $this->fail('expected a failure');
    }

    public function testAVerifyCaptureFailsWhenTwoCapturesDiffer(): void
    {
        $recorder = $this->recorder('verify');
        $recorder->record('group', 'steady', self::response(['Last-Modified: A']));
        $recorder->record('group', 'steady', self::response(['Last-Modified: A']));
        $recorder->record('group', 'moves', self::response(['Last-Modified: A']));
        $recorder->record('group', 'moves', self::response(['Last-Modified: B']));

        $message = $this->failureOf(fn () => $recorder->finish());

        $this->assertStringContainsString('group/moves.txt', $message);
        $this->assertStringContainsString('last-modified', $message);
        $this->assertStringNotContainsString('steady', $message);
        $this->assertFileDoesNotExist($this->directory . '/group/moves.txt', 'a capture that is not repeatable writes nothing');
        $this->assertFileDoesNotExist($this->directory . '/group/steady.txt');
    }

    public function testAVerifyCaptureThatMatchesWritesTheFilesAndStillFails(): void
    {
        $recorder = $this->recorder('verify');
        $answer = self::response(['Content-Type: text/plain'], 'one');
        $recorder->record('group', 'a', $answer);
        $recorder->record('group', 'a', $answer);

        $message = $this->failureOf(fn () => $recorder->finish());

        $this->assertStringContainsString('group/a.txt', $message);
        $this->assertStringContainsString('WRITTEN', $message);
        $this->assertSame($this->normalise($answer), file_get_contents($this->directory . '/group/a.txt'));
    }

    public function testAVerifyCaptureOfACaseRecordedOnceFails(): void
    {
        $recorder = $this->recorder('verify');
        $recorder->record('group', 'once', self::response([]));

        $this->assertStringContainsString('group/once.txt was recorded once', $this->failureOf(fn () => $recorder->finish()));
        $this->assertFileDoesNotExist($this->directory . '/group/once.txt');
    }

    public function testACaptureWritesTheFileAndFailsTheRunNamingIt(): void
    {
        $recorder = $this->recorder('capture');
        $answer = self::response(['Content-Type: text/plain'], 'body');
        $recorder->record('group', 'one', $answer);
        $recorder->record('group', 'two', $answer);

        $message = $this->failureOf(fn () => $recorder->finish());

        $this->assertStringContainsString('group/one.txt, group/two.txt', $message);
        $this->assertSame("status 200\ncontent-type: text/plain\n\nbody", file_get_contents($this->directory . '/group/one.txt'));
    }

    public function testACaptureIsRefusedWhenCiIsSetAndWritesNothing(): void
    {
        foreach (['capture', 'verify'] as $mode) {
            $recorder = $this->recorder($mode, true);

            $this->assertStringContainsString('refused when CI is set', $this->failureOf(fn () => $recorder->record('group', 'a', self::response([]))));
            $this->assertDirectoryDoesNotExist($this->directory);
        }
    }

    public function testComparingIsAllowedWhenCiIsSet(): void
    {
        $recorder = $this->recorder('compare', true);
        $answer = self::response(['Content-Type: text/plain'], 'one');
        mkdir($this->directory . '/group', 0775, true);
        file_put_contents($this->directory . '/group/a.txt', $this->normalise($answer));

        $recorder->record('group', 'a', $answer);
        $this->addToAssertionCount(1);
    }

    public function testACaseWithNoRecordingFailsAndIsNeverCreatedOutsideACapture(): void
    {
        $recorder = $this->recorder('compare');

        $this->assertStringContainsString('there is no recording', $this->failureOf(fn () => $recorder->record('group', 'absent', self::response([]))));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testAnAnswerIsComparedWithItsRecordingWithADiff(): void
    {
        $recorder = $this->recorder('compare');
        mkdir($this->directory . '/group', 0775, true);
        file_put_contents($this->directory . '/group/other.txt', $this->normalise(self::response(['Content-Type: text/plain'], 'two')));

        $message = $this->failureOf(fn () => $recorder->record('group', 'other', self::response(['Content-Type: text/plain'], 'one')));

        $this->assertStringContainsString('-two', $message);
        $this->assertStringContainsString('+one', $message);
    }

    public function testAChangedCaseIsLeftToItsTestAndMustBeMetWithAReason(): void
    {
        $recorder = new class {
            use RecordedAnswers;

            public const CHANGED = ['moved' => 'it moved on purpose', 'unmet' => 'never recorded', 'blank' => ' '];

            public function record(string $case): void
            {
                $this->assertRecordedAnswer('g', $case, new SiteResponse(200, [], ''));
            }

            public function finish(): void
            {
                self::assertRecordedAnswersComplete();
            }

            public static function reset(): void
            {
                self::forgetRecordedAnswers();
            }

            protected static function captureMode(): string
            {
                return 'compare';
            }

            protected function baseUrl(): string
            {
                return 'http://127.0.0.1:55438/';
            }
        };
        $recorder::reset();

        $recorder->record('moved');
        $this->assertStringContainsString('with no reason', $this->failureOf(fn () => $recorder->record('blank')));
        $this->assertStringContainsString('no test recorded: unmet', $this->failureOf(fn () => $recorder->finish()));
    }

    public function testARecordingNameCannotLeaveItsDirectory(): void
    {
        $recorder = $this->recorder('capture');

        foreach ([['group', '../x'], ['..', 'x'], ['a/b', 'c'], ['group', 'Upper'], ['group', '']] as [$group, $case]) {
            $this->assertStringContainsString('named by a group and a case', $this->failureOf(fn () => $recorder->record($group, $case, self::response([]))));
        }
    }

    public function testExpiresAtTheEdgeOfTheYear2000(): void
    {
        $date = 'Date: Wed, 25 Nov 2026 10:00:00 GMT';

        $this->assertStringContainsString("expires: <past>\n", $this->normalise(self::response([$date, 'Expires: Fri, 31 Dec 1999 23:59:59 GMT'])));
        $this->assertStringContainsString("expires: <future: -9825 days from the request>\n", $this->normalise(self::response([$date, 'Expires: Sat, 01 Jan 2000 00:00:00 GMT'])));
    }

    public function testAFutureExpiresWithNoDateCannotBeRecorded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->normalise(self::response(['Expires: Fri, 25 Dec 2099 10:00:00 GMT']));
    }

    public function testAnExpiresInThePastNeedsNoDate(): void
    {
        $this->assertStringContainsString('expires: <past>', $this->normalise(self::response(['Expires: Thu, 19 Nov 1981 08:52:00 GMT'])));
    }

    public function testTheSitesAddressInAHeaderValueIsReplacedAndAForeignHostIsNot(): void
    {
        $text = $this->normalise(self::response([
            'Location: http://127.0.0.1:55438/x', 'Link: <http://127.0.0.1:55438/p>; rel="canonical"', 'X-Idno-Forward-Trace: http://127.0.0.1:55438/a',
            'Access-Control-Allow-Origin: https://elsewhere.example:55438',
        ]));

        $this->assertStringContainsString("location: {site}/x\n", $text);
        $this->assertStringContainsString('link: <{site}/p>; rel="canonical"', $text);
        $this->assertStringContainsString('x-idno-forward-trace: {site}/a', $text);
        $this->assertStringContainsString('access-control-allow-origin: https://elsewhere.example:55438', $text);
        $this->assertSame(
            $this->normalise(self::response(['Location: http://127.0.0.1:55438/x'])),
            RecordedAnswersTest::normaliseFor(self::response(['Location: http://127.0.0.1:61000/x']), 'http://127.0.0.1:61000/'),
            'the same answer from a site on another port is the same recording',
        );
    }

    public function testContentLengthIsRecordedAsWhetherItIsTheBodysOwnLength(): void
    {
        $same = $this->normalise(self::response(['Content-Length: 5'], 'hello'));
        $other = $this->normalise(self::response(['Content-Length: 99'], 'hello'));
        $head = $this->normalise(self::response(['Content-Length: 5'], ''));

        $this->assertStringContainsString('content-length: <the length of the body>', $same);
        $this->assertStringContainsString('content-length: <not the length of the body>', $other, 'a length that is not the body\'s is a difference');
        $this->assertStringContainsString('content-length: <not the length of the body>', $head);
        $long = 'a http://127.0.0.1:55438/ b ok';
        $short = 'a http://127.0.0.1:61/ b ok';
        $this->assertSame(
            $this->normalise(self::response(['Content-Length: ' . strlen($long)], $long)),
            RecordedAnswersTest::normaliseFor(self::response(['Content-Length: ' . strlen($short)], $short), 'http://127.0.0.1:61/'),
            'the body\'s length varies with the digits of the port',
        );
    }

    public function testRepeatedValuesAndNameCaseAreFolded(): void
    {
        $text = $this->normalise(self::response(['Content-type: text/plain', 'Vary: Accept', 'vary: Accept']));

        $this->assertSame("status 200\ncontent-type: text/plain\nvary: Accept\nvary: Accept\n\n", $text);
    }

    public function testACaptureRefusesAFormTokenOutsideTheMarkupTheNormaliserKnows(): void
    {
        $recorder = $this->recorder('capture');
        $token = str_repeat('ab12', 16);

        $message = $this->failureOf(fn () => $recorder->record('group', 'a', self::response(['Content-Type: application/json'], '{"t":"' . $token . '"}')));

        $this->assertStringContainsString('64-digit hex', $message);
        $this->assertFileDoesNotExist($this->directory . '/group/a.txt');
        $recorder->record('group', 'b', self::response(['Content-Type: text/html'], '<input name="__bTk" value="' . $token . '">'));
        $this->addToAssertionCount(1);
    }

    public function testACaptureRefusesTheSessionIdTheResponseSet(): void
    {
        $recorder = $this->recorder('capture');
        $session = 'k3j4h5g6f7d8s9a0q1w2';

        $message = $this->failureOf(fn () => $recorder->record('group', 'a', self::response(['Set-Cookie: idno=' . $session . '; path=/', 'Content-Type: text/plain'], 'Location: ?s=' . $session)));

        $this->assertStringContainsString('session id', $message);
    }

    public function testTheCiHookDefaultsToTheEnvironment(): void
    {
        $probe = new class {
            use RecordedAnswers;

            public static function ci(): bool
            {
                return self::ciRefusesCapture();
            }
        };
        $before = getenv('CI');
        try {
            putenv('CI=1');
            $this->assertTrue($probe::ci());
            putenv('CI=');
            $this->assertFalse($probe::ci());
        } finally {
            putenv($before === false ? 'CI' : 'CI=' . $before);
        }
    }

    public static function normaliseFor(SiteResponse $response, string $baseUrl): string
    {
        $probe = new class {
            use RecordedAnswers;
        };

        return $probe::normalise($response, $baseUrl);
    }
}
