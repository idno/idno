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

        $this->assertSame(self::comparable(self::recorded('legacy-304')), self::comparable(self::normalise($response, $this->baseUrl())));
        $this->assertSame('', $response->body);
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
        // (a page whose type is chosen from the `Accept` header says so, the shell's page as its controller's)
        $this->assertStringContainsString('Accept', (string) $current->header('Vary'));
        $this->assertSame(self::comparable(self::recorded('legacy-304')), self::comparable(self::normalise($current, $this->baseUrl())));
        $this->assertSame('', $current->body);

        $older = $this->answer($path, self::LAST_CHANGE - 86400);
        $this->assertSame(200, $older->status);
        $this->assertSame(self::date(self::LAST_CHANGE), $older->header('Last-Modified'));
        $this->assertStringContainsString('A drawn page', $older->body);
    }

    public function testTheControllerAndTheShellOfOnePageAnswerAlike(): void
    {
        $shell = $this->answer('/_test/fixture-shell/?case=conditional', self::LAST_CHANGE + 86400);
        $controller = $this->answer('/_test/fixture-shell/controller?case=conditional', self::LAST_CHANGE + 86400);

        $this->assertSame(304, $shell->status);
        $this->assertSame(
            self::comparable(self::normalise($shell, $this->baseUrl())),
            self::comparable(self::normalise($controller, $this->baseUrl()))
        );

        // and both send the page to a copy from before the change (the bodies name their own addresses)
        $shell = $this->answer('/_test/fixture-shell/?case=conditional', self::LAST_CHANGE - 86400);
        $controller = $this->answer('/_test/fixture-shell/controller?case=conditional', self::LAST_CHANGE - 86400);
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

    public function testAskingThroughTheCurrentPageAnswersAsTheLegacyPageDid(): void
    {
        // the call legacy code and templates make: the current page's gatekeeper, on a real legacy page and on a controller's page
        $legacy = $this->answer('/_test/site-page/draw-legacy?case=conditional-page', self::LAST_CHANGE - 86400);
        $controller = $this->answer('/_test/site-page/draw-new?case=conditional-page', self::LAST_CHANGE + 86400);

        $this->assertSame(304, $legacy->status);
        $this->assertSame(304, $controller->status);
        $this->assertSame(self::comparable(self::normalise($legacy, $this->baseUrl())), self::comparable(self::normalise($controller, $this->baseUrl())));
        $this->assertSame(self::comparable(self::recorded('legacy-304')), self::comparable(self::normalise($controller, $this->baseUrl())));

        // and a copy that is not current is sent the page, with its date
        $legacy = $this->answer('/_test/site-page/draw-legacy?case=conditional-page', self::LAST_CHANGE + 86400);
        $controller = $this->answer('/_test/site-page/draw-new?case=conditional-page', self::LAST_CHANGE - 86400);
        $this->assertSame(200, $legacy->status);
        $this->assertSame(200, $controller->status);
        $this->assertSame($legacy->header('Last-Modified'), $controller->header('Last-Modified'));
    }

    public function testAPageWithNoDateIsAlwaysSent(): void
    {
        // a post with no `updated` has no change to compare with: whatever date the client's copy has, the page is sent (as the legacy page did)
        foreach (['/_test/site-page/draw-legacy', '/_test/site-page/draw-new'] as $page) {
            foreach ([self::LAST_CHANGE - 86400, self::LAST_CHANGE + 86400, self::LAST_CHANGE + 86400 * 3650] as $since) {
                $response = $this->answer($page . '?case=conditional-missing', $since);

                $this->assertSame(200, $response->status, $page . ' ' . $since);
                $this->assertStringContainsString('A drawn page', $response->body);
                $this->assertNull($response->header('Last-Modified'));
            }
        }
    }

    public function testAPendingMessageSurvivesA304AndIsShownOnTheNextPage(): void
    {
        // The legacy page ended at its 304, before it drew anything, so the session's pending message was still there for the next page
        foreach (['legacy' => ['draw-legacy', self::LAST_CHANGE - 86400], 'controller' => ['draw-new', self::LAST_CHANGE + 86400]] as $kind => [$page, $since]) {
            $queued = $this->site()->request('GET', '/_test/site-page/' . $page . '?case=queue-message');
            $cookie = self::sessionCookie($queued);
            $this->assertSame('queued', $queued->body, $kind);

            $current = $this->site()->request('GET', '/_test/site-page/' . $page . '?case=conditional', ['If-Modified-Since' => self::date($since), 'Cookie' => $cookie]);
            $this->assertSame(304, $current->status, $kind);

            $next = $this->site()->request('GET', '/_test/site-page/' . $page . '?case=conditional', ['Cookie' => $cookie]);
            $this->assertSame(200, $next->status, $kind);
            $this->assertStringContainsString('A message to be shown once', $next->body, $kind . ': the message was used up by the 304');

            $after = $this->site()->request('GET', '/_test/site-page/' . $page . '?case=conditional', ['Cookie' => $cookie]);
            $this->assertStringNotContainsString('A message to be shown once', $after->body, $kind . ': the message is shown once');
        }
    }
}
