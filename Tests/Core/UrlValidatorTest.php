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

        // The SSRF rule: loopback, private, link-local, shared and IPv4-mapped addresses are not public ones.
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
                ["  http://example.com/ \n", 'http://example.com/'],
                // Hosts that begin with "s" were once refused (see hostFirstCharacterProvider())
                ['https://substack.com/@someone', 'https://substack.com/@someone'],
                ['https://stackoverflow.com/questions/1', 'https://stackoverflow.com/questions/1'],
                ['https://social.example/@user', 'https://social.example/@user'],
                ['http://s.example/', 'http://s.example/'],
                ['HTTPS://Social.Example/@User', 'HTTPS://Social.Example/@User'],
                ['//static.example.com/a.png', '//static.example.com/a.png'],
                'the letter s alone' => ['https://s', 'https://s'],
                'user name'          => ['https://sam@example.com/', 'https://sam@example.com/'],
                'user and password'  => ['https://sam:secret@example.com/', 'https://sam:secret@example.com/'],
                'port'               => ['http://example.com:8080/a', 'http://example.com:8080/a'],
                'IPv4'               => ['http://203.0.113.7/a', 'http://203.0.113.7/a'],
                'IPv6'               => ['http://[2001:db8::1]/a', 'http://[2001:db8::1]/a'],
                'IPv6 and port'      => ['https://[2001:db8::5]:8443/', 'https://[2001:db8::5]:8443/'],
                'IDN host'           => ['https://straße.example/', 'https://straße.example/'],
                'IDN host, Cyrillic' => ['https://сайт.example/', 'https://сайт.example/'],
                'punycode host'      => ['https://xn--strae-oqa.example/', 'https://xn--strae-oqa.example/'],
                'backslash later'    => ['https://example.com/a\\b', 'https://example.com/a\\b'],
                's in the path'      => ['https://example.com/s/s?s=s#s', 'https://example.com/s/s?s=s#s'],
            ];
        }

        /**
         * A host may begin with any letter or digit. The pattern in httpUrl() once
         * refused the letter "s": the white space class reached the regular expression
         * engine as an escaped backslash followed by the letter s.
         */
        public static function hostFirstCharacterProvider(): array
        {
            $cases = [];
            foreach (array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9')) as $character) {
                $character = (string) $character;
                foreach (['http://', 'https://', 'HTTPS://', '//'] as $start) {
                    $cases[$start . $character] = [$start . $character . 'ite.example/' . $character . '?' . $character . '=1'];
                }
            }

            return $cases;
        }

        /**
         * @dataProvider hostFirstCharacterProvider
         */
        public function testHttpUrlAcceptsAHostThatBeginsWithAnyLetterOrDigit($url)
        {
            $this->assertSame($url, UrlValidator::httpUrl($url));
        }

        /**
         * Every character of printable ASCII, as the first character of the host and
         * later in the address: only the ones a URL cannot contain are refused, and at
         * the start of the host a slash or a backslash, which leave no host.
         */
        public static function asciiCharacterProvider(): array
        {
            $cases = [];
            for ($code = 0x21; $code <= 0x7e; $code++) {
                $character = chr($code);
                $never = in_array($character, ['"', '<', '>', '`'], true);
                $cases['first, 0x' . dechex($code)] = [
                    'https://' . $character . 'ite.example/',
                    !$never && $character !== '/' && $character !== '\\',
                ];
                $cases['later, 0x' . dechex($code)] = ['https://example.com/a' . $character . 'b', !$never];
            }

            return $cases;
        }

        /**
         * @dataProvider asciiCharacterProvider
         */
        public function testHttpUrlRefusesOnlyTheCharactersItNames($url, $accepted)
        {
            $this->assertSame($accepted ? $url : '', UrlValidator::httpUrl($url));
        }

        /**
         * White space and control characters are refused anywhere inside the address.
         */
        public static function controlCharacterProvider(): array
        {
            $cases = [];
            foreach (array_merge(range(0x00, 0x20), [0x7f]) as $code) {
                $cases['first, 0x' . dechex($code)] = ['https://' . chr($code) . 'ite.example/'];
                $cases['later, 0x' . dechex($code)] = ['https://example.com/a' . chr($code) . 'b'];
            }

            return $cases;
        }

        /**
         * @dataProvider controlCharacterProvider
         */
        public function testHttpUrlRefusesWhiteSpaceAndControlCharacters($url)
        {
            $this->assertSame('', UrlValidator::httpUrl($url));
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
                'backslash first'   => ['http://\\example.com/'],
                'backslash, no scheme' => ['//\\example.com/'],
                'three slashes'     => ['///example.com/'],
                'slash, backslash'  => ['/\\example.com/'],
                'space first'       => ['http:// example.com/'],
                'tab first'         => ["http://\texample.com/"],
                'quote first'       => ['http://"example.com/'],
                'bracket first'     => ['http://<example.com/'],
                'backtick first'    => ['http://`example.com/'],
                'no host, no scheme' => ['//'],
                'javascript, slashes' => ['javascript://example.com/%0aalert(1)'],
                'javascript, s host' => ['javascript://s.example/%0aalert(1)'],
                'data, s host'      => ['data://s.example/'],
                'https twice'       => ['httpss://s.example/'],
                'line after'        => ["javascript:alert(1)\nhttps://s.example/"],
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
