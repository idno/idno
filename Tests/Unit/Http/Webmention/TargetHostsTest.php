<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Webmention;

use Idno\Http\Webmention\TargetHosts;
use PHPUnit\Framework\TestCase;

/**
 * Which addresses are this site's, for a webmention aimed at one: those on the host of the site's address and no other (not its `www.` or bare counterpart, not
 * a former domain), over http or https, on the site's port or its scheme's default.
 */
final class TargetHostsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}> the site's address, a target, whether it is the site's
     */
    public static function rules(): array
    {
        return [
            // the site's own host
            'same address' => ['https://example.com/', 'https://example.com/x', true],
            'the other scheme' => ['https://example.com/', 'http://example.com/x', true],
            'the other scheme of a site over http' => ['http://example.com/', 'https://example.com/x', true],
            'host in another case' => ['https://example.com/', 'https://EXAMPLE.com/x', true],
            'scheme in another case' => ['https://example.com/', 'HTTPS://example.com/x', true],
            'a trailing dot' => ['https://example.com/', 'https://example.com./x', true],
            'two trailing dots' => ['https://example.com/', 'https://example.com../x', false],
            'a site written with a trailing dot' => ['https://example.com./', 'https://example.com/x', true],
            'no path' => ['https://example.com/', 'https://example.com', true],
            // no other host: not the www counterpart, not a subdomain, not a former domain
            'the www counterpart of a bare host' => ['https://example.com/', 'https://www.example.com/x', false],
            'the bare counterpart of a www host' => ['https://www.example.com/', 'https://example.com/x', false],
            'the www host of a www site' => ['https://www.example.com/', 'https://www.example.com/x', true],
            'another subdomain' => ['https://example.com/', 'https://blog.example.com/x', false],
            'www twice' => ['https://example.com/', 'https://www.www.example.com/x', false],
            'www of a subdomain site is not its parent' => ['https://blog.example.com/', 'https://example.com/x', false],
            'another host' => ['https://example.com/', 'https://old.example/x', false],
            // ports on a site at its scheme's default
            'a default port written out' => ['https://example.com/', 'https://example.com:443/x', true],
            'the other schemes default port written out' => ['https://example.com/', 'http://example.com:80/x', true],
            'the wrong scheme default port' => ['https://example.com/', 'https://example.com:80/x', false],
            'another explicit port' => ['https://example.com/', 'https://example.com:8443/x', false],
            // ports on a site at another
            'the sites own port' => ['https://example.com:8443/', 'https://example.com:8443/x', true],
            'the sites own port over the other scheme' => ['https://example.com:8443/', 'http://example.com:8443/x', true],
            'no port on a site at another' => ['https://example.com:8443/', 'https://example.com/x', false],
            'a default port on a site at another' => ['https://example.com:8443/', 'https://example.com:443/x', false],
            'another port than the sites' => ['https://example.com:8443/', 'https://example.com:9443/x', false],
            'a site that writes its default port' => ['https://example.com:443/', 'https://example.com/x', true],
            // near misses
            'a longer host' => ['https://example.com/', 'https://example.com.evil.example/x', false],
            'a host that ends like the site' => ['https://example.com/', 'https://evilexample.com/x', false],
            'userinfo in front of the site' => ['https://example.com/', 'https://user@example.com/x', false],
            'userinfo with a password' => ['https://example.com/', 'https://user:pw@example.com/x', false],
            'the site as userinfo of another host' => ['https://example.com/', 'https://example.com@evil.example/x', false],
            'another host written as the site with a backslash' => ['https://example.com/', 'https://evil.example\\@example.com/x', false],
            // an IPv6 site
            'an IPv6 site' => ['http://[::1]:8080/', 'http://[::1]:8080/x', true],
            'an IPv6 site written out' => ['http://[::1]:8080/', 'http://[0:0:0:0:0:0:0:1]:8080/x', true],
            'an IPv6 site without its port' => ['http://[::1]:8080/', 'http://[::1]/x', false],
            'an IPv6 target of a named site' => ['https://example.com/', 'https://[::1]/x', false],
            // what is no address of a site
            'a bare path' => ['https://example.com/', '/view/abc', false],
            'a relative path' => ['https://example.com/', 'view/abc', false],
            'another scheme' => ['https://example.com/', 'ftp://example.com/x', false],
            'mailto' => ['https://example.com/', 'mailto:a@example.com', false],
            'a scheme-relative address' => ['https://example.com/', '//example.com/x', false],
            'nothing' => ['https://example.com/', '', false],
            'no host' => ['https://example.com/', 'http:///x', false],
            // control characters anywhere
            'a NUL in the path' => ['https://example.com/', "https://example.com/a\0b", false],
            'a tab in the path' => ['https://example.com/', "https://example.com/a\tb", false],
            'a newline in the path' => ['https://example.com/', "https://example.com/a\nb", false],
            'a trailing newline' => ['https://example.com/', "https://example.com/a\n", false],
            'a carriage return in the query' => ['https://example.com/', "https://example.com/a?b=\rc", false],
            'a NUL in the host' => ['https://example.com/', "https://exam\0ple.com/x", false],
            'a space in the path' => ['https://example.com/', 'https://example.com/a b', false],
            'a delete character' => ['https://example.com/', "https://example.com/a\x7fb", false],
            'a leading space' => ['https://example.com/', ' https://example.com/x', false],
            // a site in a subdirectory
            'under the directory' => ['https://example.com/blog/', 'https://example.com/blog/x', true],
            'the directory itself' => ['https://example.com/blog/', 'https://example.com/blog', true],
            'outside the directory' => ['https://example.com/blog/', 'https://example.com/x', false],
            'a directory that only starts alike' => ['https://example.com/blog/', 'https://example.com/blogger/x', false],
            'a dot segment out of the directory' => ['https://example.com/blog/', 'https://example.com/blog/../x', false],
            'a dot segment out of the directory, encoded' => ['https://example.com/blog/', 'https://example.com/blog/%2e%2E/x', false],
            'a dot segment inside the directory' => ['https://example.com/blog/', 'https://example.com/blog/a/../b', false],
            'a dot segment of a site at the root' => ['https://example.com/', 'https://example.com/a/./b', false],
            'a dot that is part of a name' => ['https://example.com/blog/', 'https://example.com/blog/a..b/c.d', true],
            // a site with no host (nothing configured) accepts nothing
            'no site address' => ['', 'https://example.com/x', false],
            'a site address that is only a path' => ['/', 'https://example.com/x', false],
        ];
    }

    /**
     * @dataProvider rules
     */
    public function testWhichAddressesAreTheSites(string $site, string $target, bool $accepted): void
    {
        $this->assertSame($accepted, (new TargetHosts($site))->locate($target) !== null, $target . ' against ' . $site);
    }

    public function testAnAcceptedAddressIsGivenBackAsItWillBeRequested(): void
    {
        $hosts = new TargetHosts('https://example.com/blog/');

        $this->assertSame(['scheme' => 'http', 'host' => 'example.com', 'port' => 80, 'path' => '/blog/a%2Fb', 'query' => 'x=1', 'directory' => '/blog'], $hosts->locate('HTTP://EXAMPLE.com:80/blog/a%2Fb?x=1#frag'));
        $this->assertSame('/', (new TargetHosts('https://example.com/'))->locate('https://example.com')['path']);
        $this->assertSame('', (new TargetHosts('https://example.com/'))->locate('https://example.com')['directory']);
    }

    public function testIdnHostsAreComparedAsPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('The intl extension is not loaded.');
        }
        $unicode = new TargetHosts('https://bücher.example/');
        $punycode = new TargetHosts('https://xn--bcher-kva.example/');

        foreach ([$unicode, $punycode] as $hosts) {
            $this->assertNotNull($hosts->locate('https://xn--bcher-kva.example/x'));
            $this->assertNotNull($hosts->locate('https://bücher.example/x'));
            $this->assertNotNull($hosts->locate('https://Bücher.EXAMPLE/x'));
            $this->assertNull($hosts->locate('https://bucher.example/x'));
        }
        $this->assertNull((new TargetHosts('https://example.com/'))->locate('https://bücher.example/x'));
    }

    public function testAnAddressOnTheSitesHostIsOnItInAnySpelling(): void
    {
        $hosts = new TargetHosts('https://example.com/');

        foreach (['https://example.com/x', 'http://EXAMPLE.com/x', 'http://example.com./x', 'http://example.com:8080/x', 'https://example.com/a/../x', 'https://example.com/x y', '//example.com/x'] as $address) {
            $this->assertTrue($hosts->isOnSiteHost($address), $address);
        }
        foreach (['https://remote.example/x', 'https://www.example.com/x', 'https://example.com.evil.example/x', 'https://sub.example.com/', '', '/relative', 'not an address'] as $address) {
            $this->assertFalse($hosts->isOnSiteHost($address), $address);
        }
        $this->assertFalse((new TargetHosts(''))->isOnSiteHost('https://example.com/x'), 'a site with no address has no host');
    }
}
