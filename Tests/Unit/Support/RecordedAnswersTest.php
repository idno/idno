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

    public function testAVerifyCaptureFailsWhenTwoCapturesDiffer(): void
    {
        $directory = sys_get_temp_dir() . '/idno-recorded-' . bin2hex(random_bytes(4));
        $recorder = new class {
            use RecordedAnswers;

            public static string $directory = '';

            public static function reset(): void
            {
                self::forgetRecordedAnswers();
            }

            public function record(string $case, SiteResponse $response): void
            {
                $this->assertRecordedAnswer('group', $case, $response);
            }

            public function finish(): void
            {
                self::assertRecordedAnswersComplete();
            }

            protected static function captureMode(): string
            {
                return 'verify';
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
        $recorder::$directory = $directory;
        $recorder::reset();
        $recorder->record('steady', self::response(['Last-Modified: A']));
        $recorder->record('steady', self::response(['Last-Modified: A']));
        $recorder->record('moves', self::response(['Last-Modified: A']));
        $recorder->record('moves', self::response(['Last-Modified: B']));

        try {
            $recorder->finish();
            $this->fail('a verify capture is never a green run');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('group/moves.txt', $failure->getMessage());
            $this->assertStringContainsString('last-modified', $failure->getMessage());
            $this->assertStringNotContainsString('group/steady.txt', explode("\n", $failure->getMessage())[0] ?? '');
        } finally {
            $recorder::reset();
        }
        $this->assertFileDoesNotExist($directory . '/group/moves.txt', 'a capture that is not repeatable writes nothing');
        $this->assertFileDoesNotExist($directory . '/group/steady.txt');
    }

    public function testAnAnswerIsComparedWithItsRecordingAndAChangedCaseIsLeftToItsTest(): void
    {
        $directory = sys_get_temp_dir() . '/idno-recorded-' . bin2hex(random_bytes(4));
        mkdir($directory . '/g', 0775, true);
        $comparer = new class {
            use RecordedAnswers;

            public const CHANGED = ['moved' => 'it moved on purpose'];
            public static string $directory = '';

            public function record(string $case, SiteResponse $response): void
            {
                $this->assertRecordedAnswer('g', $case, $response);
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
        $comparer::$directory = $directory;
        $answer = self::response(['Content-Type: text/plain'], 'one');
        file_put_contents($directory . '/g/same.txt', $this->normalise($answer));
        file_put_contents($directory . '/g/other.txt', $this->normalise(self::response(['Content-Type: text/plain'], 'two')));

        try {
            $comparer->record('same', $answer);
            $comparer->record('moved', $answer);
            try {
                $comparer->record('other', $answer);
                $this->fail('a different answer fails');
            } catch (AssertionFailedError $failure) {
                $this->assertStringContainsString('-two', $failure->getMessage());
                $this->assertStringContainsString('+one', $failure->getMessage());
            }
            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage('no recording');
            $comparer->record('absent', $answer);
        } finally {
            array_map('unlink', glob($directory . '/g/*') ?: []);
            rmdir($directory . '/g');
            rmdir($directory);
        }
    }
}
