<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The permalink of a site served from a subdirectory (`/blog/`): the legacy table holds the patterns under it, and the page is the same page. Asked on a
 * second server of the seeded site, as the other tests of a subdirectory are.
 */
final class PermalinkSubdirectoryTest extends PermalinkSiteCase
{
    /** This class asserts its answers itself and records none (the site case asks every class for the method). */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('PermalinkSubdirectoryTest records no answer.');
    }

    public function testThePermalinkIsServedUnderASubdirectoryInEachForm(): void
    {
        $paths = $this->seedPermalinkContent();
        $note = '/blog/' . $paths['bob-public'];
        $server = $this->startExtraServer(['IDNO_TEST_SUBDIRECTORY' => 'blog']);
        $client = new HttpSiteClient($server);

        $html = $client->request('GET', $note);
        $this->assertSame(200, $html->status);
        $this->assertStringContainsString('Bob publishes a note anyone can read.', $html->body);
        $this->assertStringContainsStringIgnoringCase('accept', (string) $html->header('Vary'));
        $this->assertNotNull($html->header('Last-Modified'));

        $markdown = $client->request('GET', $note . '.md');
        $this->assertSame(200, $markdown->status);
        $this->assertStringContainsString('text/markdown', (string) $markdown->header('Content-Type'));
        $this->assertStringContainsString('Bob publishes a note anyone can read.', $markdown->body);

        $json = $client->request('GET', $note . '?_t=json');
        $this->assertSame(200, $json->status);
        $this->assertStringContainsString('Bob publishes a note anyone can read.', $json->body);

        $head = $client->request('HEAD', $note);
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);

        $this->assertSame(410, $client->request('GET', '/blog/2026/no-such-slug-here')->status);
        $this->assertSame(410, $client->request('GET', '/blog/' . $paths['bob-owner'])->status, 'a post a visitor may not read is answered as a missing one');
        $this->assertSame(403, $client->request('DELETE', $note)->status, 'a DELETE with no form token');
    }
}
