<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Webmention;

use Idno\Http\Webmention\TargetHosts;
use PHPUnit\Framework\TestCase;

/**
 * Which addresses are this site's, for a webmention aimed at one: the host of the site's address, its `www.` or bare counterpart, and the hosts the boot
 * configuration lists (`webmention_target_hosts`), over http or https, on the site's port or its scheme's default.
 */
final class TargetHostsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: mixed, 2: string, 3: bool}> the site's address, the listed hosts, a target, whether it is the site's
     */
    public static function rules(): array
    {
        return [
            // the site's own host
            'same address' => ['https://example.com/', null, 'https://example.com/x', true],
            'the other scheme' => ['https://example.com/', null, 'http://example.com/x', true],
            'the other scheme of a site over http' => ['http://example.com/', null, 'https://example.com/x', true],
            'host in another case' => ['https://example.com/', null, 'https://EXAMPLE.com/x', true],
            'scheme in another case' => ['https://example.com/', null, 'HTTPS://example.com/x', true],
            'a trailing dot' => ['https://example.com/', null, 'https://example.com./x', true],
            'no path' => ['https://example.com/', null, 'https://example.com', true],
            // the www counterpart
            'the www counterpart of a bare host' => ['https://example.com/', null, 'https://www.example.com/x', true],
            'the bare counterpart of a www host' => ['https://www.example.com/', null, 'https://example.com/x', true],
            'another subdomain' => ['https://example.com/', null, 'https://blog.example.com/x', false],
            'www twice' => ['https://example.com/', null, 'https://www.www.example.com/x', false],
            'www of a subdomain site is not its parent' => ['https://blog.example.com/', null, 'https://example.com/x', false],
            // ports on a site at its scheme's default
            'a default port written out' => ['https://example.com/', null, 'https://example.com:443/x', true],
            'the other schemes default port written out' => ['https://example.com/', null, 'http://example.com:80/x', true],
            'the wrong scheme default port' => ['https://example.com/', null, 'https://example.com:80/x', false],
            'another explicit port' => ['https://example.com/', null, 'https://example.com:8443/x', false],
            // ports on a site at another
            'the sites own port' => ['https://example.com:8443/', null, 'https://example.com:8443/x', true],
            'the sites own port over the other scheme' => ['https://example.com:8443/', null, 'http://example.com:8443/x', true],
            'no port on a site at another' => ['https://example.com:8443/', null, 'https://example.com/x', false],
            'a default port on a site at another' => ['https://example.com:8443/', null, 'https://example.com:443/x', false],
            'another port than the sites' => ['https://example.com:8443/', null, 'https://example.com:9443/x', false],
            'a site that writes its default port' => ['https://example.com:443/', null, 'https://example.com/x', true],
            // near misses
            'a longer host' => ['https://example.com/', null, 'https://example.com.evil.example/x', false],
            'a host that ends like the site' => ['https://example.com/', null, 'https://evilexample.com/x', false],
            'userinfo in front of the site' => ['https://example.com/', null, 'https://user@example.com/x', false],
            'userinfo with a password' => ['https://example.com/', null, 'https://user:pw@example.com/x', false],
            'the site as userinfo of another host' => ['https://example.com/', null, 'https://example.com@evil.example/x', false],
            'another host' => ['https://example.com/', null, 'https://evil.example/x', false],
            // what is no address of a site
            'a bare path' => ['https://example.com/', null, '/view/abc', false],
            'a relative path' => ['https://example.com/', null, 'view/abc', false],
            'another scheme' => ['https://example.com/', null, 'ftp://example.com/x', false],
            'mailto' => ['https://example.com/', null, 'mailto:a@example.com', false],
            'a scheme-relative address' => ['https://example.com/', null, '//example.com/x', false],
            'nothing' => ['https://example.com/', null, '', false],
            'no host' => ['https://example.com/', null, 'http:///x', false],
            // a site in a subdirectory
            'under the directory' => ['https://example.com/blog/', null, 'https://example.com/blog/x', true],
            'the directory itself' => ['https://example.com/blog/', null, 'https://example.com/blog', true],
            'outside the directory' => ['https://example.com/blog/', null, 'https://example.com/x', false],
            'a directory that only starts alike' => ['https://example.com/blog/', null, 'https://example.com/blogger/x', false],
            // listed hosts
            'a listed host' => ['https://example.com/', ['old.example'], 'https://old.example/x', true],
            'a listed host over the other scheme' => ['https://example.com/', ['old.example'], 'http://old.example/x', true],
            'a listed host in another case, with a trailing dot' => ['https://example.com/', ['OLD.example.'], 'https://old.EXAMPLE/x', true],
            'a host that is not listed' => ['https://example.com/', ['old.example'], 'https://other.example/x', false],
            'a listed host has no www counterpart' => ['https://example.com/', ['old.example'], 'https://www.old.example/x', false],
            'a listed host with userinfo' => ['https://example.com/', ['old.example'], 'https://u@old.example/x', false],
            'a listed host on a default port written out' => ['https://example.com/', ['old.example'], 'https://old.example:443/x', true],
            'a listed host on another port' => ['https://example.com/', ['old.example'], 'https://old.example:8443/x', false],
            'a listed host written with its port' => ['https://example.com/', ['old.example:8443'], 'https://old.example:8443/x', true],
            'a listed host written with a port, without it' => ['https://example.com/', ['old.example:8443'], 'https://old.example/x', false],
            'a listed host written with a port, on another' => ['https://example.com/', ['old.example:8443'], 'https://old.example:9443/x', false],
            'the sites port does not apply to a listed host' => ['https://example.com:8443/', ['old.example'], 'https://old.example:8443/x', false],
            'a listed host on the default port of a site at another' => ['https://example.com:8443/', ['old.example'], 'https://old.example/x', true],
            'a list in a string' => ['https://example.com/', 'old.example, other.example', 'https://other.example/x', true],
            'an empty string lists nothing' => ['https://example.com/', '', 'https://old.example/x', false],
            'a listed IPv4 address' => ['https://example.com/', ['192.0.2.7'], 'http://192.0.2.7/x', true],
            'a listed IPv6 address' => ['https://example.com/', ['[2001:db8::7]'], 'http://[2001:db8::7]/x', true],
            'a listed host serves under the sites directory' => ['https://example.com/blog/', ['old.example'], 'https://old.example/blog/x', true],
            'a listed host outside the sites directory' => ['https://example.com/blog/', ['old.example'], 'https://old.example/x', false],
            // a site with no host (nothing configured) still has the listed ones
            'no site address, a listed host' => ['', ['old.example'], 'https://old.example/x', true],
            'no site address, no list' => ['', null, 'https://example.com/x', false],
            'a site address that is only a path' => ['/', null, 'https://example.com/x', false],
        ];
    }

    /**
     * @dataProvider rules
     */
    public function testWhichAddressesAreTheSites(string $site, mixed $listed, string $target, bool $accepted): void
    {
        $hosts = new TargetHosts($site, TargetHosts::entries($listed));

        $this->assertSame($accepted, $hosts->locate($target) !== null, $target . ' against ' . $site . ' listing ' . json_encode($listed));
    }

    public function testAnAcceptedAddressIsGivenBackAsItWillBeRequested(): void
    {
        $hosts = new TargetHosts('https://example.com/blog/', []);

        $this->assertSame(['scheme' => 'http', 'host' => 'www.example.com', 'port' => 80, 'path' => '/blog/a%2Fb', 'query' => 'x=1', 'directory' => '/blog'], $hosts->locate('HTTP://WWW.example.com:80/blog/a%2Fb?x=1#frag'));
        $this->assertSame('/', (new TargetHosts('https://example.com/', []))->locate('https://example.com')['path']);
        $this->assertSame('', (new TargetHosts('https://example.com/', []))->locate('https://example.com')['directory']);
    }

    public function testIdnHostsAreComparedAsPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            $this->markTestSkipped('The intl extension is not loaded.');
        }
        $unicode = new TargetHosts('https://bücher.example/', []);
        $punycode = new TargetHosts('https://xn--bcher-kva.example/', []);

        foreach ([$unicode, $punycode] as $hosts) {
            $this->assertNotNull($hosts->locate('https://xn--bcher-kva.example/x'));
            $this->assertNotNull($hosts->locate('https://bücher.example/x'));
            $this->assertNotNull($hosts->locate('https://Bücher.EXAMPLE/x'));
            $this->assertNull($hosts->locate('https://bucher.example/x'));
        }
        $this->assertNotNull((new TargetHosts('https://example.com/', TargetHosts::entries(['bücher.example'])))->locate('https://xn--bcher-kva.example/x'));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}> a value of the setting, the entry the exception names
     */
    public static function invalidEntries(): array
    {
        return [
            'a url' => [['https://old.example/'], 'https://old.example/'],
            'a path' => [['old.example/blog'], 'old.example/blog'],
            'a space' => [['old example'], 'old example'],
            'a wildcard' => [['*.example'], '*.example'],
            'a lone star' => [['*'], '*'],
            'userinfo' => [['u@old.example'], 'u@old.example'],
            'a port that is no number' => [['old.example:abc'], 'old.example:abc'],
            'a port of zero' => [['old.example:0'], 'old.example:0'],
            'a port too large' => [['old.example:70000'], 'old.example:70000'],
            'brackets round a host name' => [['[old.example]'], '[old.example]'],
            'an empty label' => [['old..example'], 'old..example'],
            'a leading hyphen' => [['-old.example'], '-old.example'],
            'a string with a bad entry' => ['old.example, bad host', 'bad host'],
            'an entry that is not text' => [[['old.example']], 'array'],
        ];
    }

    /**
     * @dataProvider invalidEntries
     */
    public function testAnEntryThatIsNoHostIsRefusedNamingTheSettingAndTheEntry(mixed $value, string $entry): void
    {
        try {
            TargetHosts::entries($value);
            $this->fail('an entry that is no host was accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('webmention_target_hosts', $e->getMessage());
            $this->assertStringContainsString($entry, $e->getMessage());
        }
    }

    public function testASettingThatIsNeitherAListNorTextIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('webmention_target_hosts');

        TargetHosts::entries(42);
    }

    public function testEntriesAreNormalised(): void
    {
        $this->assertSame([['host' => 'old.example', 'port' => null], ['host' => 'other.example', 'port' => 8443], ['host' => '[2001:db8::7]', 'port' => null]], TargetHosts::entries(['OLD.example.', ' other.EXAMPLE:8443 ', '2001:db8::7']));
        $this->assertSame([], TargetHosts::entries(null));
        $this->assertSame([], TargetHosts::entries([]));
        $this->assertSame([['host' => 'a.example', 'port' => null], ['host' => 'b.example', 'port' => null]], TargetHosts::entries('a.example,, b.example'));
    }
}
