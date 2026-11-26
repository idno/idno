<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Common\Page;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Http\Controller\Home\HomePages;
use Idno\Http\Webmention\WebmentionTarget;
use Idno\Http\Webmention\WebmentionTargets;
use Idno\Kernel;
use Idno\Legacy\LegacyDispatcher;
use Idno\Legacy\LegacyWebmentionTargets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Tests\Fixtures\WebmentionReceiverProbe;

/**
 * The page an address of this site is, for a webmention aimed at it (`LegacyWebmentionTargets`): found as a GET of the address is routed, without building a page
 * class. On the rig's site, in this process; a site configured another way (a plugin's page that takes mentions, a subdirectory) is booted in a process of its own.
 */
final class WebmentionTargetsTest extends TestCase
{
    private function targets(): WebmentionTargets
    {
        return Idno::container()->get(WebmentionTargets::class);
    }

    private function base(): string
    {
        return Idno::site()->config()->getURL();
    }

    /** The rig's own address with another scheme: 127.0.0.1:8089 as https, localhost as http, as a site spells itself. */
    private function otherScheme(): string
    {
        $base = $this->base();

        return str_starts_with($base, 'https://') ? 'http://' . substr($base, 8) : 'https://' . substr($base, 7);
    }

    /** The permalink address of a post as the site's structure makes it. */
    private function permalink(): string
    {
        $path = strtr(Idno::site()->config()->getPermalinkStructure(), [':year' => '2026', ':month' => '11', ':day' => '25', ':slug' => 'a-note', ':id' => 'abc123']);

        return $this->base() . ltrim($path, '/');
    }

    /**
     * @return array{page: string, route: string, arguments: list<string>, legacy: bool}|null
     */
    private function answer(?WebmentionTarget $target): ?array
    {
        return $target === null ? null : ['page' => $target->page, 'route' => $target->route, 'arguments' => $target->arguments, 'legacy' => $target->isLegacy()];
    }

    // ------------------------------------------------------------------ the pages a controller serves

    public function testTheHomePageIsATarget(): void
    {
        foreach ([$this->base(), rtrim($this->base(), '/')] as $address) {
            $this->assertSame(['page' => 'Idno\Pages\Homepage', 'route' => 'idno_home', 'arguments' => [], 'legacy' => false], $this->answer($this->targets()->resolve($address)), $address);
        }
    }

    public function testAContentStreamIsATarget(): void
    {
        $this->assertSame(['page' => 'Idno\Pages\Homepage', 'route' => 'idno_content', 'arguments' => ['note/article'], 'legacy' => false], $this->answer($this->targets()->resolve($this->base() . 'content/note/article')));
    }

    public function testTheFeedAndTagAddressesResolveToARouteTheirReceiverRefuses(): void
    {
        $pages = Idno::container()->get(HomePages::class);
        foreach (['feed.xml' => 'idno_feed_xml', 'feed' => 'idno_feed', 'rss.xml' => 'idno_rss_xml', 'search' => 'idno_search', 'tag/books' => 'idno_tag'] as $path => $route) {
            $target = $this->targets()->resolve($this->base() . $path);

            $this->assertNotNull($target, $path);
            $this->assertFalse($target->isLegacy(), $path);
            $this->assertSame(['Idno\Pages\Homepage', $route], [$target->page, $target->route], $path);
            $this->assertFalse($pages->receivesFor($target->route), $path);
        }
    }

    public function testTheHomePageServiceIsTheReceiverOfTheHomePage(): void
    {
        $probe = Idno::container()->get(WebmentionReceiverProbe::class);

        $this->assertContains('Idno\Pages\Homepage', $probe->pages());
        $this->assertInstanceOf(HomePages::class, $probe->receiverOf('Idno\Pages\Homepage'));
        $this->assertNull($probe->receiverOf('Idno\Pages\NoSuchPage'));
    }

    // ------------------------------------------------------------------ the pages that are still classes

    public function testAPermalinkIsTheShellsLegacyTargetUntilItMoves(): void
    {
        $target = $this->targets()->resolve($this->permalink());

        $this->assertNotNull($target);
        $this->assertTrue($target->isLegacy());
        $this->assertSame('Idno\Pages\Entity\View', $target->page);
        $this->assertSame('', $target->route);
        $this->assertSame(['a-note'], array_slice($target->arguments, -1));
    }

