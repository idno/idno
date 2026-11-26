<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The profile of a site served from a subdirectory (`/blog/`): the legacy table holds the patterns under it, and the page is the same page. Asked on a
 * second server of the seeded site, as the other tests of a subdirectory are.
 */
final class ProfileSubdirectoryTest extends ProfileSiteCase
{
    /** This class asserts its answers itself and records none (the site case asks every class for the method). */
    protected function assertRecordedAnswer(string $group, string $case, SiteResponse $response): void
    {
        throw new \LogicException('ProfileSubdirectoryTest records no answer.');
    }

    public function testTheProfileIsServedUnderASubdirectoryInEachForm(): void
    {
        $this->seedProfiles();
        $server = $this->startExtraServer(['IDNO_TEST_SUBDIRECTORY' => 'blog']);
        $client = new HttpSiteClient($server);

        $html = $client->request('GET', '/blog/profile/ada');
        $this->assertSame(200, $html->status);
        $this->assertStringContainsString('Ada Admin', $html->body);
        $this->assertNotNull($html->header('Last-Modified'));
        $this->assertStringContainsStringIgnoringCase('accept', (string) $html->header('Vary'));

        $friendly = $client->request('GET', '/blog/profile/ada/all');
        $this->assertSame(200, $friendly->status);
        $this->assertStringContainsString('Ada Admin', $friendly->body);

        $json = $client->request('GET', '/blog/profile/ada?_t=json');
        $this->assertSame(200, $json->status);
        $this->assertStringContainsString('Ada Admin', $json->body);

        $head = $client->request('HEAD', '/blog/profile/ada');
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);

        $this->assertSame(404, $client->request('GET', '/blog/profile/nobody-has-this-handle')->status);
        $this->assertSame(204, $client->request('OPTIONS', '/blog/profile/ada')->status);
        $this->assertSame(302, $client->request('POST', '/blog/profile/ada')->status, 'a POST with no form token is sent back with a message');
    }
}
