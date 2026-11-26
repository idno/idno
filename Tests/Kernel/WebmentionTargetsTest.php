<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Common\ContentType;
use Idno\Common\MarkdownPermalink;
use Idno\Common\Page;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Http\Controller\Home\HomePages;
use Idno\Http\Controller\Permalink\PermalinkPages;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Idno\Http\Webmention\WebmentionTarget;
use Idno\Http\Webmention\WebmentionTargets;
use Idno\Kernel;
use Idno\Legacy\LegacyDispatcher;
use Idno\Legacy\LegacyWebmentionTargets;
use Idno\Portability\SiteFacts;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Tests\Fixtures\PingController;
use Tests\Fixtures\SitePageFixtureController;
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

    /** The permalink address of a post as the site makes it. */
    private function permalink(): string
    {
        return $this->base() . '2026/a-note';
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

    public function testTheAddressesOfPagesThatTakeNoMentionAreNotTargets(): void
    {
        // a page a controller serves whose receiver refuses its route (the feeds, the search and the tags of the home page's stream), and one with no receiver (the small
        // public endpoints): no target, so a caller answers "no such target" before it fetches a source
        foreach (['feed.xml', 'feed', 'rss.xml', 'search', 'tag/books', 'robots.txt', 'chrome/manifest.json', 'service-worker.min.js', 'search/mentions.json', 'service/security/csrftoken/', '.well-known/webfinger'] as $path) {
            $this->assertNull($this->targets()->resolve($this->base() . $path), $path);
        }
    }

    public function testThePagesOfTheHomePagesReceiverAreTargets(): void
    {
        $pages = Idno::container()->get(HomePages::class);
        foreach (['', 'content/note'] as $path) {
            $target = $this->targets()->resolve($this->base() . $path);

            $this->assertNotNull($target, $path);
            $this->assertTrue($pages->receivesFor($target->route), $path);
        }
    }

    public function testAPageThatTakesNoMentionIsNoTarget(): void
    {
        // the legacy pages whose class does not declare a webmentionContent() of its own answered false: no target
        foreach (['edit/abc123', 'webmention/', 'stream/', 'admin/', 'actor/ada', 'actor/ada/inbox', 'account/settings/', 'file/abc123', 'drafts/', 'status/edit/'] as $path) {
            $this->assertNull($this->targets()->resolve($this->base() . $path), $path);
        }
    }

    public function testTheFrontControllerIsTheHomePage(): void
    {
        $this->assertSame('idno_home', $this->targets()->resolve($this->base() . 'index.php')?->route);
    }

    public function testTheHomePageServiceIsTheReceiverOfTheHomePage(): void
    {
        $probe = Idno::container()->get(WebmentionReceiverProbe::class);

        $this->assertContains('Idno\Pages\Homepage', $probe->pages());
        $this->assertInstanceOf(HomePages::class, $probe->receiverOf('Idno\Pages\Homepage'));
        $this->assertNull($probe->receiverOf('Idno\Pages\NoSuchPage'));
    }

    // ------------------------------------------------------------------ the pages that are still classes

    public function testAPermalinkIsATarget(): void
    {
        $target = $this->targets()->resolve($this->permalink());

        $this->assertNotNull($target);
        $this->assertFalse($target->isLegacy());
        $this->assertSame('Idno\Pages\Entity\View', $target->page);
        $this->assertSame('idno_permalink', $target->route);
        $this->assertSame(['a-note'], array_slice($target->arguments, -1));
    }

    public function testThePermalinksMarkdownFormIsThePermalink(): void
    {
        $target = $this->targets()->resolve($this->permalink() . '.md');

        $this->assertNotNull($target);
        $this->assertSame('Idno\Pages\Entity\View', $target->page);
        $this->assertSame(['a-note'], array_slice($target->arguments, -1));
    }

    public function testAShortlinkIsATarget(): void
    {
        foreach (['s/abc123', 's/abc123/', 's/ab-12'] as $path) {
            $this->assertSame(['page' => 'Idno\Pages\Entity\Shortlink', 'route' => 'idno_shortlink', 'arguments' => [basename($path)], 'legacy' => false], $this->answer($this->targets()->resolve($this->base() . $path)), $path);
        }
        // a code the route does not take is no address of a page
        $this->assertNull($this->targets()->resolve($this->base() . 's/ab_12'));
        $this->assertNull($this->targets()->resolve($this->base() . 's/'));
        $this->assertInstanceOf(\Idno\Http\Controller\Permalink\ShortlinkPages::class, Idno::container()->get(WebmentionReceiverProbe::class)->receiverOf('Idno\Pages\Entity\Shortlink'));
    }

    public function testAProfileIsATarget(): void
    {
        foreach (['profile/ada' => ['ada'], 'profile/ada/' => ['ada'], 'profile/ada/notes' => ['ada', 'notes'], 'profile/ada/articles/notes' => ['ada', 'articles/notes'], 'profile/ada/all' => ['ada', 'all']] as $path => $arguments) {
            $this->assertSame(['page' => 'Idno\Pages\User\View', 'route' => 'idno_profile', 'arguments' => $arguments, 'legacy' => false], $this->answer($this->targets()->resolve($this->base() . $path)), $path);
        }
        // an address that names no page of the profile's patterns
        $this->assertNull($this->targets()->resolve($this->base() . 'profile/'));
        $this->assertNull($this->targets()->resolve($this->base() . 'profile/ada/123'));
        $this->assertInstanceOf(\Idno\Http\Controller\Profile\ProfilePages::class, Idno::container()->get(WebmentionReceiverProbe::class)->receiverOf('Idno\Pages\User\View'));
    }

    public function testAnAnnotationListTakesNoMention(): void
    {
        // the pages of a post's annotations had no webmentionContent(): an address of one is no target, in any layout and for the delete
        foreach ([$this->permalink() . '/annotations/', $this->permalink() . '/annotations/abc123', $this->base() . 'view/abc123/annotations/', $this->base() . '2026/03/a-note/annotations/abc123', $this->base() . '2026/03/14/a-note/annotations/', $this->permalink() . '/annotations/abc123/delete', $this->permalink() . '/annotation/delete'] as $address) {
            $this->assertNull($this->targets()->resolve($address), $address);
        }
    }

    public function testAStaticPageIsALegacyTarget(): void
    {
        $this->assertSame(['page' => 'IdnoPlugins\StaticPages\Pages\View', 'route' => '', 'arguments' => ['about'], 'legacy' => true], $this->answer($this->targets()->resolve($this->base() . 'pages/about')));
    }

    /**
     * A path a registered pattern of the legacy route table matches, made from the pattern (null for one this does not know how to write out).
     */
    private static function sampleOf(string $pattern): ?string
    {
        $sample = $pattern;
        foreach (['[0-9]{4}' => '2026', '[0-9]{2}' => '11', '[A-Za-z\\-\\/]+' => 'note', '[A-Za-z0-9\\-]+' => 'abc123', '[A-Za-z0-9]+' => 'abc123', '[0-9]+' => '7', '[^/.]+' => 'abc', '[^\\/]+' => 'abc', '[^\\s]+' => 'abc', '.*' => 'x'] as $from => $to) {
            $sample = str_replace($from, $to, $sample);
        }
        $sample = preg_replace(['~\)[+?]~', '~[()]~', '~([a-z])\?~', '~/\?$~'], [')', '', '$1', ''], $sample);
        $sample = strtr((string) $sample, ['\\.' => '.', '\\-' => '-', '\\/' => '/']);
        if (preg_match('~[\[\]()*+?|{}\\^$]~', $sample) === 1) {
            return null;
        }

        return '/' . ltrim($sample, '/');
    }

    /**
     * The addresses of the booted site: a sample of every pattern of its legacy route table, every content type's own permalink (an entity with a fixed id and slug, as it
     * prints its address), its annotations, and the static page.
     *
     * @return list<string>
     */
    private function addresses(): array
    {
        $paths = [];
        foreach (Idno::site()->routes() as $pattern => $handler) {
            $sample = self::sampleOf((string) $pattern);
            if ($sample !== null) {
                $paths[] = $sample;
            }
        }
        foreach (ContentType::getRegistered() as $type) {
            $class = $type->getEntityClass();
            $entity = new $class();
            $entity->_id = 'abc123def456';
            $entity->slug = 'hello-world';
            $entity->created = 1790000000;
            $permalink = substr($entity->getURL(), strlen($this->base()) - 1);
            array_push($paths, $permalink, $permalink . '/annotations/abc123', $permalink . '/annotations/abc123/delete');
        }
        $paths[] = '/';
        $paths[] = '/pages/about';
        $paths[] = '/content/note/article';
        $paths[] = '/no/such/page/here/at/all/x.y';

        return array_values(array_unique($paths));
    }

    /**
     * Each address, and the spellings of it a sender may use: a trailing slash added or taken off, a query string, a fragment, a percent-encoded character in the last
     * segment (both cases of the hex digits), and `.md`.
     *
     * @return list<string>
     */
    private static function spellingsOf(string $path): array
    {
        $plain = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');

        return array_values(array_unique([$path, $plain, $plain . '/', $plain . '?_t=json', $plain . '#frag', $plain . '%C3%A9', $plain . '%c3%a9', $plain . '.md']));
    }

    /**
     * The lookup `PageHandler::getRoute()` made, without building the page (a page's constructor may send a header): the first registered pattern that matches the
     * path of the address, and the class it names when that is a page.
     *
     * @return array{class: class-string<Page>, arguments: list<string>}|null
     */
    private function legacyLookup(string $address): ?array
    {
        $path = parse_url($address, PHP_URL_PATH) ?: '/';
        $routes = Idno::site()->routes();
        foreach ($routes as $pattern => $handler) {
            if (preg_match('#^/?' . strtr((string) $pattern, $routes->routeTokens()) . '/?$#', $path, $matches)) {
                if (!is_string($handler) || !class_exists($handler) || !is_a($handler, Page::class, true)) {
                    return null;
                }
                unset($matches[0]);

                return ['class' => ltrim($handler, '\\'), 'arguments' => array_values($matches)];
            }
        }

        return null;
    }

    /**
     * What the legacy lookup the endpoint made (`PageHandler::getRoute()`) built for each address a site has, against what the resolver says, for every spelling of it:
     * a page that declares a `webmentionContent()` of its own is a target (a legacy one with the same class and arguments, or a page a controller serves whose receiver takes
     * the route); a page that does not (the base page answers false) and an address no page serves are no target.
     */
    public function testEveryAddressOfTheSiteIsTheLegacyLookupsPageOrNoTarget(): void
    {
        $probe = Idno::container()->get(WebmentionReceiverProbe::class);
        $mismatches = [];
        $targets = 0;
        $checked = 0;
        foreach ($this->addresses() as $path) {
            foreach (self::spellingsOf($path) as $spelling) {
                $address = rtrim($this->base(), '/') . $spelling;
                $legacy = $this->legacyLookup($address);
                if ($legacy === null && str_ends_with($spelling, '.md')) {
                    // the Markdown form of a permalink is the permalink (the legacy lookup did not strip the suffix)
                    $stripped = $this->legacyLookup(substr($address, 0, -3));
                    $legacy = $stripped !== null && is_a($stripped['class'], MarkdownPermalink::class, true) ? $stripped : null;
                }
                $target = $this->targets()->resolve($address);
                ++$checked;

                $takesMentions = $legacy !== null && (new \ReflectionMethod($legacy['class'], 'webmentionContent'))->getDeclaringClass()->getName() !== Page::class;
                if (!$takesMentions) {
                    if ($target !== null && !$target->isLegacy() && $target->page === 'Idno\Pages\Entity\Shortlink' && str_starts_with($spelling, '/s/') && $probe->receiverOf($target->page)?->receivesFor($target->route) === true) {
                        // the shortlink, a page of core that took mentions and is a controller's now: the legacy table holds a placeholder at its address
                        ++$targets;
                        continue;
                    }
                    if ($target !== null) {
                        $mismatches[] = $address . ' is no page that takes mentions, and resolved to ' . json_encode($this->answer($target));
                    }
                    continue;
                }
                ++$targets;
                if ($target === null) {
                    $mismatches[] = $address . ' is the page ' . $legacy['class'] . ', which takes mentions, and resolved to nothing';
                } elseif ($target->isLegacy()) {
                    if ($target->page !== $legacy['class'] || $target->arguments !== $legacy['arguments']) {
                        $mismatches[] = $address . ' is ' . $legacy['class'] . json_encode($legacy['arguments']) . ' and resolved to ' . json_encode($this->answer($target));
                    }
                } else {
                    $receiver = $probe->receiverOf($target->page);
                    if ($target->page !== $legacy['class'] || $receiver === null || !$receiver->receivesFor($target->route)) {
                        $mismatches[] = $address . ' is ' . $legacy['class'] . ' and resolved to a page its receiver does not take: ' . json_encode($this->answer($target));
                    }
                }
            }
        }

        $this->assertSame([], $mismatches);
        // (the test is not vacuous: it looked at a good many addresses, and many of them are pages that take mentions)
        $this->assertGreaterThan(600, $checked);
        $this->assertGreaterThan(60, $targets);
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

    public function testAnAddressSymfonyWillNotMakeARequestOfIsNotFound(): void
    {
        // (a backslash in the path: building the request throws, and a sender must not be able to make the endpoint fail with it)
        $this->assertNull($this->targets()->resolve($this->base() . 'view/abc\\123'));
        $this->assertNull($this->targets()->resolve($this->base() . "view/abc\x00123"));
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

    public function testOnlyTheSitesOwnHostIsATarget(): void
    {
        // no www. or bare counterpart, no subdomain, no other host
        $this->assertNotNull($this->resolverFor('https://example.com/')->resolve('https://example.com/'));
        $this->assertNull($this->resolverFor('https://example.com/')->resolve('https://www.example.com/'));
        $this->assertNull($this->resolverFor('https://www.example.com/')->resolve('https://example.com/'));
        $this->assertNull($this->resolverFor('https://example.com/')->resolve('https://blog.example.com/'));
        $this->assertNull($this->resolverFor('https://example.com/')->resolve('https://old.example/'));
    }

    public function testNoSettingListsOtherHosts(): void
    {
        // (the boot configuration is not asked for hosts: a setting that listed them is gone)
        $resolver = new LegacyWebmentionTargets(
            Idno::container()->get('router'),
            static fn (): LegacyDispatcher => Idno::container()->get(LegacyDispatcher::class),
            Idno::container()->get('kernel'),
            new BootConfig(['url' => 'https://example.com/', 'webmention_target_hosts' => ['old.example']], false),
            $this->createStub(SiteFacts::class),
            new ServiceLocator(['Idno\\Pages\\Homepage' => static fn (): HomePages => Idno::container()->get(HomePages::class)]),
            static fn (): LoggerInterface => new NullLogger(),
        );

        $this->assertNull($resolver->resolve('https://old.example/'));
        $this->assertNotNull($resolver->resolve('https://example.com/'));
    }

    public function testASiteWithNoUrlSettingIsMatchedByTheAddressItDetected(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [$level, (string) $message];
            }
        };
        $resolver = $this->resolverFor(null, detected: 'https://detected.example/', logger: $logger);

        $this->assertSame('idno_home', $resolver->resolve('https://detected.example/')?->route);
        $this->assertSame('idno_home', $resolver->resolve('http://detected.example/')?->route);
        $this->assertNull($resolver->resolve('https://www.detected.example/'));
        // never by the path alone
        $this->assertNull($resolver->resolve('https://other.example/'));
        $this->assertNull($resolver->resolve('/content/note'));
        // one warning a process, naming the setting and no host
        $this->assertCount(1, $logger->lines);
        $this->assertSame('warning', $logger->lines[0][0]);
        $this->assertStringContainsString('url', $logger->lines[0][1]);
        $this->assertStringNotContainsString('detected.example', $logger->lines[0][1]);
    }

    public function testASiteWithAnUrlSettingLogsNothingAndIgnoresTheDetectedAddress(): void
    {
        $logger = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };
        $resolver = $this->resolverFor('https://example.com/', detected: 'https://detected.example/', logger: $logger);

        $this->assertNotNull($resolver->resolve('https://example.com/'));
        $this->assertNull($resolver->resolve('https://detected.example/'));
        $this->assertSame(0, $logger->count);
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
            foreach (['', 'content/note', 'view/abc123', 'pages/about'] as $path) {
                $this->assertNotNull($this->targets()->resolve($this->base() . $path), $path);
            }
        } finally {
            Idno::site()->events()->removeListener('page/ready', $listener);
        }

        $this->assertSame([], $events);
        $this->assertSame($current, Idno::site()->currentPage());
    }

    private function stubRouter(array $match, ?RouteCollection $routes = null): RouterInterface
    {
        return new class(Idno::container()->get('router'), $match, $routes ?? new RouteCollection()) implements RouterInterface {
            public function __construct(private readonly RouterInterface $router, private readonly array $match, private readonly RouteCollection $routes)
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

            public function getRouteCollection(): RouteCollection
            {
                return $this->routes;
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return $this->router->generate($name, $parameters, $referenceType);
            }

            public function match(string $pathinfo): array
            {
                return $this->match;
            }
        };
    }

    public function testTheArgumentsOfAPageAreInTheOrderOfItsPath(): void
    {
        // (the matcher's array puts a route's defaults first: the order of the path is the route's)
        $routes = new RouteCollection();
        $routes->add('idno_stub', new Route('/{lead}stub/{second}/{first}{slash}', ['lead' => '', 'slash' => '']));
        $router = $this->stubRouter(['lead' => '', 'slash' => '', 'first' => 'one', 'second' => 'two', '_route' => 'idno_stub', '_controller' => SitePageFixtureController::class . '::state'], $routes);
        $receiver = new class implements ReceivesWebmentions {
            public function receivesFor(string $route): bool
            {
                return true;
            }

            public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool
            {
                return true;
            }
        };
        $resolver = $this->resolverFor($this->base(), $router, receivers: new ServiceLocator(['Tests\Fixtures\SitePageFixture' => static fn (): ReceivesWebmentions => $receiver]));

        $target = $resolver->resolve($this->base() . 'stub/two/one');

        $this->assertSame(['Tests\Fixtures\SitePageFixture', 'idno_stub', ['two', 'one']], [$target?->page, $target?->route, $target?->arguments]);
    }

    public function testARouteThatIsNoSitePageIsNoPageOfTheSiteEvenWhereTheLegacyTableHasOne(): void
    {
        // the table serves /view/<id> with a page that takes mentions: a GET is answered by the route that matched, which is no page of the site's
        $router = $this->stubRouter(['_route' => 'idno_test_ping', '_controller' => PingController::class]);

        $this->assertNull($this->resolverFor($this->base(), $router)->resolve($this->base() . 'view/abc123'));
        $this->assertNotNull($this->resolverFor($this->base())->resolve($this->base() . 'view/abc123'));
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

    private function resolverFor(?string $siteUrl, ?RouterInterface $router = null, string $detected = '/', ?LoggerInterface $logger = null, ?ContainerInterface $receivers = null): LegacyWebmentionTargets
    {
        $dispatcher = Idno::container()->get(LegacyDispatcher::class);
        $facts = $this->createStub(SiteFacts::class);
        $facts->method('url')->willReturn($detected);
        $values = $siteUrl !== null ? ['url' => $siteUrl] : [];
        $logger ??= new NullLogger();

        return new LegacyWebmentionTargets(
            $router ?? Idno::container()->get('router'),
            static fn (): LegacyDispatcher => $dispatcher,
            Idno::container()->get('kernel'),
            new BootConfig($values, false),
            $facts,
            $receivers ?? new ServiceLocator([
                'Idno\Pages\Homepage' => static fn (): HomePages => Idno::container()->get(HomePages::class),
                'Idno\Pages\Entity\View' => static fn (): PermalinkPages => Idno::container()->get(PermalinkPages::class),
            ]),
            static fn (): LoggerInterface => $logger,
        );
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
            $this->assertNull($answers[$this->base() . 'feed.xml']);
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
        $answers = $this->resolveInAProcess('subdirectory', $url, [$url, $url . 'content/note', $url . 'feed.xml', $url . 'view/abc123', $url . 'pages/about', $this->base() . 'content/note', $this->base(), $url . '../content/note', $url . 'a/../../content/note', $url . '%2e%2e/content/note']);

        $this->assertSame('idno_home', $answers[$url]['route']);
        $this->assertSame(['idno_content', ['note']], [$answers[$url . 'content/note']['route'], $answers[$url . 'content/note']['arguments']]);
        $this->assertNull($answers[$url . 'feed.xml']);
        $this->assertSame(['Idno\Pages\Entity\View', ['abc123']], [$answers[$url . 'view/abc123']['page'], $answers[$url . 'view/abc123']['arguments']]);
        $this->assertSame('IdnoPlugins\StaticPages\Pages\View', $answers[$url . 'pages/about']['page']);
        // an address on the host that is not under the site's directory is not the site's page
        $this->assertNull($answers[$this->base() . 'content/note']);
        $this->assertNull($answers[$this->base()]);
        // a dot segment does not lead out of the directory
        foreach (['../content/note', 'a/../../content/note', '%2e%2e/content/note'] as $path) {
            $this->assertNull($answers[$url . $path], $path);
        }
    }

    public function testNothingIsResolvedWithoutTheLegacyApplication(): void
    {
        $kernel = new Kernel('test', true, bootLegacy: false);
        $kernel->boot();
        $resolver = new LegacyWebmentionTargets($kernel->getContainer()->get('router'), static function (): never {
            throw new \LogicException('the legacy dispatcher is not built without the legacy application');
        }, $kernel, new BootConfig(['url' => 'https://example.com/'], false), $this->createStub(SiteFacts::class), new ServiceLocator([]), static fn (): LoggerInterface => new NullLogger());

        $this->assertNull($resolver->resolve('https://example.com/'));
    }
}
