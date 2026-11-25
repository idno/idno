<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use PHPUnit\Framework\TestCase;

/**
 * Whether the site wants HTTPS: an https `url`, or `force_ssl`.
 */
final class ConfigHasSslTest extends TestCase
{
    private mixed $url = null;

    private mixed $force = null;

    protected function setUp(): void
    {
        $config = Idno::site()->config();
        $this->url = $config->url;
        $this->force = $config->force_ssl;
    }

    protected function tearDown(): void
    {
        $config = Idno::site()->config();
        $config->url = $this->url;
        $config->force_ssl = $this->force;
    }

    public function testAnHttpSiteWithNoForceSslDoesNotWantHttps(): void
    {
        $config = Idno::site()->config();
        $config->url = 'http://example.test/';
        $config->force_ssl = null;

        $this->assertFalse($config->hasSSL());
    }

    public function testForceSslWantsHttpsOnAnHttpSite(): void
    {
        $config = Idno::site()->config();
        $config->url = 'http://example.test/';
        $config->force_ssl = true;

        $this->assertTrue($config->hasSSL());
    }

    public function testAnHttpsUrlWantsHttps(): void
    {
        $config = Idno::site()->config();
        $config->url = 'https://example.test/';
        $config->force_ssl = null;

        $this->assertTrue($config->hasSSL());
    }
}
