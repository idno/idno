<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * No template sends a header: the code that draws a page does. Header values are what the templates sent before.
 * On a site of its own so that the page of a site with no user can be asked for.
 */
final class PageHeadersTest extends PrivateSiteTestCase
{
    public function testHeadersAreSentByTheCodeThatDrawsThePage(): void
    {
        $site = $this->site();

        // a site with no user says that it is being set up: a page of its own, which no template draws
        $pending = $site->request('GET', '');
        $this->assertSame(503, $pending->status, $this->serverLog());
        $this->assertStringStartsWith('text/html', (string) $pending->header('Content-Type'));
        $this->assertSame('no-store', $pending->header('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", (string) $pending->header('Content-Security-Policy'));

        $this->registerUser('writer');
        $home = $site->request('GET', '');
        $this->assertSame(200, $home->status, $this->serverLog());
        $this->assertStringStartsWith('text/html', (string) $home->header('Content-Type'));
        $this->assertSame('*', $home->header('Access-Control-Allow-Origin'));

        $robots = $site->request('GET', 'robots.txt');
        $this->assertSame(200, $robots->status, $this->serverLog());
        $this->assertStringStartsWith('text/plain', (string) $robots->header('Content-Type'));
        $this->assertSame("User-agent: *\nDisallow:\n", $robots->body);

        $manifest = $site->request('GET', 'chrome/manifest.json');
        $this->assertSame(200, $manifest->status, $this->serverLog());
        $this->assertStringStartsWith('application/json', (string) $manifest->header('Content-Type'));
        // the whole body, byte for byte: the page builds what the template used to
        $site = str_replace('/', '\\/', rtrim($this->baseUrl(), '/'));
        $this->assertSame(str_replace('%SITE%', $site, <<<'JSON'
{
    "name": "Writer's Idno",
    "short_name": "Writer's Idno",
    "icons": [
        {
            "src": "%SITE%\/gfx\/logos\/logo_k_36.png",
            "sizes": "36x36",
            "type": "image\/png"
        },
        {
            "src": "%SITE%\/gfx\/logos\/logo_k_48.png",
            "sizes": "48x48",
            "type": "image\/png"
        },
        {
            "src": "%SITE%\/gfx\/logos\/apple-icon-72x72.png",
            "sizes": "72x72",
            "type": "image\/png"
        },
        {
            "src": "%SITE%\/gfx\/logos\/logo_k_96.png",
            "sizes": "96x96",
            "type": "image\/png"
        },
        {
            "src": "%SITE%\/gfx\/logos\/apple-icon-144x144.png",
            "sizes": "144x144",
            "type": "image\/png"
        },
        {
            "src": "%SITE%\/gfx\/logos\/logo_k_192.png",
            "sizes": "192x192",
            "type": "image\/png"
        }
    ],
    "start_url": "%SITE%\/session\/login",
    "display": "standalone",
    "scope": "\/"
}
JSON), $manifest->body);
        $json = $manifest->json();
        $this->assertSame($this->runInSite('return \Idno\Core\Idno::site()->config()->getTitle();'), $json['name']);
        $this->assertCount(6, $json['icons']);
        $this->assertSame('standalone', $json['display']);
    }

    public function testThePasswordRecoveryPageSendsTheShellHeadersToo(): void
    {
        $page = $this->site()->request('GET', 'account/password');

        $this->assertSame(200, $page->status, $this->serverLog());
        $this->assertSame('*', $page->header('Access-Control-Allow-Origin'));
        $this->assertStringStartsWith('text/html', (string) $page->header('Content-Type'));
    }
}
