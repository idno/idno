<?php

namespace Tests\Core {

    use Idno\Core\UrlValidator;

    class UrlValidatorTest extends \Tests\IdnoTestCase
    {

        public static function unsafeUrlProvider(): array
        {
            return [
                'empty string'          => [''],
                'not a string'          => [null],
                'not a URL'             => ['not a url'],
                'file scheme'           => ['file:///etc/passwd'],
                'gopher scheme'         => ['gopher://127.0.0.1:6379/_INFO'],
                'ftp scheme'            => ['ftp://example.com/file'],
                'loopback IPv4'         => ['http://127.0.0.1/'],
                'loopback with port'    => ['http://127.0.0.1:8080/admin'],
                'localhost'             => ['http://localhost/'],
                'private 10/8'          => ['http://10.0.0.1/'],
                'private 172.16/12'     => ['http://172.16.0.1/'],
                'private 192.168/16'    => ['http://192.168.1.1/'],
                'link-local metadata'   => ['http://169.254.169.254/latest/meta-data/'],
                'unspecified'           => ['http://0.0.0.0/'],
                'carrier-grade NAT'     => ['http://100.64.0.1/'],
                'loopback IPv6'         => ['http://[::1]/'],
                'IPv4-mapped IPv6'      => ['http://[::ffff:127.0.0.1]/'],
                'unique local IPv6'     => ['http://[fd00::1]/'],
                'link-local IPv6'       => ['http://[fe80::1]/'],
                'embedded credentials'  => ['http://user:pass@93.184.216.34/'],
                'unresolvable host'     => ['http://this-host-does-not-exist.invalid/'],
            ];
        }

        /**
         * @dataProvider unsafeUrlProvider
         */
        public function testUnsafeUrlsAreRejected($url)
        {
            $this->assertFalse(UrlValidator::isSafeUrl($url), 'URL should be rejected: ' . json_encode($url));
        }

        public function testPublicIpLiteralsAreAccepted()
        {
            // IP literals, so that the test doesn't depend on DNS
            $this->assertTrue(UrlValidator::isSafeUrl('http://93.184.216.34/'));
            $this->assertTrue(UrlValidator::isSafeUrl('https://93.184.216.34:8443/path?query=1'));
            $this->assertTrue(UrlValidator::isSafeUrl('https://[2606:2800:220:1:248:1893:25c8:1946]/'));
        }

        public function testIsPublicIp()
        {
            $this->assertTrue(UrlValidator::isPublicIp('93.184.216.34'));
            $this->assertTrue(UrlValidator::isPublicIp('2606:2800:220:1:248:1893:25c8:1946'));

            $this->assertFalse(UrlValidator::isPublicIp('127.0.0.1'));
            $this->assertFalse(UrlValidator::isPublicIp('10.1.2.3'));
            $this->assertFalse(UrlValidator::isPublicIp('192.168.0.10'));
            $this->assertFalse(UrlValidator::isPublicIp('169.254.169.254'));
            $this->assertFalse(UrlValidator::isPublicIp('100.127.255.254'));
            $this->assertFalse(UrlValidator::isPublicIp('::1'));
            $this->assertFalse(UrlValidator::isPublicIp('::ffff:10.0.0.1'));
            $this->assertFalse(UrlValidator::isPublicIp('not an ip'));
        }
    }

}
