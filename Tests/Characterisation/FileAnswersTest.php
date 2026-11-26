<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Http\Page\HttpDate;
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
     * The cases the page that replaced the legacy one answers differently ON PURPOSE, with why: the recordings are the legacy page's and are kept as
     * they were, and each case is held to the recording but for what changed (`KINDS`).
     */
    private const CHANGED = [
        'html-visitor' => self::WHY_DOWNLOAD,
        'html-admin' => self::WHY_DOWNLOAD,
        'restricted-admin' => self::WHY_DOWNLOAD,
        'site-export-admin' => self::WHY_DOWNLOAD,
        'recorded-admin' => self::WHY_DOWNLOAD,
        'range-html' => self::WHY_DOWNLOAD,
        'range-restricted-admin' => self::WHY_DOWNLOAD,
        'range-recorded-admin' => self::WHY_DOWNLOAD,
        'range-site-export-admin' => self::WHY_DOWNLOAD,
        'head-html' => self::WHY_DOWNLOAD,
        'head-restricted-admin' => self::WHY_DOWNLOAD,
        'head-restricted-admin-range' => self::WHY_DOWNLOAD,
        'head-recorded-admin' => self::WHY_DOWNLOAD,
        'html-no-name' => self::WHY_DOWNLOAD,
        'html-name' => self::WHY_DOWNLOAD,
        'html-name-with-a-space' => self::WHY_DOWNLOAD,
        'html-upper-case-id' => self::WHY_DOWNLOAD,
        'zip-no-name' => self::WHY_DOWNLOAD,
        'ims-restricted-before-admin' => self::WHY_DOWNLOAD,
        'ims-restricted-after-admin' => self::WHY_DOWNLOAD,
        'ims-recorded-after-admin' => self::WHY_DOWNLOAD,
        'range-unsatisfiable' => self::WHY_REFUSED,
        'range-starts-at-the-size' => self::WHY_REFUSED,
        'range-inverted' => self::WHY_REFUSED,
        'range-empty-suffix' => self::WHY_REFUSED,
        'head-image-unsatisfiable' => self::WHY_REFUSED,
        'range-html-unsatisfiable' => self::WHY_DOWNLOAD . '; ' . self::WHY_REFUSED,
        'range-restricted-admin-unsatisfiable' => self::WHY_DOWNLOAD . '; ' . self::WHY_REFUSED,
        'malformed-host' => self::WHY_NOT_SERVED,
        'malformed-host-head' => self::WHY_NOT_SERVED,
        'put-visitor' => self::WHY_NOT_SERVED,
        'post-visitor' => self::WHY_NOT_SERVED,
        'delete-visitor' => self::WHY_NOT_SERVED,
        'image-doubled-slash-after-file' => self::WHY_NOT_SERVED,
        'html-name-dots' => 'an encoded slash in a name is a slash to the route (the legacy router, given the path with its dot segments resolved by the rig\'s server, answered the 404 page): the download is named after the last segment',
        'html-name-hostile' => 'the route takes any character in a name, a line break too, which the legacy pattern did not match (the 404 page): the download\'s name replaces every character that is not a letter, a digit or one of ._-',
        'xhr-restricted-visitor' => self::WHY_XHR,
        'xhr-restricted-admin' => self::WHY_XHR,
        'xhr-missing' => self::WHY_XHR,
        'ims-image-before' => self::WHY_CONDITIONAL,
        'ims-image-after' => self::WHY_CONDITIONAL,
        'ims-image-before-head' => self::WHY_CONDITIONAL,
        'ims-image-after-head' => self::WHY_CONDITIONAL,
        'ims-image-before-range' => self::WHY_CONDITIONAL,
        'ims-image-after-range' => self::WHY_CONDITIONAL,
        'ims-image-garbage' => self::WHY_CONDITIONAL . '; a date that is not an HTTP date is ignored',
        'ims-html-before' => self::WHY_CONDITIONAL,
        'ims-html-after' => self::WHY_CONDITIONAL,
        'ims-attached-owner-before-owner' => self::WHY_CONDITIONAL,
        'ims-attached-owner-after-owner' => self::WHY_CONDITIONAL,
    ];

    /**
     * What changed in each of them (see `assertTheAnswerThatChanged()`).
     *
     * @var array<string, array<string, mixed>>
     */
    private const KINDS = [
        'html-visitor' => ['name' => 'html.bin'],
        'html-admin' => ['name' => 'html.bin'],
        'restricted-admin' => ['name' => 'restricted.bin'],
        'site-export-admin' => ['name' => 'site-export.bin'],
        'recorded-admin' => ['name' => 'recorded.bin'],
        'range-html' => ['name' => 'x.bin'],
        'range-restricted-admin' => ['name' => 'x.bin'],
        'range-recorded-admin' => ['name' => 'x.bin'],
        'range-site-export-admin' => ['name' => 'x.bin'],
        'head-html' => ['name' => 'x.bin'],
        'head-restricted-admin' => ['name' => 'x.bin'],
        'head-restricted-admin-range' => ['name' => 'x.bin'],
        'head-recorded-admin' => ['name' => 'x.bin'],
        'html-no-name' => ['name' => 'download'],
        'html-name' => ['name' => 'page.html'],
        'html-name-with-a-space' => ['name' => 'some_name.html'],
        'html-upper-case-id' => ['name' => 'download'],
        'zip-no-name' => ['name' => 'download'],
        'ims-restricted-before-admin' => ['name' => 'download'],
        'ims-restricted-after-admin' => ['name' => 'download'],
        'ims-recorded-after-admin' => ['name' => 'download'],
        'range-unsatisfiable' => ['refused' => true],
        'range-starts-at-the-size' => ['refused' => true],
        'range-inverted' => ['refused' => true],
        'range-empty-suffix' => ['refused' => true],
        'head-image-unsatisfiable' => ['refused' => true],
        'range-html-unsatisfiable' => ['name' => 'x.bin', 'refused' => true],
        'range-restricted-admin-unsatisfiable' => ['name' => 'x.bin', 'refused' => true],
        'malformed-host' => ['page' => 404],
        'malformed-host-head' => ['page' => 404, 'head' => true],
        'put-visitor' => ['page' => 404],
        'post-visitor' => ['page' => 404],
        'delete-visitor' => ['page' => 404],
        'image-doubled-slash-after-file' => ['page' => 404],
        'html-name-dots' => ['status' => 200, 'bytes' => true, 'download' => 'x.html'],
        'html-name-hostile' => ['status' => 200, 'bytes' => true, 'download' => '__x______X-A_b.html'],
        'xhr-restricted-visitor' => ['xhr' => true],
        'xhr-restricted-admin' => ['xhr' => true],
        'xhr-missing' => ['xhr' => true],
        'ims-image-before' => ['status' => 200, 'bytes' => true],
        'ims-image-after' => ['status' => 304],
        'ims-image-before-head' => ['status' => 200],
        'ims-image-after-head' => ['status' => 304],
        'ims-image-before-range' => ['status' => 206, 'bytes' => 'ABCDEFGHIJ'],
        'ims-image-after-range' => ['status' => 304],
        'ims-image-garbage' => ['status' => 200, 'bytes' => true],
        'ims-html-before' => ['status' => 200, 'bytes' => true, 'download' => 'download'],
        'ims-html-after' => ['status' => 304],
        'ims-attached-owner-before-owner' => ['status' => 200, 'bytes' => true],
        'ims-attached-owner-after-owner' => ['status' => 304],
    ];

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

    public function testEveryCaseThatDiffersOnPurposeIsARecordedCaseAndHasItsAssertion(): void
    {
        $this->assertSame(array_keys(self::CHANGED), array_keys(self::KINDS));
        foreach (self::CHANGED as $case => $why) {
            $this->assertNotSame('', $why, $case);
            $this->assertFileExists(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt', $case);
        }
    }

    /** A download is called after the last segment of the address it is asked for at, whatever the file's id. */
    public function testADownloadIsNamedAfterItsAddress(): void
    {
        $id = $this->seedFiles()['html'];
        $names = ['/page.html' => 'page.html', '' => 'download', '/' => 'download', '/a/b/c.zip' => 'c.zip', '/some%20name.html' => 'some_name.html', '/..%2f..%2fx.html' => 'x.html'];
        foreach ($names as $rest => $name) {
            $answer = $this->site()->request('GET', '/file/' . $id . $rest);
            $this->assertSame(200, $answer->status, $rest);
            $this->assertSame('attachment; filename="' . $name . '"', $answer->header('Content-Disposition'), $rest);
            $this->assertStringNotContainsString($id, (string) $answer->header('Content-Disposition'), 'it is no longer the file\'s id');
        }
        $this->assertNull($this->site()->request('GET', $this->fileAddress('image', '/photo.png'))->header('Content-Disposition'), 'an image is shown, not downloaded');
    }

    /** What a public file's `Expires` is: an HTTP date in GMT, thirty days after the request (it was the server\'s time zone with a numeric offset). */
    public function testAPublicFilesExpiresIsAnHttpDate(): void
    {
        $answer = $this->site()->request('GET', $this->fileAddress('image'));

        $expires = (string) $answer->header('Expires');
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $expires);
        $this->assertNotNull(HttpDate::parse($expires), 'a recipient reads it');
        $date = HttpDate::parse((string) $answer->header('Date'));
        $this->assertNotNull($date);
        $this->assertEqualsWithDelta($date + 30 * 86400, HttpDate::parse($expires), 5);
        $this->assertSame('public', $answer->header('Cache-Control'));
        $this->assertSame('public', $answer->header('Pragma'));
    }

    /** A range nothing of the file satisfies has no body: the answer says so, whatever the length of the file. */
    public function testARefusedRangeHasNoBody(): void
    {
        $answer = $this->site()->request('GET', $this->fileAddress('image'), ['Range' => 'bytes=999999-']);

        $this->assertSame(416, $answer->status);
        $this->assertSame('bytes */300', $answer->header('Content-Range'));
        $this->assertSame('0', $answer->header('Content-Length'));
        $this->assertSame('', $answer->body);
    }

    /** A request for part of a file whose copy the client has is answered "not modified" (as a request for all of it is), never with the part. */
    public function testARangeWithASatisfiedConditionIsNotModified(): void
    {
        $future = HttpDate::format(time() + 86400);
        foreach ([[], ['Range' => 'bytes=0-9']] as $range) {
            $answer = $this->site()->request('GET', $this->fileAddress('image'), ['If-Modified-Since' => $future] + $range);
            $this->assertSame(304, $answer->status, json_encode($range));
            $this->assertSame('', $answer->body);
            $this->assertNull($answer->header('Content-Range'));
        }
        // beside an If-None-Match the date is ignored
        $this->assertSame(200, $this->site()->request('GET', $this->fileAddress('image'), ['If-Modified-Since' => $future, 'If-None-Match' => '"x"'])->status);
        // an archive of the site is always sent
        $this->assertSame(200, $this->site()->request('GET', $this->fileAddress('restricted'), $this->asViewer('admin', ['If-Modified-Since' => $future]))->status);
    }

    /**
     * A HEAD answers with the headers and reads nothing of the file: the stored bytes are made unreadable, the file is still found (it is a stat), and
     * a GET (which must read them) says in the site's log that it could not, where a HEAD says nothing.
     */
    public function testAHeadReadsNothing(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('A file cannot be made unreadable to root.');
        }
        $id = $this->seedFiles()['pdf'];
        $stored = glob($this->uploadDirectory() . '/*/0/0/0/0/' . $id . '.file');
        $this->assertCount(1, $stored ?: [], 'the file is in the upload directory');
        $before = $this->logOffset();
        chmod($stored[0], 0000);
        try {
            $head = $this->site()->request('HEAD', $this->fileAddress('pdf'));
            $this->assertSame(200, $head->status);
            $this->assertSame('300', $head->header('Content-Length'));
            $this->assertSame('', $head->body);
            $this->assertStringNotContainsString('Could not read a file', $this->logSince($before), 'a HEAD read nothing');

            try {
                $this->site()->request('GET', $this->fileAddress('pdf'));
            } catch (\RuntimeException) {
                // (the answer announces 300 bytes and holds none: the client says so)
            }
            $this->assertStringContainsString('Could not read a file', $this->logSince($before), 'a GET reads the file');
        } finally {
            chmod($stored[0], 0644);
        }
    }
}
