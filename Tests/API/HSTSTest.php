<?php

namespace Tests\API {

    /**
     * Test HSTS handling on webservice calls using synthetic headers
     * so tests do not depend on external websites being reachable.
     */
    class HSTSTest extends \Tests\IdnoTestCase
    {

        function setUp(): void
        {

            // Tidy up
            if ($cache = \Idno\Core\Idno::site()->cache()) {
                 $cache->delete(parse_url('http://localhost', PHP_URL_HOST));
                 $cache->delete(parse_url('http://example.com', PHP_URL_HOST));
                 $cache->delete('127.0.0.1'); // the rig's own address must never stay pinned to https
            }

        }

        /**
         * Test that a URL without HSTS headers is not detected as HSTS
         */
        function testNoHSTS()
        {

            // Feed headers that do NOT contain Strict-Transport-Security
            \Idno\Core\Webservice::checkForHSTSHeader(
                'http://localhost',
                ["HTTP/1.1 200 OK", "Content-Type: text/html"]
            );

            $this->assertFalse(\Idno\Core\Webservice::isHSTS('http://localhost'), 'Should have detected that http://localhost does not have HSTS headers.');

        }

        /**
         * Test that HSTS headers are detected and cached correctly
         */
        function testHSTS()
        {
            // Feed a synthetic Strict-Transport-Security header
            \Idno\Core\Webservice::checkForHSTSHeader(
                'https://example.com',
                ["HTTP/1.1 200 OK", "Strict-Transport-Security: max-age=31536000; includeSubDomains"]
            );

            $this->assertTrue(\Idno\Core\Webservice::isHSTS('http://example.com'), 'Should have detected HSTS headers from the synthetic response.');

        }

        /**
         * The expiry the pool's file for a key records (the first line of a FilesystemAdapter file).
         */
        private function poolExpiry(string $key): ?int
        {
            $base = \Idno\Kernel\CacheDirResolver::fromEnvironment(dirname(__DIR__, 2));
            $directory = \Idno\Cache\CachePoolFactory::directory(\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all(), $base);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory . '/' . md5((string) \Idno\Core\Idno::site()->config()->host), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $lines = explode("\n", (string) file_get_contents($file->getPathname()), 3);
                if (($lines[1] ?? null) === $key) {
                    return (int) $lines[0];
                }
            }

            return null;
        }

        /**
         * The entry's lifetime in the pool is its max-age, never more than a year.
         */
        function testAnHstsEntryIsStoredWithItsMaxAgeAsTtl()
        {
            \Idno\Core\Webservice::checkForHSTSHeader('https://example.com', ["Strict-Transport-Security: max-age=600"]);
            $expiry = $this->poolExpiry('example.com');
            $this->assertNotNull($expiry);
            $this->assertGreaterThan(0, $expiry, 'the entry must expire');
            $this->assertEqualsWithDelta(time() + 600, $expiry, 5);

            \Idno\Core\Idno::site()->cache()->delete('example.com');
            \Idno\Core\Webservice::checkForHSTSHeader('https://example.com', ["Strict-Transport-Security: max-age=999999999999"]);
            $this->assertEqualsWithDelta(time() + 31536000, $this->poolExpiry('example.com'), 5);
        }

        /**
         * An entry the pool still holds after its max-age (a pool entry may outlive it) upgrades nothing.
         */
        function testAnExpiredEntryNoLongerUpgradesTheRequest()
        {
            \Idno\Core\Idno::site()->cache()->store('example.com', serialize(['stored_ts' => time() - 100, 'max-age' => 10, 'includeSubDomains' => false]));
            $this->assertFalse(\Idno\Core\Webservice::isHSTS('http://example.com'));

            \Idno\Core\Idno::site()->cache()->store('example.com', serialize(['stored_ts' => time() - 100, 'max-age' => 1000, 'includeSubDomains' => false]));
            $this->assertTrue(\Idno\Core\Webservice::isHSTS('http://example.com'));
        }

        /**
         * max-age=0 switches HSTS off for the host (RFC 6797, 6.1.1): a stored entry is removed, and none is stored.
         */
        function testMaxAgeZeroRemovesTheEntry()
        {
            \Idno\Core\Webservice::checkForHSTSHeader('https://example.com', ["Strict-Transport-Security: max-age=31536000"]);
            $this->assertTrue(\Idno\Core\Webservice::isHSTS('http://example.com'));

            \Idno\Core\Webservice::checkForHSTSHeader('https://example.com', ["Strict-Transport-Security: max-age=0"]);
            $this->assertFalse(\Idno\Core\Webservice::isHSTS('http://example.com'));
            $this->assertNull(\Idno\Core\Idno::site()->cache()->load('example.com'));

            \Idno\Core\Webservice::checkForHSTSHeader('https://localhost', ["Strict-Transport-Security: max-age=0"]);
            $this->assertFalse(\Idno\Core\Webservice::isHSTS('http://localhost'));
            $this->assertNull(\Idno\Core\Idno::site()->cache()->load('localhost'));
        }

        function tearDown(): void
        {

            if ($cache = \Idno\Core\Idno::site()->cache()) {
                 $cache->delete(parse_url('http://localhost', PHP_URL_HOST));
                 $cache->delete(parse_url('http://example.com', PHP_URL_HOST));
                 $cache->delete('127.0.0.1');
            }
        }

    }

}
