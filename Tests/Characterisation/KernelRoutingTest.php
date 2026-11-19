<?php

namespace Tests\Characterisation {

    /**
     * Every request goes through the Symfony kernel; anything its router does not match
     * is served by the legacy router exactly as before. The expected values were observed
     * on the code before the kernel handled requests.
     */
    class KernelRoutingTest extends CharacterisationTestCase
    {
        /**
         * Headers added by the web server rather than the application; they differ
         * between PHP's built-in server and Apache.
         */
        private const SERVER_HEADERS = ['date', 'server', 'host', 'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'content-encoding', 'vary'];

        /**
         * Application header names, sorted, minus SERVER_HEADERS.
         *
         * @return list<string>
         */
        private function applicationHeaderNames(SiteResponse $response): array
        {
            $names = array_values(array_diff(array_keys($response->headers), self::SERVER_HEADERS));
            sort($names);

            return $names;
        }

        public function testNewRouteWinsOverLegacyFallback(): void
        {
            $r = $this->site()->request('GET', '_test/ping');

            $this->assertSame(200, $r->status);
            $this->assertSame('pong', $r->body);
            $this->assertSame('1', $r->header('X-Idno-Kernel'));
        }

        public function testSessionsAreFilesOnSqliteAndDatabaseRowsOnMysql(): void
        {
            $r = $this->site()->request('GET', '_test/session');

            $this->assertSame(200, $r->status);
            $family = \Idno\Storage\ConnectionParameters::driverFamily(\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all());
            $this->assertSame(
                ['module' => $family === 'sqlite' ? 'files' : 'user'],
                json_decode($r->body, true),
                'an SQLite site keeps its sessions in files (nothing locks them in the database), a MySQL or MariaDB site in the database',
            );
        }

        public function testLegacyPageHasNoSymfonyDefaultHeaders(): void
        {
            $r = $this->site()->request('GET', '');

            $this->assertSame('no-store, no-cache, must-revalidate', $r->header('Cache-Control'));
            $this->assertNull($r->header('X-Robots-Tag'));
        }

        public function testHeaderSetIsUnchangedForLegacyPages(): void
        {
            $r = $this->site()->request('GET', '');

            $this->assertSame(200, $r->status);
            $this->assertSame(
                [
                    'access-control-allow-origin',
                    'cache-control',
                    'content-type',
                    'expires',
                    'last-modified',
                    'link',
                    'p3p',
                    'pragma',
                    'set-cookie',
                    'x-clacks-overhead',
                    'x-idno-build-fingerprint',
                    'x-powered-by',
                ],
                $this->applicationHeaderNames($r)
            );
            $this->assertSame('text/html;charset=UTF-8', $r->header('Content-Type'));
            $this->assertSame('https://idno.co', $r->header('X-Powered-By'));
        }

        public function testAllMethodsReachLegacy(): void
        {
            $expected = ['HEAD' => 404, 'POST' => 404, 'PUT' => 404, 'DELETE' => 404, 'PATCH' => 404, 'OPTIONS' => 404];
            foreach ($expected as $method => $status) {
                $r = $this->site()->request($method, 'this-resource-does-not-exist');

                $this->assertSame($status, $r->status, $method);
                // Set by Idno\Common\Page::init(): the legacy page ran.
                $this->assertNotNull($r->header('X-Idno-Build-Fingerprint'), $method);
            }
        }

        public function testHeadRequestReachesLegacy(): void
        {
            $get  = $this->site()->request('GET', '');
            $head = $this->site()->request('HEAD', '');

            $this->assertSame(200, $head->status);
            $this->assertSame('', $head->body);
            $this->assertNotNull($head->header('Last-Modified'));
            $this->assertSame($this->applicationHeaderNames($get), $this->applicationHeaderNames($head));
        }

        public function testOptionsReachesLegacy(): void
        {
            $r = $this->site()->request('OPTIONS', '');

            $this->assertSame(204, $r->status);
            $this->assertSame('GET, POST, HEAD, OPTIONS, PUT, DELETE', $r->header('Access-Control-Allow-Methods'));
        }

        public function testXhrRequestReachesLegacy(): void
        {
            // No "url" parameter: the legacy page throws, and its own exception handler
            // renders the 500 page (not a Symfony error page).
            $r = $this->site()->request('GET', 'service/security/csrftoken', ['X-Requested-With' => 'XMLHttpRequest']);

            $this->assertSame(500, $r->status);
            $this->assertSame('text/html;charset=UTF-8', $r->header('Content-Type'));
            $this->assertStringContainsString('RuntimeException: URL missing', $r->body);
        }

        public function testUnusualPathsReachLegacy(): void
        {
            $expected = [
                'this.has.dots/and%20space' => 404,
                'foo/'                      => 404,
                'foo%0Abar'                 => 404,
                '%FF'                       => 404,
                'feed.xml/'                 => 200,
            ];
            foreach ($expected as $path => $status) {
                $r = $this->site()->request('GET', $path);

                $this->assertSame($status, $r->status, $path);
                $this->assertNotNull($r->header('X-Idno-Build-Fingerprint'), $path);
                $this->assertNull($r->header('Location'), $path);
            }
        }
    }

}
