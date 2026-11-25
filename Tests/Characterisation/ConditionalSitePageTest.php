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

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
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
}
