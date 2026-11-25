<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;
use Tests\Support\RecordedAnswers;

/**
 * A conditional request (`If-Modified-Since`) to a page that asks for it, over HTTP on a site of the test's own: what a legacy page
 * answered (recorded from `Tests\Fixtures\LegacyDrawPage`, case `conditional`) and what a page a controller serves answers.
 */
final class ConditionalSitePageTest extends PrivateSiteTestCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    /** The time the fixture pages say they last changed. */
    private const LAST_CHANGE = 1700000000;

    private const LEGACY = '/_test/site-page/draw-legacy?case=conditional';

    private const CONTROLLER = '/_test/site-page/draw-new?case=conditional';

    /** The file a streamed page writes when its stream is sent: one for the class, so that the site's environment is the same for every test. */
    private static string $marker = '';

    protected function setUp(): void
    {
        self::$marker = self::$marker !== '' ? self::$marker : sys_get_temp_dir() . '/idno-conditional-' . bin2hex(random_bytes(6));
        parent::setUp();
        if (is_file(self::$marker)) {
            unlink(self::$marker);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_FIXTURE_SHELL' => '1', 'IDNO_TEST_STREAM_MARKER' => self::$marker];
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            if (self::$marker !== '' && is_file(self::$marker)) {
                unlink(self::$marker);
            }
            self::$marker = '';
            parent::tearDownAfterClass();
        }
    }

    private static function date(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s T', $timestamp);
    }

    public function testTheLegacyPagesNotModifiedAnswer(): void
    {
        // The legacy rule answered 304 when the request's date was NOT AFTER the change, and sent the page when it was after (the wrong way round)
        foreach ([1, 2] as $_twice) {
            $this->assertRecordedAnswer('conditional', 'legacy-304', $this->site()->request('GET', self::LEGACY, ['If-Modified-Since' => self::date(self::LAST_CHANGE - 86400)]));
            $this->assertRecordedAnswer('conditional', 'legacy-200', $this->site()->request('GET', self::LEGACY, ['If-Modified-Since' => self::date(self::LAST_CHANGE + 86400)]));
        }
    }

    /** What the legacy page answered, as recorded: the answer a controller's page must give a current copy. */
    private static function recorded(string $case): string
    {
        return (string) file_get_contents(self::recordedAnswersDirectory() . '/conditional/' . $case . '.txt');
    }

    /**
     * A recorded answer, or an answer normalised, as it is compared: the page's own address (the `self` link) is the same address, and the
     * lines in `$ignore` are left out.
     *
     * @param list<string> $ignore header names (lower case)
     */
    private static function comparable(string $text, array $ignore = []): string
    {
        $text = (string) preg_replace('/^link: <\{site\}[^>]*>; rel="self"$/m', 'link: <{site}/the-page>; rel="self"', $text);
        foreach ($ignore as $name) {
            $text = (string) preg_replace('/^' . preg_quote($name, '/') . ': .*\n/m', '', $text);
        }

        return $text;
    }

    private function answer(string $path, int $since, string $method = 'GET'): SiteResponse
    {
        return $this->site()->request($method, $path, ['If-Modified-Since' => self::date($since)]);
    }

    public function testTheControllersNotModifiedAnswerHasTheLegacyHeaders(): void
    {
        // A copy as new as the change (a day after it) is current: the legacy page answered 304 to a date a day BEFORE it, the other way round,
        // and that answer's status, headers and (no) body are what the controller's page answers too
        $response = $this->answer(self::CONTROLLER, self::LAST_CHANGE + 86400);

        $this->assertSame(self::comparable(self::recorded('legacy-304')), self::comparable(self::normalise($response, $this->baseUrl()), ['access-control-allow-origin']));
        $this->assertSame('', $response->body);
        // The one line more: the legacy page exited before it drew anything, so its 304 had none of the headers the page's own draw sends; this
        // one keeps them (a cross-origin client may read what a 304 says)
        $this->assertSame('*', $response->header('Access-Control-Allow-Origin'));
    }

    public function testAnOlderCopyGetsThePage(): void
    {
        $response = $this->answer(self::CONTROLLER, self::LAST_CHANGE - 86400);

        $this->assertSame(200, $response->status);
        $this->assertSame(self::date(self::LAST_CHANGE), $response->header('Last-Modified'));
        $this->assertStringContainsString('A drawn page', $response->body);
    }

    public function testACopyAsNewAsTheChangeIsCurrent(): void
    {
        $this->assertSame(304, $this->answer(self::CONTROLLER, self::LAST_CHANGE)->status);
        $this->assertSame(200, $this->answer(self::CONTROLLER, self::LAST_CHANGE - 1)->status);
    }

    public function testAHeadRequestIsAnsweredToo(): void
    {
        $this->assertSame(304, $this->answer(self::CONTROLLER, self::LAST_CHANGE + 86400, 'HEAD')->status);
    }

    public function testAnIfNoneMatchBesideItMeansThePageIsSent(): void
    {
        $response = $this->site()->request('GET', self::CONTROLLER, ['If-Modified-Since' => self::date(self::LAST_CHANGE + 86400), 'If-None-Match' => '"abc"']);

        $this->assertSame(200, $response->status);
    }

    public function testADateThatIsNotADateMeansThePageIsSent(): void
    {
        foreach (['tomorrow', '1700000000'] as $value) {
            $this->assertSame(200, $this->site()->request('GET', self::CONTROLLER, ['If-Modified-Since' => $value])->status, $value);
        }
    }

    public function testAShellServedPageAnswersTheSame(): void
    {
        $path = '/_test/fixture-shell/?case=conditional';

        $current = $this->answer($path, self::LAST_CHANGE + 86400);
        $this->assertSame(self::comparable(self::recorded('legacy-304')), self::comparable(self::normalise($current, $this->baseUrl()), ['access-control-allow-origin']));
        $this->assertSame('', $current->body);

        $older = $this->answer($path, self::LAST_CHANGE - 86400);
        $this->assertSame(200, $older->status);
        $this->assertSame(self::date(self::LAST_CHANGE), $older->header('Last-Modified'));
        $this->assertStringContainsString('A drawn page', $older->body);
    }

    public function testTheControllerAndTheShellOfOnePageAnswerAlike(): void
    {
        $shell = $this->answer('/_test/fixture-shell/?case=conditional', self::LAST_CHANGE + 86400);
        $controller = $this->answer('/_test/fixture-shell-controller?case=conditional', self::LAST_CHANGE + 86400);

        $this->assertSame(304, $shell->status);
        // (the controller's page says it is a permalink and so varies by `Accept`; the shell's page is not, so far, one)
        $this->assertSame(
            self::comparable(self::normalise($shell, $this->baseUrl()), ['vary']),
            self::comparable(self::normalise($controller, $this->baseUrl()), ['vary'])
        );

        // and both send the page to a copy from before the change (the bodies name their own addresses)
        $shell = $this->answer('/_test/fixture-shell/?case=conditional', self::LAST_CHANGE - 86400);
        $controller = $this->answer('/_test/fixture-shell-controller?case=conditional', self::LAST_CHANGE - 86400);
        $this->assertSame(200, $shell->status);
        $this->assertSame(200, $controller->status);
        $this->assertSame($shell->header('Last-Modified'), $controller->header('Last-Modified'));
    }

    public function testTheStreamIsNotSentFor304(): void
    {
        $current = $this->answer('/_test/site-page/draw-new?case=conditional-stream', self::LAST_CHANGE + 86400);

        $this->assertSame(304, $current->status);
        $this->assertSame('', $current->body);
        $this->assertFileDoesNotExist(self::$marker);

        // (and an older copy is sent it, so that the marker proves the stream is what writes it)
        $older = $this->answer('/_test/site-page/draw-new?case=conditional-stream', self::LAST_CHANGE - 86400);
        $this->assertSame(200, $older->status);
        $this->assertSame('streamed', $older->body);
        $this->assertFileExists(self::$marker);
    }
}
