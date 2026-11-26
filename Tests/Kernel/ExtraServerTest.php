<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Characterisation\HttpSiteClient;
use Tests\Support\PrivateSiteTestCase;

/**
 * PrivateSiteTestCase::startExtraServer(): a second server of the same site, on a port of its own, with the settings it is given.
 */
final class ExtraServerTest extends PrivateSiteTestCase
{
    public function testASecondServerServesTheSameSiteInAnotherEnvironment(): void
    {
        // A site with no account answers 503 (it is being set up): this one has its administrator.
        $this->registerUser('extra');
        $url = $this->startExtraServer(['APP_ENV' => 'prod', 'APP_DEBUG' => '0'], ['opcache.enable_cli' => '1']);

        $this->assertNotSame($this->baseUrl(), $url);
        $this->assertStringStartsWith('http://127.0.0.1:', $url);
        $this->assertNotSame(8089, (int) parse_url($url, PHP_URL_PORT));
        $this->assertSame(200, $this->site()->request('GET', '_test/ping')->status, 'the test server is the test environment: its test routes exist');
        $other = new HttpSiteClient($url);
        $this->assertSame(404, $other->request('GET', '_test/ping')->status, 'the second server is the production environment: no test routes');
        $this->assertContains($other->request('GET', '')->status, [200, 302], 'and it serves the site' . $this->serverLog());
    }

    public function testAServerCanBeTheTestEnvironmentWithOpcache(): void
    {
        $url = $this->startExtraServer(['APP_ENV' => 'test', 'APP_DEBUG' => '0'], ['opcache.enable_cli' => '1']);

        $probe = (new HttpSiteClient($url))->request('GET', '_test/server');

        $this->assertSame(200, $probe->status);
        $this->assertSame(['env' => 'test', 'debug' => '0', 'opcache' => true], array_intersect_key($probe->json(), array_flip(['env', 'debug', 'opcache'])));
        $own = $this->site()->request('GET', '_test/server')->json();
        $this->assertSame(['test', '1'], [$own['env'], $own['debug']], 'the test\'s own server: debug on');
    }
}
