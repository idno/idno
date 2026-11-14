<?php

namespace Tests\Pages\Session {

    use Idno\Pages\Session\Login;

    class LoginRedirectTest extends \Tests\IdnoTestCase
    {

        private function siteUrl(): string
        {
            return \Idno\Core\Idno::site()->config()->getDisplayURL();
        }

        public function testEmptyRedirectFallsBackToSite()
        {
            $this->assertEquals($this->siteUrl(), Login::sanitizeRedirectUrl(''));
            $this->assertEquals($this->siteUrl(), Login::sanitizeRedirectUrl(null));
        }

        public function testRelativePathsAreAllowed()
        {
            $this->assertEquals('/', Login::sanitizeRedirectUrl('/'));
            $this->assertEquals('/account/settings/', Login::sanitizeRedirectUrl('/account/settings/'));
            $this->assertEquals('/content/all/?q=1', Login::sanitizeRedirectUrl('/content/all/?q=1'));
        }

        public function testSameSiteAbsoluteUrlsAreAllowed()
        {
            $url = rtrim($this->siteUrl(), '/') . '/account/settings/';
            $this->assertEquals($url, Login::sanitizeRedirectUrl($url));
        }

        public static function externalUrlProvider(): array
        {
            return [
                'external site'              => ['https://evil.example/'],
                'protocol-relative'          => ['//evil.example/'],
                'backslash protocol-relative' => ['/\\evil.example/'],
                'backslash pair'             => ['\\\\evil.example/'],
                'javascript scheme'          => ['javascript:alert(1)'],
                'data scheme'                => ['data:text/html,<script>alert(1)</script>'],
                'control character'          => ["/\t/evil.example/"],
                'schemeless host'            => ['evil.example/path'],
            ];
        }

        /**
         * @dataProvider externalUrlProvider
         */
        public function testExternalRedirectsAreRejected($url)
        {
            $this->assertEquals($this->siteUrl(), Login::sanitizeRedirectUrl($url), 'Redirect should be rejected: ' . $url);
        }

        public function testLookalikeHostsAreRejected()
        {
            $site = parse_url($this->siteUrl());
            if (empty($site['host'])) {
                $this->markTestSkipped('The site URL has no host to build lookalike URLs from.');
            }
            $scheme = $site['scheme'] ?? 'http';
            $host   = $site['host'];

            // Site URL used as a prefix of a different host
            $this->assertEquals($this->siteUrl(), Login::sanitizeRedirectUrl($scheme . '://' . $host . '.evil.example/'));
            // Site host used as the userinfo part of a different host
            $this->assertEquals($this->siteUrl(), Login::sanitizeRedirectUrl($scheme . '://' . $host . '@evil.example/'));
            // Same host, different port
            $this->assertEquals($this->siteUrl(), Login::sanitizeRedirectUrl($scheme . '://' . $host . ':1/'));
        }
    }

}
