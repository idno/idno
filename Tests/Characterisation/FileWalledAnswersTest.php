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
     * The cases the page that replaced the legacy one answers differently ON PURPOSE, with why: the recordings are the legacy page's and are kept as
     * they were, and each case is held to the recording but for what changed.
     */
    private const CHANGED = [
        'image-visitor' => self::WHY_PRIVATE,
        'image-visitor-range' => self::WHY_PRIVATE,
        'image-visitor-conditional' => self::WHY_PRIVATE,
        'html-visitor' => self::WHY_PRIVATE,
        'attached-public-visitor' => self::WHY_PRIVATE,
        'attached-site-visitor' => self::WHY_PRIVATE,
        'attached-owner-visitor' => self::WHY_PRIVATE,
        'restricted-visitor' => self::WHY_PRIVATE,
        'recorded-visitor' => self::WHY_PRIVATE,
        'head-image-visitor' => self::WHY_PRIVATE,
        'head-restricted-visitor' => self::WHY_PRIVATE,
        'restricted-admin' => self::WHY_DOWNLOAD,
        'recorded-admin' => self::WHY_DOWNLOAD,
    ];

    /** Why a visitor gets no file of a private site: a missing file's answer, whatever the file is */
    private const WHY_PRIVATE = 'a private site gives a visitor no stored file: the answer is exactly what a file that is not there answers (it was the file, or a 403 for an archive), so nothing tells a visitor which files exist';

    /** @var array<string, array<string, mixed>> what changed in each of them */
    protected const KINDS = [
        'image-visitor' => ['hidden' => true],
        'image-visitor-range' => ['hidden' => true],
        'image-visitor-conditional' => ['hidden' => true],
        'html-visitor' => ['hidden' => true],
        'attached-public-visitor' => ['hidden' => true],
        'attached-site-visitor' => ['hidden' => true],
        'attached-owner-visitor' => ['hidden' => true],
        'restricted-visitor' => ['hidden' => true],
        'recorded-visitor' => ['hidden' => true],
        'head-image-visitor' => ['hidden' => true],
        'head-restricted-visitor' => ['hidden' => true],
        'restricted-admin' => ['name' => 'x.bin'],
        'recorded-admin' => ['name' => 'x.bin'],
    ];

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
            $this->recordFile($case, 'GET', $this->fileAddress($file, '/x.bin'), $range === '' ? [] : ['Range' => $range], $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testAHeadAnOptionsAndAMissingFileOfAPrivateSite(): void
    {
        // (a copy the visitor holds is no reason to answer 304 for a file they may not have)
        $this->recordFile('image-visitor-conditional', 'GET', $this->fileAddress('image'), ['If-Modified-Since' => gmdate('D, d M Y H:i:s', self::WRITTEN + 86400) . ' GMT'], 'visitor');
        $this->recordFile('head-image-visitor', 'HEAD', $this->fileAddress('image'), [], 'visitor');
        $this->recordFile('head-restricted-visitor', 'HEAD', $this->fileAddress('restricted'), [], 'visitor');
        $this->recordFile('options-image-visitor', 'OPTIONS', $this->fileAddress('image'), [], 'visitor');
        $this->recordFile('missing-visitor', 'GET', '/file/' . str_repeat('0', 32), [], 'visitor');
        $this->recordFile('missing-member', 'GET', '/file/' . str_repeat('0', 32), [], 'member');
        $this->addToAssertionCount(1);
    }
}
