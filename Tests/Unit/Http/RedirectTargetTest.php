<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Idno\Http\RedirectTarget;
use PHPUnit\Framework\TestCase;

final class RedirectTargetTest extends TestCase
{
    private const SITE = 'https://site.example/';
    private const SITE_WITH_PORT = 'https://site.example:444/';

    /**
     * @return iterable<string, array{string, string, string}> url, site, expected
     */
    public static function cases(): iterable
    {
        $s = self::SITE;
        $p = self::SITE_WITH_PORT;

        yield 'empty' => ['', $s, $s];
        yield 'spaces only' => ['   ', $s, $s];
        yield 'a path' => ['/x', $s, '/x'];
        yield 'a path with spaces around it is trimmed' => ['  /x ', $s, '/x'];
        yield 'the root' => ['/', $s, '/'];
        yield 'protocol-relative' => ['//evil.example/x', $s, $s];
        yield 'two slashes alone' => ['//', $s, $s];
        yield 'backslashes' => ['\\\\evil.example', $s, $s];
        yield 'a backslash in a path' => ['/x\\y', $s, $s];
        yield 'a tab' => ["/x\ty", $s, $s];
        yield 'a newline in an absolute address' => ["https://site.example/x\n", $s, 'https://site.example/x'];
        yield 'a NUL' => ["/x\0y", $s, $s];
        yield 'the site address' => ['https://site.example/x', $s, 'https://site.example/x'];
        yield 'the host in capitals' => ['HTTPS://SITE.EXAMPLE/x', $s, 'HTTPS://SITE.EXAMPLE/x'];
        yield 'a longer host' => ['https://site.example.evil.test/', $s, $s];
        yield 'user information naming the site, host another' => ['https://site.example@evil.test/', $s, $s];
        yield 'another port, site has none' => ['https://site.example:444/', $s, $s];
        yield 'the default port, site has none' => ['https://site.example:443/', $s, $s];
        yield 'no port, site has one' => ['https://site.example/', $p, $p];
        yield 'the same port' => ['https://site.example:444/x', $p, 'https://site.example:444/x'];
        yield 'another port, site has one' => ['https://site.example:445/', $p, $p];
        yield 'javascript' => ['javascript:alert(1)', $s, $s];
        yield 'ftp' => ['ftp://site.example/', $s, $s];
        yield 'no scheme' => ['site.example/x', $s, $s];
        yield 'a query only' => ['?a=b', $s, $s];
        // A redirect from an https site to http would take a signed-in visitor off the encrypted connection; and an
        // address with user information before the site's own host is a link made to look like the site's.
        yield 'http on an https site' => ['http://site.example/x', $s, $s];
        yield 'user information before the site host' => ['https://user@site.example/x', $s, $s];
        yield 'user name and password before the site host' => ['https://user:secret@site.example/x', $s, $s];
        yield 'an empty user name before the site host' => ['https://@site.example/x', $s, $s];
        yield 'http on an http site is kept' => ['http://site.example/x', 'http://site.example/', 'http://site.example/x'];
        yield 'https on an http site is kept' => ['https://site.example/x', 'http://site.example/', 'https://site.example/x'];
    }

    /**
     * Catches: a site installed under a subdirectory (`https://site.example/blog/`) being taken for another host, so that every redirect to one of its own
     * pages was sent to the site's address; and an address on another host that borrows the subdirectory's path being kept.
     */
    public function testASiteInASubdirectoryKeepsItsOwnAddressesAndRefusesAnotherHostWithTheSamePath(): void
    {
        $site = 'https://site.example/blog/';

        $this->assertSame('https://site.example/blog/2026/a-note', RedirectTarget::sanitize('https://site.example/blog/2026/a-note', $site));
        $this->assertSame('/blog/2026/a-note', RedirectTarget::sanitize('/blog/2026/a-note', $site));
        $this->assertSame($site, RedirectTarget::sanitize('https://evil.test/blog/2026/a-note', $site));
        $this->assertSame($site, RedirectTarget::sanitize('https://site.example.evil.test/blog/', $site));
    }

    /**
     * @dataProvider cases
     */
    public function testSanitize(string $url, string $site, string $expected): void
    {
        $this->assertSame($expected, RedirectTarget::sanitize($url, $site));
    }
}