    public function testThePermalinksMarkdownFormIsThePermalink(): void
    {
        $target = $this->targets()->resolve($this->permalink() . '.md');

        $this->assertNotNull($target);
        $this->assertSame('Idno\Pages\Entity\View', $target->page);
        $this->assertSame(['a-note'], array_slice($target->arguments, -1));
    }

    public function testAStaticPageIsALegacyTarget(): void
    {
        $this->assertSame(['page' => 'IdnoPlugins\StaticPages\Pages\View', 'route' => '', 'arguments' => ['about'], 'legacy' => true], $this->answer($this->targets()->resolve($this->base() . 'pages/about')));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function addresses(): array
    {
        return [
            'the home page' => [''],
            'a content stream' => ['content/note'],
            'the feed' => ['feed.xml'],
            'a tag' => ['tag/books'],
            'a search' => ['search'],
            'a short link' => ['s/abc123'],
            'a post by its id' => ['view/abc123'],
            'a profile' => ['profile/ada'],
            'a static page' => ['pages/about'],
            'a page of a controller that is no site page' => ['_test/ping'],
            'the edit page' => ['edit/abc123'],
            'an address no page serves' => ['no/such/page/here/at/all/x.y'],
            'robots.txt' => ['robots.txt'],
            'the webmention endpoint' => ['webmention/'],
        ];
    }

    /**
     * What the legacy lookup the endpoint used (`PageHandler::getRoute()`) built for an address is what the resolver names, with the same arguments, for every address
     * a class served; and where the legacy lookup found no page (a page a controller serves, or nothing) the resolver names no legacy page.
     *
     * @dataProvider addresses
     */
    public function testEveryAddressIsTheLegacyLookupsPageOrNoLegacyPage(string $path): void
    {
        $address = $this->base() . $path;
        $current = Idno::site()->currentPage();
        try {
            $legacy = Idno::site()->routes()->getRoute($address);
        } finally {
            if ($current instanceof Page) {
                Idno::site()->setCurrentPage($current);
            }
        }
        $target = $this->targets()->resolve($address);

        if ($legacy instanceof Page) {
            $this->assertNotNull($target, $path);
            // (a shell a controller serves is the legacy lookup's page and the controller's page by name)
            $this->assertTrue($target->isLegacy() || $target->page === get_class($legacy), $path);
            $this->assertSame(get_class($legacy), $target->page, $path);
            if ($target->isLegacy()) {
                $this->assertSame($legacy->arguments, $target->arguments, $path);
            }
        } else {
            $this->assertTrue($target === null || !$target->isLegacy(), $path);
        }
    }

    // ------------------------------------------------------------------ whose address it is

    public function testATargetOnAnotherHostIsNotFound(): void
    {
        $this->assertNull($this->targets()->resolve('https://other.example/'));
        $this->assertNull($this->targets()->resolve('https://other.example/view/abc123'));
    }

    public function testTheSameHostOverTheOtherSchemeIsATarget(): void
    {
        $target = $this->targets()->resolve($this->otherScheme());

        $this->assertNotNull($target);
        $this->assertSame('idno_home', $target->route);
    }

    public function testTheHostIsComparedIgnoringCase(): void
    {
        $this->assertNotNull($this->targets()->resolve(strtoupper($this->base())));
    }

    public function testTheSameHostOnAnotherExplicitPortIsNotFound(): void
    {
        $parts = parse_url($this->base());
        $other = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . ':9/';

        $this->assertNull($this->targets()->resolve($other));
    }

    public function testAnAddressNothingServesIsNotFound(): void
    {
        $this->assertNull($this->targets()->resolve($this->base() . 'no/such/page/here/at/all/x.y'));
    }

    public function testAnAddressWithNoHostOrAnotherSchemeIsNotFound(): void
    {
        foreach (['/view/abc123', 'view/abc123', 'ftp://' . parse_url($this->base(), PHP_URL_HOST) . '/', 'mailto:a@example.com', '', 'http:///'] as $address) {
            $this->assertNull($this->targets()->resolve($address), $address);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}> the site's address, a target, whether it is the site's
     */
    public static function hostRules(): array
    {
        return [
            'same address' => ['https://example.com/', 'https://example.com/feed.xml', true],
            'the other scheme' => ['https://example.com/', 'http://example.com/feed.xml', true],
            'the other scheme of a site over http' => ['http://example.com/', 'https://example.com/feed.xml', true],
            'host in another case' => ['https://example.com/', 'https://EXAMPLE.com/feed.xml', true],
            'a default port written out' => ['https://example.com/', 'https://example.com:443/feed.xml', true],
            'the other scheme default port written out' => ['https://example.com/', 'http://example.com:80/feed.xml', true],
            'the sites own port' => ['https://example.com:8443/', 'https://example.com:8443/feed.xml', true],
            'the sites own port over the other scheme' => ['https://example.com:8443/', 'http://example.com:8443/feed.xml', true],
            'another explicit port' => ['https://example.com/', 'https://example.com:8443/feed.xml', false],
            'another explicit port than the sites' => ['https://example.com:8443/', 'https://example.com:9443/feed.xml', false],
            'the default port of a site on another' => ['https://example.com:8443/', 'https://example.com:443/feed.xml', true],
            'a subdomain' => ['https://example.com/', 'https://www.example.com/feed.xml', false],
            'a longer host' => ['https://example.com/', 'https://example.com.evil.example/x', false],
            'a user name in front of another host' => ['https://example.com/', 'https://example.com@evil.example/x', false],
            'another host' => ['https://example.com/', 'https://evil.example/x', false],
        ];
    }

    /**
     * @dataProvider hostRules
     */
    public function testTheHostRule(string $site, string $target, bool $accepted): void
    {
        $targets = $this->resolverFor($site);

        $this->assertSame($accepted, $targets->resolve($target) !== null, $target . ' against ' . $site);
    }

    // ------------------------------------------------------------------ no page is built, and the router is left as it was

    public function testResolvingBuildsNoPageClass(): void
    {
        $events = [];
        $listener = static function () use (&$events): void {
            $events[] = 'page/ready';
        };
        $current = Idno::site()->currentPage();
        Idno::site()->events()->addListener('page/ready', $listener);
        try {
            foreach (['', 'feed.xml', 'view/abc123', 'pages/about'] as $path) {
                $this->assertNotNull($this->targets()->resolve($this->base() . $path), $path);
            }
        } finally {
            Idno::site()->events()->removeListener('page/ready', $listener);
        }

        $this->assertSame([], $events);
        $this->assertSame($current, Idno::site()->currentPage());
    }

    public function testTheRoutersContextIsRestored(): void
    {
        $router = Idno::container()->get('router');
        $before = $this->contextOf($router->getContext());

        $this->assertNotNull($this->targets()->resolve($this->base() . 'content/note'));

        $this->assertSame($before, $this->contextOf($router->getContext()));
    }

    public function testTheRoutersContextIsRestoredWhenMatchingThrows(): void
    {
        $real = Idno::container()->get('router');
        $before = $this->contextOf($real->getContext());
        $throwing = new class($real) implements RouterInterface {
            public function __construct(private readonly RouterInterface $router)
            {
            }

            public function setContext(RequestContext $context): void
            {
                $this->router->setContext($context);
            }

            public function getContext(): RequestContext
            {
                return $this->router->getContext();
            }

            public function getRouteCollection(): \Symfony\Component\Routing\RouteCollection
            {
                return $this->router->getRouteCollection();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return $this->router->generate($name, $parameters, $referenceType);
            }

            public function match(string $pathinfo): array
            {
                throw new \RuntimeException('the router broke');
            }
        };

        try {
            $this->resolverFor($this->base(), $throwing)->resolve($this->base() . 'content/note');
            $this->fail('the router was asked and its failure was not passed on');
        } catch (\RuntimeException $e) {
            $this->assertSame('the router broke', $e->getMessage());
        }

        $this->assertSame($before, $this->contextOf($real->getContext()));
    }

    /**
     * @return array<string, mixed>
     */
    private function contextOf(RequestContext $context): array
    {
        return [$context->getBaseUrl(), $context->getPathInfo(), $context->getMethod(), $context->getHost(), $context->getScheme(), $context->getHttpPort(), $context->getHttpsPort(), $context->getQueryString(), $context->getParameters()];
    }

    private function resolverFor(string $siteUrl, ?RouterInterface $router = null): LegacyWebmentionTargets
    {
        $dispatcher = Idno::container()->get(LegacyDispatcher::class);

        return new LegacyWebmentionTargets($router ?? Idno::container()->get('router'), static fn (): LegacyDispatcher => $dispatcher, Idno::container()->get('kernel'), new BootConfig(['url' => $siteUrl], false));
    }

    // ------------------------------------------------------------------ a site configured another way, in a process of its own

    /**
     * @param list<string> $targets
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function resolveInAProcess(string $mode, string $url, array $targets, string $record = ''): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr -d log_errors=0 ' . escapeshellarg(__DIR__ . '/webmention_targets.php')
            . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg(json_encode($targets, JSON_THROW_ON_ERROR)) . ($record !== '' ? ' ' . escapeshellarg($record) : '');
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), ['IDNO_DOMAIN' => (string) getenv('IDNO_DOMAIN'), 'IDNO_PORT' => (string) getenv('IDNO_PORT'), 'IDNO_PROXY_STRING' => '127.0.0.1:9', 'PATH' => (string) getenv('PATH')]);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $position = strrpos($stdout, "\n#RESULT#");
        $this->assertNotFalse($position, "the script printed no result.\nstdout: $stdout\nstderr: $stderr");

        return json_decode(substr($stdout, $position + 9), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAPluginsPageIsALegacyTarget(): void
    {
        $record = sys_get_temp_dir() . '/idno-mention-record-' . bin2hex(random_bytes(6));
        try {
            $address = $this->base() . '_test/mentionable/xyz';
            $answers = $this->resolveInAProcess('mentionable', $this->base(), [$address, $this->base() . '_test/mentionable/xyz/', $this->base() . 'feed.xml'], $record);

            $this->assertSame(['page' => 'Tests\Fixtures\MentionablePage', 'route' => '', 'arguments' => ['xyz'], 'legacy' => true, 'received' => true], $answers[$address]);
            $this->assertSame('idno_feed_xml', $answers[$this->base() . 'feed.xml']['route']);
            // the page was built as the router builds it: its arguments, marked a permalink, and given what the endpoint fetched
            $lines = array_map(static fn (string $line): array => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($record))));
            $this->assertCount(2, $lines);
            $this->assertSame(['arguments' => ['xyz'], 'permalink' => true, 'source' => 'https://a.example/post', 'target' => $address, 'response' => ['response' => 200], 'mf2' => ['items' => []]], $lines[0]);
        } finally {
            @unlink($record);
        }
    }

    public function testATargetUnderASubdirectoryIsResolved(): void
    {
        $url = $this->base() . 'blog/';
        $answers = $this->resolveInAProcess('subdirectory', $url, [$url, $url . 'content/note', $url . 'feed.xml', $url . 'view/abc123', $url . 'pages/about', $this->base() . 'content/note', $this->base()]);

        $this->assertSame('idno_home', $answers[$url]['route']);
        $this->assertSame(['idno_content', ['note']], [$answers[$url . 'content/note']['route'], $answers[$url . 'content/note']['arguments']]);
        $this->assertSame('idno_feed_xml', $answers[$url . 'feed.xml']['route']);
        $this->assertSame(['Idno\Pages\Entity\View', ['abc123']], [$answers[$url . 'view/abc123']['page'], $answers[$url . 'view/abc123']['arguments']]);
        $this->assertSame('IdnoPlugins\StaticPages\Pages\View', $answers[$url . 'pages/about']['page']);
        // an address on the host that is not under the site's directory is not the site's page
        $this->assertNull($answers[$this->base() . 'content/note']);
        $this->assertNull($answers[$this->base()]);
    }

    public function testNothingIsResolvedWithoutTheLegacyApplication(): void
    {
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        $resolver = new LegacyWebmentionTargets($kernel->getContainer()->get('router'), static function (): never {
            throw new \LogicException('the legacy dispatcher is not built without the legacy application');
        }, $kernel, new BootConfig(['url' => 'https://example.com/'], false));

        $this->assertNull($resolver->resolve('https://example.com/'));
    }
}
