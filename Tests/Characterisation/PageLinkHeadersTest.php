<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * The `Link` headers every public page sends (the endpoints a page advertises, and the webmention, hub and self links the page's
 * listeners add), with the default hub and with none. Each test has a site of its own: one of them changes the site's settings.
 * The page events are recorded as in `PageContractTest`, whose site this one is.
 */
final class PageLinkHeadersTest extends PrivateSiteTestCase
{
    private string $recordFile = '';

    protected function setUp(): void
    {
        $this->recordFile = sys_get_temp_dir() . '/idno-pages-links-' . bin2hex(random_bytes(6));
        parent::setUp();
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
    }

    protected function tearDown(): void
    {
        if ($this->recordFile !== '' && is_file($this->recordFile)) {
            unlink($this->recordFile);
        }
        parent::tearDown();
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_RECORD_PAGES' => $this->recordFile];
    }

    /**
     * The `Link` headers of a page as they were sent, each as `<rel> <address>` with the site's own address as `@/`.
     *
     * @return list<string>
     */
    private function links(string $path): array
    {
        $lines = [];
        $ch = curl_init($this->baseUrl() . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                $lines[] = trim($line);

                return strlen($line);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        $links = [];
        foreach ($lines as $line) {
            if (stripos($line, 'link:') === 0) {
                $links[] = (string) preg_replace('/^Link: <([^>]*)>; rel="([^"]*)"$/', '$2 $1', str_replace($this->baseUrl(), '@/', $line));
            }
        }

        return $links;
    }

    public function testEachPageSendsTheLinksOfItsEndpointsAndTheHubsListenersWhenTheHubIsSet(): void
    {
        $endpoints = ['authorization_endpoint @/indieauth/auth', 'token_endpoint @/indieauth/token', 'micropub @/micropub/endpoint'];
        $listeners = static fn (string $self): array => ['http://webmention.org/ @/webmention/', 'webmention @/webmention/', 'hub https://withknown.superfeedr.com/', 'self @/' . $self];

        // The default hub: the webmention, hub and self links follow the endpoints, once on every page (the feed used to send them twice: its own
        // page and the home page it built each fired `page/head`).
        foreach (['', 'robots.txt', 'tag/x', 'content/all', 'feed.xml', 'search/mentions.json', 'chrome/manifest.json'] as $path) {
            $this->assertSame([...$endpoints, ...$listeners($path)], $this->links($path), '/' . $path);
        }
    }

    public function testEachPageSendsOnlyTheLinksOfItsEndpointsWhenTheHubIsEmpty(): void
    {
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->hub = ""; $config->save(); return true;');
        $endpoints = ['authorization_endpoint @/indieauth/auth', 'token_endpoint @/indieauth/token', 'micropub @/micropub/endpoint'];

        foreach (['', 'feed.xml', 'robots.txt', 'tag/x', 'content/all', 'search/mentions.json', 'chrome/manifest.json'] as $path) {
            $this->assertSame($endpoints, $this->links($path), '/' . $path);
        }
    }
}
