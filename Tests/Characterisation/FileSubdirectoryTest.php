<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * A stored file on a site served from a subdirectory (`/blog/`): the legacy table holds the pattern under it, and the page is the same page. Asked on a
 * second server of the seeded site, as the other tests of a subdirectory are.
 */
final class FileSubdirectoryTest extends FileSiteCase
{
    /** This class asserts its answers itself and records none (the site case asks every class for the method). */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('FileSubdirectoryTest records no answer.');
    }

    public function testAFileIsServedUnderASubdirectory(): void
    {
        $ids = $this->seedFiles();
        $server = $this->startExtraServer(['IDNO_TEST_SUBDIRECTORY' => 'blog']);
        $client = new HttpSiteClient($server);

        $whole = $client->request('GET', '/blog/file/' . $ids['image'] . '/photo.png');
        $this->assertSame(200, $whole->status);
        $this->assertSame('image/png', $whole->header('Content-Type'));
        $this->assertSame(self::bytes(), $whole->body);
        $this->assertSame('300', $whole->header('Content-Length'));

        $range = $client->request('GET', '/blog/file/' . $ids['image'], ['Range' => 'bytes=-5']);
        $this->assertSame(206, $range->status);
        $this->assertSame('bytes 295-299/300', $range->header('Content-Range'));
        $this->assertSame(substr(self::bytes(), -5), $range->body);

        $head = $client->request('HEAD', '/blog/file/' . $ids['image']);
        $this->assertSame(200, $head->status);
        $this->assertSame('300', $head->header('Content-Length'));
        $this->assertSame('', $head->body);

        $this->assertSame(403, $client->request('GET', '/blog/file/' . $ids['restricted'])->status);
        $this->assertSame(404, $client->request('GET', '/blog/file/' . str_repeat('0', 32))->status);
    }
}
