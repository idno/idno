<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Characterisation\SiteResponse;
use Tests\Support\RecordedAnswers;

final class RecordedAnswersMainOnlyTest extends TestCase
{
    private const BASE = 'http://127.0.0.1:55438/';

    private const PAGE = "<!doctype html><html><head><title>Settings</title><link rel=\"stylesheet\" href=\"/css/x.css\"></head>\n"
        . "<body><nav><a href=\"/menu\">Menu</a></nav>\n<main class=\"x\"><h1>Hello</h1></main>\n<footer><script src=\"/js/x.js\"></script></footer></body></html>";

    /** @param list<string> $lines */
    private static function response(array $lines, string $body): SiteResponse
    {
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[$name] = trim($value);
        }

        return new SiteResponse(200, $headers, $body, $lines);
    }

    private function normalise(SiteResponse $response, bool $mainOnly): string
    {
        $probe = $mainOnly ? new class {
            use RecordedAnswers;

            protected const RECORD_MAIN_ONLY = true;
        } : new class {
            use RecordedAnswers;
        };

        return $probe::normalise($response, self::BASE);
    }

    /**
     * Catches: a recording that still holds the menu and the footer (so one change to the menu breaks every file of a group), or one that lost a
     * header or the title a page sends.
     */
    public function testAMainOnlyRecordingKeepsTheHeadersAndTheTitleAndDropsTheShell(): void
    {
        $text = $this->normalise(self::response(['Content-Type: text/html; charset=utf-8', 'Cache-Control: no-store'], self::PAGE), true);

        $this->assertStringContainsString("cache-control: no-store\n", $text);
        $this->assertStringContainsString('<title>Settings</title>', $text);
        $this->assertStringContainsString('<main class="x">', $text);
        $this->assertStringContainsString('Hello', $text);
        $this->assertStringNotContainsString('Menu', $text);
        $this->assertStringNotContainsString('x.js', $text);
        $this->assertStringNotContainsString('x.css', $text);
    }

    /** Catches: the mode changing a recording that did not ask for it, or a page with no `<main>` losing its body. */
    public function testWithoutTheModeTheBodyIsWholeAndAPageWithNoMainIsKeptWhole(): void
    {
        $whole = $this->normalise(self::response(['Content-Type: text/html'], self::PAGE), false);
        $this->assertStringContainsString('Menu', $whole);

        $bare = "<html><head><title>T</title></head><body><p>Not a settings page</p></body></html>";
        $text = $this->normalise(self::response(['Content-Type: text/html'], $bare), true);
        $this->assertStringContainsString('Not a settings page', $text);
    }

    /** Catches: a JSON or text answer being cut down to a title and a main it does not have. */
    public function testANonHtmlBodyIsRecordedWhole(): void
    {
        $text = $this->normalise(self::response(['Content-Type: application/json'], '{"menu":"<main>x</main>"}'), true);

        $this->assertStringEndsWith("\n\n" . '{"menu":"<main>x</main>"}', $text);
    }
}
