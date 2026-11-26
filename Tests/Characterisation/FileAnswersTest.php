<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the file page (`/file/<id>` and `/file/<id>/<name>`) answers, recorded from the legacy page over HTTP: the status, headers and body of each seeded
 * file to each kind of viewer, of each form of a `Range` header, of a HEAD and an OPTIONS request, of a conditional request, of an XHR request, of an id that
 * is no file and of each spelling of the address. The answers are `Tests/Characterisation/fixtures/answers/files/`; a page that replaces the legacy one is held
 * to them. Each case is a literal, in the tables below or in the call (`Tests\Support\RecordingOrphans` reads them).
 *
 * The files and viewers are `FileSiteCase`'s. A file has no change time of its own: its `Last-Modified` is the time of the request, which the recordings
 * mark instead of holding.
 */
final class FileAnswersTest extends FileSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'files';

    /**
     * Each seeded file for each viewer: case => [the file, the viewer].
     *
     * @var array<string, array{string, string}>
     */
    private const VIEWER_CASES = [
        'image-visitor' => ['image', 'visitor'],
        'image-admin' => ['image', 'admin'],
        'pdf-visitor' => ['pdf', 'visitor'],
        'html-visitor' => ['html', 'visitor'],
        'html-admin' => ['html', 'admin'],
        'attached-public-visitor' => ['attached-public', 'visitor'],
        'attached-site-visitor' => ['attached-site', 'visitor'],
        'attached-site-member' => ['attached-site', 'member'],
        'attached-owner-visitor' => ['attached-owner', 'visitor'],
        'attached-owner-member' => ['attached-owner', 'member'],
        'attached-owner-owner' => ['attached-owner', 'owner'],
        'attached-owner-admin' => ['attached-owner', 'admin'],
        'attached-draft-visitor' => ['attached-draft', 'visitor'],
        'attached-draft-member' => ['attached-draft', 'member'],
        'attached-draft-owner' => ['attached-draft', 'owner'],
        'attached-draft-admin' => ['attached-draft', 'admin'],
        'restricted-visitor' => ['restricted', 'visitor'],
        'restricted-member' => ['restricted', 'member'],
        'restricted-owner' => ['restricted', 'owner'],
        'restricted-admin' => ['restricted', 'admin'],
        'site-export-visitor' => ['site-export', 'visitor'],
        'site-export-admin' => ['site-export', 'admin'],
        'recorded-visitor' => ['recorded', 'visitor'],
        'recorded-member' => ['recorded', 'member'],
        'recorded-admin' => ['recorded', 'admin'],
    ];

    /**
     * Each form of a `Range` header: case => [the file, the viewer, the header].
     *
     * @var array<string, array{string, string, string}>
     */
    private const RANGE_CASES = [
        'range-first-last' => ['image', 'visitor', 'bytes=0-9'],
        'range-middle' => ['image', 'visitor', 'bytes=100-199'],
        'range-from' => ['image', 'visitor', 'bytes=5-'],
        'range-suffix' => ['image', 'visitor', 'bytes=-5'],
        'range-one-byte' => ['image', 'visitor', 'bytes=7-7'],
        'range-last-byte' => ['image', 'visitor', 'bytes=299-299'],
        'range-end-past-the-file' => ['image', 'visitor', 'bytes=290-9999'],
        'range-suffix-longer-than-the-file' => ['image', 'visitor', 'bytes=-9999'],
        'range-unsatisfiable' => ['image', 'visitor', 'bytes=999999-'],
        'range-starts-at-the-size' => ['image', 'visitor', 'bytes=300-'],
        'range-inverted' => ['image', 'visitor', 'bytes=5-2'],
        'range-empty-suffix' => ['image', 'visitor', 'bytes=-0'],
        'range-malformed' => ['image', 'visitor', 'bytes=abc'],
        'range-no-numbers' => ['image', 'visitor', 'bytes=-'],
        'range-several' => ['image', 'visitor', 'bytes=0-4,10-14'],
        'range-another-unit' => ['image', 'visitor', 'items=0-5'],
        'range-spaces' => ['image', 'visitor', ' bytes = 3 - 6 '],
        'range-upper-case' => ['image', 'visitor', 'BYTES=0-9'],
        'range-html' => ['html', 'visitor', 'bytes=0-9'],
        'range-html-unsatisfiable' => ['html', 'visitor', 'bytes=999999-'],
        'range-pdf-suffix' => ['pdf', 'visitor', 'bytes=-5'],
        'range-attached-public' => ['attached-public', 'visitor', 'bytes=0-9'],
        'range-attached-owner-visitor' => ['attached-owner', 'visitor', 'bytes=0-9'],
        'range-attached-owner-owner' => ['attached-owner', 'owner', 'bytes=0-9'],
        'range-attached-owner-unsatisfiable-visitor' => ['attached-owner', 'visitor', 'bytes=999999-'],
        'range-attached-draft-owner' => ['attached-draft', 'owner', 'bytes=-5'],
        'range-restricted-visitor' => ['restricted', 'visitor', 'bytes=0-9'],
        'range-restricted-unsatisfiable-visitor' => ['restricted', 'visitor', 'bytes=999999-'],
        'range-restricted-admin' => ['restricted', 'admin', 'bytes=0-9'],
        'range-restricted-admin-unsatisfiable' => ['restricted', 'admin', 'bytes=999999-'],
        'range-recorded-visitor' => ['recorded', 'visitor', 'bytes=0-9'],
        'range-recorded-admin' => ['recorded', 'admin', 'bytes=-5'],
        'range-site-export-admin' => ['site-export', 'admin', 'bytes=5-'],
    ];

    /**
     * HEAD: case => [the file, the viewer, the range or an empty string].
     *
     * @var array<string, array{string, string, string}>
     */
    private const HEAD_CASES = [
        'head-image' => ['image', 'visitor', ''],
        'head-image-range' => ['image', 'visitor', 'bytes=0-9'],
        'head-image-unsatisfiable' => ['image', 'visitor', 'bytes=999999-'],
        'head-html' => ['html', 'visitor', ''],
        'head-attached-owner-visitor' => ['attached-owner', 'visitor', ''],
        'head-attached-owner-owner' => ['attached-owner', 'owner', ''],
        'head-attached-draft-visitor' => ['attached-draft', 'visitor', ''],
        'head-restricted-visitor' => ['restricted', 'visitor', ''],
        'head-restricted-visitor-range' => ['restricted', 'visitor', 'bytes=0-9'],
        'head-restricted-admin' => ['restricted', 'admin', ''],
        'head-restricted-admin-range' => ['restricted', 'admin', 'bytes=0-9'],
        'head-recorded-admin' => ['recorded', 'admin', ''],
        'head-recorded-visitor' => ['recorded', 'visitor', ''],
    ];

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    protected function seedSharedSite(): void
    {
        $this->seedFiles();
    }

    /**
     * One recorded request for a file, the time of the request marked in place of `Last-Modified`.
     *
     * @param array<string, string>     $headers
     * @param string|array<string>|null $body
     */
    private function recordFile(string $case, string $method, string $path, array $headers = [], string $viewer = 'visitor'): SiteResponse
    {
        return $this->record($case, $method, $path, $headers, $viewer, null, self::withTheRequestTimeMarked(...));
    }

    public function testEachFileForEachViewer(): void
    {
        foreach (self::VIEWER_CASES as $case => [$file, $viewer]) {
            $answer = $this->recordFile($case, 'GET', $this->fileAddress($file, '/' . $file . '.bin'), [], $viewer);
            if (in_array($case, ['image-visitor', 'image-admin', 'attached-public-visitor', 'html-visitor'], true)) {
                $this->assertSame(200, $answer->status, $case);
                $this->assertSame(self::bytes(), $answer->body, $case);
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testEachFormOfARangeHeader(): void
    {
        foreach (self::RANGE_CASES as $case => [$file, $viewer, $range]) {
            $this->recordFile($case, 'GET', $this->fileAddress($file, '/x.bin'), ['Range' => $range], $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testAHeadRequestForEachKindOfFile(): void
    {
        foreach (self::HEAD_CASES as $case => [$file, $viewer, $range]) {
            $this->recordFile($case, 'HEAD', $this->fileAddress($file, '/x.bin'), $range === '' ? [] : ['Range' => $range], $viewer);
        }
        $this->addToAssertionCount(1);
    }

    public function testAnOptionsRequest(): void
    {
        $this->recordFile('options-image', 'OPTIONS', $this->fileAddress('image'));
        $this->recordFile('options-restricted-visitor', 'OPTIONS', $this->fileAddress('restricted'));
        $this->recordFile('options-attached-owner-visitor', 'OPTIONS', $this->fileAddress('attached-owner'));
        $this->recordFile('options-missing', 'OPTIONS', '/file/' . str_repeat('0', 32));
        $this->addToAssertionCount(1);
    }

    public function testAnIdThatIsNoFileAndEachSpellingOfTheAddress(): void
    {
        $this->recordFile('missing-visitor', 'GET', '/file/' . str_repeat('0', 32) . '/x.bin');
        $this->recordFile('missing-admin', 'GET', '/file/' . str_repeat('0', 32) . '/x.bin', [], 'admin');
        $this->recordFile('missing-short-id', 'GET', '/file/abc123');
        $this->recordFile('missing-dashed-id', 'GET', '/file/0000-0000');
        $this->recordFile('missing-24-digits', 'GET', '/file/' . str_repeat('0', 24));
        $this->recordFile('malformed-id', 'GET', '/file/zzzz');
        $this->recordFile('malformed-id-underscore', 'GET', '/file/ab_cd');
        $this->recordFile('missing-head', 'HEAD', '/file/' . str_repeat('0', 32));
        $this->recordFile('missing-range', 'GET', '/file/' . str_repeat('0', 32), ['Range' => 'bytes=0-9']);
        $this->recordFile('image-no-name', 'GET', $this->fileAddress('image'));
        $this->recordFile('image-trailing-slash', 'GET', $this->fileAddress('image', '/'));
        $this->recordFile('image-with-name', 'GET', $this->fileAddress('image', '/photo.png'));
        $this->recordFile('image-name-with-a-space', 'GET', $this->fileAddress('image', '/some%20name.html'));
        $this->recordFile('image-name-with-slashes', 'GET', $this->fileAddress('image', '/a/b/c.png'));
        $this->recordRaw('image-doubled-slash-before', 'GET', '/' . $this->fileAddress('image'));
        $this->recordRaw('image-doubled-slash-before-head', 'HEAD', '/' . $this->fileAddress('image'));
        $this->recordRaw('image-doubled-slash-before-restricted-visitor', 'GET', '/' . $this->fileAddress('restricted'));
        $this->recordFile('image-doubled-slash-after-file', 'GET', '/file//' . $this->seedFiles()['image']);
        $this->recordFile('html-no-name', 'GET', $this->fileAddress('html'));
        $this->recordFile('html-name', 'GET', $this->fileAddress('html', '/page.html'));
        $this->recordFile('html-name-with-a-space', 'GET', $this->fileAddress('html', '/some%20name.html'));
        $this->recordFile('html-name-hostile', 'GET', $this->fileAddress('html', '/%22%3Bx%3D%22%3C%3E%0D%0AX-A:b.html'));
        $this->recordFile('html-name-dots', 'GET', $this->fileAddress('html', '/..%2f..%2fx.html'));
        $this->recordFile('html-upper-case-id', 'GET', '/file/' . strtoupper($this->seedFiles()['html']));
        $this->recordFile('zip-no-name', 'GET', $this->fileAddress('recorded'), [], 'admin');
        $this->recordFile('query-ignored', 'GET', $this->fileAddress('image') . '?_t=json');
        $this->recordFile('type-json-is-no-different', 'GET', $this->fileAddress('image') . '?_t=rss');
        $this->recordFile('malformed-host', 'GET', $this->fileAddress('image'), ['Host' => 'bad host!']);
        $this->recordFile('malformed-host-head', 'HEAD', $this->fileAddress('image'), ['Host' => 'bad host!']);
        $this->recordFile('put-visitor', 'PUT', $this->fileAddress('image'));
        $this->recordFile('post-visitor', 'POST', $this->fileAddress('image'), [], 'visitor', ['x' => 'y']);
        $this->recordFile('delete-visitor', 'DELETE', $this->fileAddress('image'));
        $this->addToAssertionCount(1);
    }

    public function testAnXhrRequestForAFile(): void
    {
        $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->recordFile('xhr-image', 'GET', $this->fileAddress('image'), $xhr);
        $this->recordFile('xhr-image-head', 'HEAD', $this->fileAddress('image'), $xhr);
        $this->recordFile('xhr-image-range', 'GET', $this->fileAddress('image'), $xhr + ['Range' => 'bytes=0-9']);
        $this->recordFile('xhr-restricted-visitor', 'GET', $this->fileAddress('restricted'), $xhr);
        $this->recordFile('xhr-restricted-admin', 'GET', $this->fileAddress('restricted'), $xhr, 'admin');
        $this->recordFile('xhr-missing', 'GET', '/file/' . str_repeat('0', 32), $xhr);
        $this->addToAssertionCount(1);
    }

    /**
     * A signed API request acts as its member: a file attached to a note private to the member is theirs, and a signed request is never an administrator.
     */
    public function testASignedApiRequestActsAsItsMember(): void
    {
        $address = $this->fileAddress('attached-owner');
        $key = $this->bobsKey();
        $this->recordFile('signed-attached-owner-bob', 'GET', $address, self::signedHeadersFor('bob', $key, $address));
        $restricted = $this->fileAddress('restricted');
        $this->recordFile('signed-restricted-bob', 'GET', $restricted, self::signedHeadersFor('bob', $key, $restricted));
        $this->addToAssertionCount(1);
    }

    /**
     * A conditional request. A file is as old as the moment it is asked for (its `Last-Modified` is the time of the request), so the dates are one day
     * before and one day after the moment of the request.
     */
    public function testAConditionalRequest(): void
    {
        $date = static fn (int $time): string => gmdate('D, d M Y H:i:s', $time) . ' GMT';
        $before = ['If-Modified-Since' => $date(time() - 86400)];
        $after = ['If-Modified-Since' => $date(time() + 86400)];
        $this->recordFile('ims-image-before', 'GET', $this->fileAddress('image'), $before);
        $this->recordFile('ims-image-after', 'GET', $this->fileAddress('image'), $after);
        $this->recordFile('ims-image-before-head', 'HEAD', $this->fileAddress('image'), $before);
        $this->recordFile('ims-image-after-head', 'HEAD', $this->fileAddress('image'), $after);
        $this->recordFile('ims-image-before-range', 'GET', $this->fileAddress('image'), $before + ['Range' => 'bytes=0-9']);
        $this->recordFile('ims-image-after-range', 'GET', $this->fileAddress('image'), $after + ['Range' => 'bytes=0-9']);
        $this->recordFile('ims-image-garbage', 'GET', $this->fileAddress('image'), ['If-Modified-Since' => 'not a date at all']);
        $this->recordFile('ims-image-none-match', 'GET', $this->fileAddress('image'), $after + ['If-None-Match' => '"abc"']);
        $this->recordFile('ims-html-before', 'GET', $this->fileAddress('html'), $before);
        $this->recordFile('ims-html-after', 'GET', $this->fileAddress('html'), $after);
        $this->recordFile('ims-attached-owner-after-visitor', 'GET', $this->fileAddress('attached-owner'), $after);
        $this->recordFile('ims-attached-owner-before-owner', 'GET', $this->fileAddress('attached-owner'), $before, 'owner');
        $this->recordFile('ims-attached-owner-after-owner', 'GET', $this->fileAddress('attached-owner'), $after, 'owner');
        $this->recordFile('ims-restricted-before-visitor', 'GET', $this->fileAddress('restricted'), $before);
        $this->recordFile('ims-restricted-before-admin', 'GET', $this->fileAddress('restricted'), $before, 'admin');
        $this->recordFile('ims-restricted-after-admin', 'GET', $this->fileAddress('restricted'), $after, 'admin');
        $this->recordFile('ims-recorded-after-admin', 'GET', $this->fileAddress('recorded'), $after, 'admin');
        $this->recordFile('ims-missing', 'GET', '/file/' . str_repeat('0', 32), $after);
        $this->addToAssertionCount(1);
    }
}
