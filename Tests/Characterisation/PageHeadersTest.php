<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * No template sends a header: the code that draws a page does. Header values are what the templates sent before.
 * On a site of its own so that the onboarding page of a site with no user can be asked for.
 */
final class PageHeadersTest extends PrivateSiteTestCase
{
    public function testHeadersAreSentByTheCodeThatDrawsThePage(): void
    {
        $site = $this->site();

        $register = $site->request('GET', 'begin/register');
        $this->assertSame(200, $register->status, $this->serverLog());
        $this->assertStringStartsWith('text/html', (string) $register->header('Content-Type'));
        $this->assertSame('*', $register->header('Access-Control-Allow-Origin'));
        // two header() calls with one name: the second replaces the first, as it did when the shell sent them
        $this->assertSame('<' . $this->baseUrl() . 'webmention/>; rel="webmention"', $register->header('Link'));

        // a site with no user sends its visitors to onboarding: the home page needs one
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
