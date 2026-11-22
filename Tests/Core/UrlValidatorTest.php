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

        public function testSafeAddressesAreTheAddressesOfTheHost()
        {
            $this->assertSame(['93.184.216.34'], UrlValidator::safeAddresses('https://93.184.216.34:8443/path'));
            $this->assertSame(['2606:2800:220:1:248:1893:25c8:1946'], UrlValidator::safeAddresses('https://[2606:2800:220:1:248:1893:25c8:1946]/'));
        }

        /**
         * @dataProvider unsafeUrlProvider
         */
        public function testUnsafeUrlsHaveNoSafeAddresses($url)
        {
            $this->assertSame([], UrlValidator::safeAddresses($url));
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

        public static function printableUrlProvider(): array
        {
            return [
                ['http://example.com/', 'http://example.com/'],
                ['https://example.com/a?b=1&c=2#d', 'https://example.com/a?b=1&c=2#d'],
                ['HTTPS://Example.com/O\'Brien.jpg', 'HTTPS://Example.com/O\'Brien.jpg'],
                ['//cdn.example.com/a.png', '//cdn.example.com/a.png'],
                // a host or a first letter of any kind: none is refused for its spelling
                ['https://shoreline.example/ana', 'https://shoreline.example/ana'],
                ['http://s.example/', 'http://s.example/'],
                ['https://xn--caf-dma.example/', 'https://xn--caf-dma.example/'],
                ["  http://example.com/ \n", 'http://example.com/'],
            ];
        }

        /**
         * @dataProvider printableUrlProvider
         */
        public function testHttpUrlKeepsHttpAndHttpsAddresses($url, $expected)
        {
            $this->assertSame($expected, UrlValidator::httpUrl($url));
        }

        public static function unprintableUrlProvider(): array
        {
            return [
                'javascript'        => ['javascript:alert(1)'],
                'mixed case'        => ['JaVaScRiPt:alert(1)'],
                'data'              => ['data:text/html,<script>alert(1)</script>'],
                'vbscript'          => ['vbscript:msgbox(1)'],
                'file'              => ['file:///etc/passwd'],
                'ftp'               => ['ftp://example.com/'],
                'relative'          => ['/a/b'],
                'no host'           => ['http:///a'],
                'scheme only'       => ['https://'],
                'one slash'         => ['http:/example.com'],
                'backslashes'       => ['http:\\\\example.com'],
                'double quote'      => ['http://example.com/"onmouseover="alert(1)'],
                'angle brackets'    => ['http://example.com/<script>'],
                'backtick'          => ['http://example.com/`a`'],
                'space'             => ['http://example.com/a b'],
                'line break'        => ["http://example.com/a\nb"],
                'tab in the scheme' => ["java\tscript:alert(1)"],
                'leading control'   => ["\x01javascript:alert(1)"],
                'null byte'         => ["http://example.com/\0a"],
                'text before'       => ['x http://example.com/'],
                'empty'             => [''],
                'null'              => [null],
                'array'             => [['http://example.com/']],
                'number'            => [0],
            ];
        }

        /**
         * @dataProvider unprintableUrlProvider
         */
        public function testHttpUrlRefusesEverythingElse($url)
        {
            $this->assertSame('', UrlValidator::httpUrl($url));
        }
    }

}
