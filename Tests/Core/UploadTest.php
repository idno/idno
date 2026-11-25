<?php

namespace Tests\Core;

use Idno\Core\Upload;

/**
 * What the server accepts as an upload, in plain units, and what a member is told when a
 * file did not arrive: never PHP's own text about php.ini directives.
 */
class UploadTest extends \Tests\IdnoTestCase
{

    public function testSizesAreReadAsPhpWritesThem()
    {
        $this->assertSame(2 * 1024 * 1024, Upload::bytes('2M'));
        $this->assertSame(2 * 1024 * 1024, Upload::bytes('2m'));
        $this->assertSame(512 * 1024, Upload::bytes('512K'));
        $this->assertSame(1024 * 1024 * 1024, Upload::bytes('1G'));
        $this->assertSame(8388608, Upload::bytes('8388608'));
        $this->assertSame(0, Upload::bytes('0'), 'Zero means no limit.');
        $this->assertSame(0, Upload::bytes('-1'));
        $this->assertSame(0, Upload::bytes(''));
        $this->assertSame(0, Upload::bytes(false));
    }

    public function testSizesAreWrittenInPlainUnits()
    {
        $this->assertSame('2 MB', Upload::formatSize(2 * 1024 * 1024));
        $this->assertSame('1.5 MB', Upload::formatSize(1572864));
        $this->assertSame('512 KB', Upload::formatSize(512 * 1024));
        $this->assertSame('1 GB', Upload::formatSize(1024 * 1024 * 1024));
        $this->assertSame('900 bytes', Upload::formatSize(900));
    }

    public function testTheLimitsAreTheServers()
    {
        $file = Upload::bytes(ini_get('upload_max_filesize'));
        $request = Upload::bytes(ini_get('post_max_size'));

        $this->assertSame($request, Upload::maxRequestSize());
        $expected = array_filter([$file, $request]);
        $this->assertSame($expected ? min($expected) : 0, Upload::maxFileSize(), 'One file can be no larger than the request that carries it.');
    }

    public function testAFileThatIsTooLargeIsNamedWithTheLimit()
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $code) {
            $message = Upload::errorMessage($code, 'baby.jpg');
            $this->assertStringContainsString('baby.jpg', $message);
            $this->assertStringContainsString('too large', $message);
            if ($limit = Upload::maxFileSize()) {
                $this->assertStringContainsString(Upload::formatSize($limit), $message, 'The limit is given in plain units.');
            }
            $this->assertStringContainsString('Nothing was saved', $message);
            $this->assertStringNotContainsString('php.ini', $message);
            $this->assertStringNotContainsString('directive', $message);
        }
    }

    public function testEveryOtherErrorHasAMessageToo()
    {
        foreach ([UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION, 99] as $code) {
            $message = Upload::errorMessage($code, 'baby.jpg');
            $this->assertStringContainsString('baby.jpg', $message, "Upload error $code has no message.");
            $this->assertStringContainsString('Nothing was saved', $message);
        }
        $this->assertStringContainsString('did not finish uploading', Upload::errorMessage(UPLOAD_ERR_PARTIAL));
        $this->assertSame('', Upload::errorMessage(UPLOAD_ERR_OK));
        $this->assertSame('', Upload::errorMessage(UPLOAD_ERR_NO_FILE), 'Whether a missing file matters is for the form to say.');
    }

    public function testAFileNameIsEscaped()
    {
        $message = Upload::errorMessage(UPLOAD_ERR_PARTIAL, '"><script>alert(1)</script>.jpg');

        $this->assertStringNotContainsString('<script', $message, 'Messages are printed as HTML.');
    }

    public function testARequestThatPhpThrewAwayIsRecognised()
    {
        $limit = Upload::maxRequestSize();
        if (!$limit) {
            $this->markTestSkipped('This PHP has no post_max_size.');
        }
        $keep = [$_SERVER['REQUEST_METHOD'] ?? null, $_SERVER['CONTENT_LENGTH'] ?? null, $_POST, $_FILES];

        try {
            $_POST = [];
            $_FILES = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_LENGTH'] = (string) ($limit + 1);
            $this->assertTrue(Upload::requestWasTooLarge());
            $this->assertStringContainsString(Upload::formatSize($limit), Upload::requestTooLargeMessage());

            $_SERVER['CONTENT_LENGTH'] = (string) $limit;
            $this->assertFalse(Upload::requestWasTooLarge(), 'A request of exactly the limit is read.');

            $_SERVER['CONTENT_LENGTH'] = (string) ($limit + 1);
            $_POST = ['title' => 'It arrived'];
            $this->assertFalse(Upload::requestWasTooLarge(), 'A request whose fields arrived was not thrown away.');

            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $this->assertFalse(Upload::requestWasTooLarge());
        } finally {
            [$method, $length, $_POST, $_FILES] = $keep;
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
            if ($length === null) {
                unset($_SERVER['CONTENT_LENGTH']);
            } else {
                $_SERVER['CONTENT_LENGTH'] = $length;
            }
        }
    }

    public function testOnlyImagesABrowserCanShowAreImages()
    {
        $dir = sys_get_temp_dir();
        $gif = tempnam($dir, 'idno-upload-test-');
        file_put_contents($gif, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
        $text = tempnam($dir, 'idno-upload-test-');
        file_put_contents($text, 'Not a picture at all');

        try {
            $this->assertTrue(Upload::isShowableImage($gif, 'pixel.gif'));
            $this->assertFalse(Upload::isShowableImage($text, 'holiday.jpg'), 'The content decides, not the name.');
            $this->assertFalse(Upload::isShowableImage($text, 'holiday.heic'));
            $this->assertFalse(Upload::isShowableImage('', 'nothing.jpg'));
            $this->assertTrue(Upload::isShowableImage($text, 'drawing.svg'), 'SVG has no signature and goes by its name, as before.');
        } finally {
            unlink($gif);
            unlink($text);
        }

        $this->assertSame('image/jpeg,image/png,image/gif,image/webp,image/avif,image/svg+xml', Upload::imageAccept());
        $this->assertSame('JPEG, PNG, GIF, WebP, AVIF or SVG', Upload::typeList());
    }
}
