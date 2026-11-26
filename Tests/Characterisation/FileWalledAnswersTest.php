<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the file page answers on a private site (`walled_garden`), recorded from the legacy page over HTTP: the answers are
 * `Tests/Characterisation/fixtures/answers/files-walled/`. The page is registered as public, so a visitor of a private site is answered as on an open
 * site; the rights of the file itself (the entity it is attached to, the administrators-only mark) still apply. The seeded files are `FileSiteCase`'s.
 */
final class FileWalledAnswersTest extends FileSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'files-walled';

    /**
     * case => [the file, the viewer, the range or an empty string].
     *
     * @var array<string, array{string, string, string}>
     */
    private const CASES = [
        'image-visitor' => ['image', 'visitor', ''],
        'image-member' => ['image', 'member', ''],
        'image-visitor-range' => ['image', 'visitor', 'bytes=0-9'],
        'html-visitor' => ['html', 'visitor', ''],
        'attached-public-visitor' => ['attached-public', 'visitor', ''],
        'attached-site-visitor' => ['attached-site', 'visitor', ''],
        'attached-site-member' => ['attached-site', 'member', ''],
        'attached-owner-visitor' => ['attached-owner', 'visitor', ''],
        'attached-owner-owner' => ['attached-owner', 'owner', ''],
        'restricted-visitor' => ['restricted', 'visitor', ''],
        'restricted-member' => ['restricted', 'member', ''],
        'restricted-admin' => ['restricted', 'admin', ''],
        'recorded-visitor' => ['recorded', 'visitor', ''],
        'recorded-admin' => ['recorded', 'admin', ''],
    ];

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_WALLED_GARDEN' => '1'];
    }

    protected function seedSharedSite(): void
    {
        $this->seedFiles();
    }

    public function testAVisitorAndAMemberOfAPrivateSite(): void
    {
        foreach (self::CASES as $case => [$file, $viewer, $range]) {
            $this->record($case, 'GET', $this->fileAddress($file, '/x.bin'), $range === '' ? [] : ['Range' => $range], $viewer, null, self::withTheRequestTimeMarked(...));
        }
        $this->addToAssertionCount(1);
    }

    public function testAHeadAnOptionsAndAMissingFileOfAPrivateSite(): void
    {
        $mark = self::withTheRequestTimeMarked(...);
        $this->record('head-image-visitor', 'HEAD', $this->fileAddress('image'), [], 'visitor', null, $mark);
        $this->record('head-restricted-visitor', 'HEAD', $this->fileAddress('restricted'), [], 'visitor', null, $mark);
        $this->record('options-image-visitor', 'OPTIONS', $this->fileAddress('image'), [], 'visitor', null, $mark);
        $this->record('missing-visitor', 'GET', '/file/' . str_repeat('0', 32), [], 'visitor', null, $mark);
        $this->record('missing-member', 'GET', '/file/' . str_repeat('0', 32), [], 'member', null, $mark);
        $this->addToAssertionCount(1);
    }
}
