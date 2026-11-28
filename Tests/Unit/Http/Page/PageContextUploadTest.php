<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Idno\Http\Page\UploadState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class PageContextUploadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/idno-upload-test-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function stored(string $contents = 'hello'): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param array<string, mixed> $files */
    private function context(array $files, string $method = 'POST', array $server = []): PageContext
    {
        return new PageContext(Request::create('/x', $method, [], [], $files, $server), new SitePage('Fixture\Page'));
    }

    // An import with nothing chosen must say so, not fail
    public function testAFieldWithNoFileIsMissing(): void
    {
        $upload = $this->context([])->file('import');

        $this->assertSame(UploadState::Missing, $upload->state);
        $this->assertFalse($upload->received());
    }

    // A form posted with the file input left empty (PHP reports UPLOAD_ERR_NO_FILE) is "nothing chosen", not "failed"
    public function testAnEmptyFileInputIsMissing(): void
    {
        $empty = new UploadedFile($this->stored(), '', null, UPLOAD_ERR_NO_FILE, true);

        $this->assertSame(UploadState::Missing, $this->context(['import' => $empty])->file('import')->state);
    }

    // The "larger than this server accepts" message must be shown for both of PHP's size refusals
    public function testAFileOverTheServersLimitIsTooLarge(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $code) {
            $file = new UploadedFile($this->stored(), 'big.xml', null, $code, true);

            $this->assertSame(UploadState::TooLarge, $this->context(['import' => $file])->file('import')->state, 'error ' . $code);
        }
    }

    // A person who sees "we couldn't open the file" when the disk is full or the upload was cut off
    public function testAnInterruptedUploadFails(): void
    {
        foreach ([UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION] as $code) {
            $file = new UploadedFile($this->stored(), 'x.xml', null, $code, true);

            $this->assertSame(UploadState::Failed, $this->context(['import' => $file])->file('import')->state, 'error ' . $code);
        }
    }

    // A body over post_max_size arrives with no files and no fields: a person must be told it was too large, not that nothing was chosen
    public function testABodyOverPostMaxSizeIsTooLarge(): void
    {
        $context = $this->context([], 'POST', ['CONTENT_LENGTH' => '9000000', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x']);

        $this->assertSame(UploadState::TooLarge, $context->file('import', 8 * 1048576)->state);
        $this->assertSame(UploadState::Missing, $context->file('import', 16 * 1048576)->state);
    }

    // A form that was posted and read normally (fields present) with a big Content-Length is not a refused body
    public function testAPostThatPhpReadAndHoldsNoFileIsMissingWhateverItsLength(): void
    {
        $context = new PageContext(Request::create('/x', 'POST', ['import_type' => 'wordpress'], [], [], ['CONTENT_LENGTH' => '9000000', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x']), new SitePage('Fixture\Page'));

        $this->assertSame(UploadState::Missing, $context->file('import', 8 * 1048576)->state);
    }

    // A path a request names in a field must never be read as an upload (it would read any file the server can)
    public function testAFileNotUploadedThroughHttpFails(): void
    {
        $notUploaded = new UploadedFile($this->stored(), 'passwd', null, UPLOAD_ERR_OK, false);

        $upload = $this->context(['import' => $notUploaded])->file('import');

        $this->assertSame(UploadState::Failed, $upload->state);
        $this->assertSame('', $upload->path);
    }

    // import[]=x (a list) must be "nothing chosen", not a TypeError
    public function testAListOfFilesUnderTheNameIsMissing(): void
    {
        $one = new UploadedFile($this->stored(), 'a.xml', null, UPLOAD_ERR_OK, true);
        $two = new UploadedFile($this->stored(), 'b.xml', null, UPLOAD_ERR_OK, true);

        $this->assertSame(UploadState::Missing, $this->context(['import' => [$one, $two]])->file('import')->state);
    }

    // The size is read from the file, and the path is the temporary file
    public function testAReceivedFileCarriesItsPathAndItsSizeOnDisk(): void
    {
        $path = $this->stored('twelve bytes');
        $file = new UploadedFile($path, 'export.xml', null, UPLOAD_ERR_OK, true);

        $upload = $this->context(['import' => $file])->file('import');

        $this->assertTrue($upload->received());
        $this->assertSame($path, $upload->path);
        $this->assertSame(12, $upload->size);
        $this->assertSame('export.xml', $upload->name);
    }

    // An upload stored under ../x, or a name with a line break in a log or a header
    public function testTheNameKeptIsABaseNameWithoutControlCharacters(): void
    {
        $file = new UploadedFile($this->stored(), "../../etc/pa\r\nss\x00wd", null, UPLOAD_ERR_OK, true);

        $upload = $this->context(['import' => $file])->file('import');

        $this->assertSame('passwd', $upload->name);
    }

    // A file that vanished between PHP receiving it and the page reading it is a failure, not a size of 0 received
    public function testAReceivedFileThatCannotBeReadFails(): void
    {
        $path = $this->stored();
        $file = new UploadedFile($path, 'x.xml', null, UPLOAD_ERR_OK, true);
        unlink($path);

        $this->assertSame(UploadState::Failed, $this->context(['import' => $file])->file('import')->state);
    }

    // A large JSON POST that PHP never parses into fields must not be reported as an upload that was too large
    public function testALargeBodyThatIsNotAFormIsNotTooLarge(): void
    {
        $context = $this->context([], 'POST', ['CONTENT_LENGTH' => '9000000', 'CONTENT_TYPE' => 'application/json']);

        $this->assertSame(UploadState::Missing, $context->file('import', 8 * 1048576)->state);
    }
}
