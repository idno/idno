<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A webmention to a page a plugin registers as a legacy class (`Tests\Fixtures\MentionablePage`, at `/_test/mentionable/<id>/`): the endpoint finds the page from the
 * target's address, builds it as the router did, hands it the mention through its own `webmentionContent()` and answers 202, without the page's constructor leaving
 * anything on the request being served (the headers every legacy page sends are not sent a second time beside the response's). The source is a real page the
 * site fetches from a stand-in address (`Tests\Fixtures\RemoteDocumentController`).
 */
final class WebmentionLegacyPageTest extends PrivateSiteTestCase
{
    private string $record = '';

    private string $cookie = '';

    protected function setUp(): void
    {
        $this->record = (string) tempnam(sys_get_temp_dir(), 'idno-mention-record');
        parent::setUp();
        $this->cookie = $this->registerUser('ada');
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            @unlink($this->record);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_MENTIONABLE' => '1', 'IDNO_TEST_MENTION_RECORD' => $this->record, 'IDNO_TEST_REMOTE_STAND_IN' => '93.184.216.34:80'];
    }

    private function mention(string $name, string $target): SiteResponse
    {
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="http://93.184.216.34/' . $name . '">permalink</a><a class="u-in-reply-to" href="' . $target . '">a page</a><div class="e-content">Words.</div></div></body></html>';
        $this->assertSame(204, $this->site()->request('PUT', '_test/remote/' . $name, ['Content-Type' => 'text/html'], $html)->status);

        return $this->site()->request('POST', 'webmention/', [], ['source' => 'http://93.184.216.34/' . $name, 'target' => $target]);
    }

    public function testAPluginsLegacyPageReceivesTheMentionThroughItsOwnMethod(): void
    {
        $target = $this->baseUrl() . '_test/mentionable/abc/#there';

        $answer = $this->mention('legacy-source', $target);

        $this->assertSame(202, $answer->status);
        $this->assertSame('', $answer->body);
        $recorded = array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->record)))));
        $this->assertCount(1, $recorded);
        $this->assertSame(['abc'], $recorded[0]['arguments']);
        $this->assertTrue($recorded[0]['permalink']);
        $this->assertSame('http://93.184.216.34/legacy-source', $recorded[0]['source']);
        $this->assertSame($this->baseUrl() . '_test/mentionable/abc/', $recorded[0]['target'], 'the fragment is taken off');
        $this->assertSame(200, $recorded[0]['response']['response']);
    }

    private function token(): string
    {
        $answer = $this->site()->request('GET', 'service/security/csrftoken/', ['Cookie' => $this->cookie, 'X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $answer->status);

        return (string) json_decode($answer->body, true)['token'];
    }

    /**
     * The endpoint takes a request that carries a member's cookie as nobody's, and leaves the browser's session as it was: the same session, the same user and the same
     * form token afterwards, and no cookie set.
     */
    public function testABrowsersSessionSurvivesAPostToTheEndpoint(): void
    {
        $token = $this->token();
        $target = $this->baseUrl() . '_test/mentionable/abc/';
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="http://93.184.216.34/session-source">permalink</a><a class="u-in-reply-to" href="' . $target . '">a page</a></div></body></html>';
        $this->assertSame(204, $this->site()->request('PUT', '_test/remote/session-source', ['Content-Type' => 'text/html'], $html)->status);

        $answer = $this->site()->request('POST', 'webmention/', ['Cookie' => $this->cookie], ['source' => 'http://93.184.216.34/session-source', 'target' => $target]);

        $this->assertSame(202, $answer->status);
        $this->assertSame([], $answer->lines('Set-Cookie'), 'no session cookie is set, replaced or expired');
        $this->assertSame($token, $this->token(), 'the form token is the one it was');
        $this->assertSame(200, $this->site()->request('GET', 'admin/', ['Cookie' => $this->cookie])->status, 'the administrator is still signed in');
        $recorded = json_decode(trim((string) file_get_contents($this->record)), true);
        $this->assertSame(['abc'], $recorded['arguments']);
    }

    public function testBuildingTheLegacyPageSendsNoHeaderTwice(): void
    {
        $answer = $this->mention('legacy-headers', $this->baseUrl() . '_test/mentionable/abc/');

        $this->assertSame(202, $answer->status);
        foreach (['x-powered-by', 'x-clacks-overhead', 'x-idno-build-fingerprint'] as $name) {
            $this->assertLessThanOrEqual(1, count($answer->lines($name)), $name);
        }
    }

    public function testAPageTheLegacyRouterServesAndThatTakesNoMentionIsNoTarget(): void
    {
        // (the page has a getContent() and the base page's webmentionContent(): a mention of it is refused before the source is fetched)
        $answer = $this->site()->request('POST', 'webmention/', [], ['source' => 'http://93.184.216.34/never-fetched', 'target' => $this->baseUrl() . 'session/login']);

        $this->assertSame(400, $answer->status);
        $this->assertSame('target_not_found', json_decode($answer->body, true)['error']);
        $this->assertSame([], $this->site()->request('GET', '_test/remote-fetched')->json());
    }
}
