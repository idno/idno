<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Webservice;

/**
 * The client asks for no compression (`Accept-Encoding: identity`): the Symfony client decodes a
 * compressed body a chunk at a time and a few kilobytes of gzip decode to megabytes, which the download
 * limit cannot bound (measured: 17 MB of PHP memory for a 40 MB body with the limit at 1 MB). So a body
 * that is small on the wire and large once decoded must cost no memory: /_test/gzip?bytes=N sends the
 * gzip of N zero bytes whatever the request asks for. Every request is a real one, made from this process, against the site's own test route.
 */
final class WebserviceCompressionTest extends CharacterisationTestCase
{
    private const LIMIT = 1024 * 1024;

    /** @var mixed */
    private $previousMaxDownload;
    private string $previousErrorLog = '';
    private string $errorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-gzip-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
        $this->previousMaxDownload = \Idno\Core\Idno::site()->config()->webservice_max_download;
        \Idno\Core\Idno::site()->config()->webservice_max_download = self::LIMIT;
    }

    protected function tearDown(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = $this->previousMaxDownload;
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        parent::tearDown();
    }

    public function testTheRouteSendsAGzipBodyThatIsSmallOnTheWire(): void
    {
        $response = $this->site()->request('GET', '_test/gzip?bytes=2000000');

        $this->assertSame(200, $response->status);
        $this->assertSame('gzip', $response->header('Content-Encoding'));
        $this->assertLessThan(100000, (int) $response->header('Content-Length'));
    }

    public function testASmallBodyThatDecodesToMoreThanTheLimitIsCutAndDoesNotFillMemory(): void
    {
        $url = $this->site()->baseUrl() . '_test/gzip?bytes=' . (40 * 1024 * 1024);
        Webservice::get($this->site()->baseUrl() . '_test/ping'); // loads the client's classes first

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $result = Webservice::get($url);
        $rise = memory_get_peak_usage() - $before;

        // The client names no encoding, so a body a server compresses anyway is not decoded: the
        // caller gets the bytes that were sent, which are under the limit.
        $this->assertSame('', $result['error']);
        $this->assertLessThanOrEqual(self::LIMIT, strlen($result['content']));
        $this->assertStringStartsWith("\x1f\x8b", $result['content'], 'the compressed bytes, not 40 MB of zeros');
        $this->assertLessThan(8 * 1024 * 1024, $rise, 'PHP memory rose by ' . $rise . ' bytes for a 40 MB body of a few kilobytes on the wire');
    }
}
