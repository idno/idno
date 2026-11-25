<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use Idno\Entities\User;
use Idno\Http\Controller\Home\HomePages;
use Idno\Http\Controller\Home\HomeStream;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The home page's one implementation (`HomePages`), on doubles of the stream and of the drawing: what it asks the stream, what it draws
 * and with which variables, the header it asks for, the redirect of an empty site and what it does with a webmention of the site's root.
 */
final class HomePagesTest extends TestCase
{
    private HomeStreamDouble $stream;

    private HomeDrawerDouble $drawer;

    private HomeLogger $logger;

    private HomePages $pages;

    private HomeEvents $events;

    protected function setUp(): void
    {
        $this->stream = new HomeStreamDouble();
        $this->drawer = new HomeDrawerDouble();
        $this->logger = new HomeLogger();
        $this->events = new HomeEvents();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $this->pages = new HomePages($this->stream, new PageRenderer($this->drawer, $translation), fn () => $this->logger, fn () => $this->events);
    }

    private function context(string $uri = '/'): PageContext
    {
        return new PageContext(Request::create($uri), new SitePage('Idno\Pages\Homepage'));
    }

    /**
     * A request as PHP's web server hands it over: the address as sent (`Request::create()` reads `//tag/x` as a host and decodes nothing it should keep).
     */
    private function sent(string $uri): PageContext
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return new PageContext(new Request($query, [], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => '/srv/index.php', 'PHP_SELF' => '/index.php', 'HTTP_HOST' => 'localhost']), new SitePage('Idno\Pages\Homepage'));
    }

    private static function item(int $updated): \stdClass
    {
        $item = new \stdClass();
        $item->updated = $updated;

        return $item;
    }

    public function testTheHomePageAsksForAReplacementFirstWithThePageAndTheRenderer(): void
    {
        $page = $this->context();

        $this->pages->home($page);

        $this->assertCount(1, $this->events->calls);
        [$name, $data] = $this->events->calls[0];
        $this->assertSame('homepage/replace', $name);
        $this->assertSame(['page', 'renderer'], array_keys($data));
        $this->assertSame($page, $data['page']);
        $this->assertInstanceOf(PageRenderer::class, $data['renderer']);
        $this->assertNotSame([], $this->drawer->pages, 'the stream is drawn when nobody replaces the home page');
    }

    public function testAReplacementResponseIsTheHomePage(): void
    {
        $this->events->answer = new Response('the replacement', 200, ['X-Replaced' => '1']);

        $response = $this->pages->home($this->context());

        $this->assertSame($this->events->answer, $response);
        $this->assertSame([], $this->stream->calls, 'the stream is not asked for anything');
        $this->assertSame([], $this->drawer->pages, 'and nothing of its own is drawn');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonsense(): array
    {
        return ['the default answer' => [true], 'false' => [false], 'null' => [null], 'a string' => ['<html>'], 'an array' => [[new Response('in a list')]], 'an object that is no response' => [new \stdClass()], 'a number' => [200]];
    }

    /**
     * @dataProvider nonsense
     */
    public function testANonResponseAnswerIsIgnored(mixed $answer): void
    {
        $this->events->answer = $answer;
        $this->stream->items = [self::item(1_700_000_000)];

        $response = $this->pages->home($this->context());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('page:', (string) $response->getContent(), 'the stream is drawn');
        $this->assertNotSame([], $this->stream->calls);
    }

    public function testAListenerThatThrowsFailsThePageAsAFailingPageDoes(): void
    {
        $this->events->throw = new \RuntimeException('a plugin broke');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a plugin broke');
        $this->pages->home($this->context());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addresses(): array
    {
        return [
            'the root' => ['/', true],
            'the root with a query' => ['/?_t=json&q=x', true],
            'a feed that draws the stream' => ['/feed.xml', false],
            'a tag search that draws it' => ['/tag/books', false],
            'a page of a plugin that extends the home page' => ['/third-party-home/', false],
        ];
    }

    /**
     * What is replaced is the site's root: a page that draws the home page's stream under an address of its own (the feed) is not the home page.
     *
     * @dataProvider addresses
     */
    public function testOnlyTheRootIsReplaced(string $address, bool $replaced): void
    {
        $this->events->answer = new Response('the replacement');

        $response = $this->pages->home($this->context($address));

        $this->assertSame($replaced, $response === $this->events->answer, $address);
        $this->assertCount($replaced ? 1 : 0, $this->events->calls, $address);
    }

    public function testContentNeverAsksForAReplacement(): void
    {
        $this->events->answer = new Response('the replacement');

        $response = $this->pages->content($this->context('/content/note'), 'note');

        $this->assertSame([], $this->events->calls);
        $this->assertStringStartsWith('page:', (string) $response->getContent());
    }

    public function testAnEmptySiteRedirectsToBegin(): void
    {
        $this->stream->hasUsers = false;

        $response = $this->pages->home($this->context());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/begin/', $response->headers->get('Location'));
        $this->assertSame([], $this->drawer->pages, 'nothing is drawn');
        $this->assertSame([], $this->stream->calls, 'the stream is not asked for a page');
        $this->assertSame(1, $this->drawer->finished, 'the session ends, as a forward ends it');
    }

    public function testTheBodyAndShellVariables(): void
    {
        $this->stream->items = [self::item(1_700_000_000)];
        $this->stream->count = 41;
        $this->stream->create = ['a-type'];
        $this->stream->titles = ['title' => 'The Title', 'description' => 'The description'];

        $response = $this->pages->home($this->context('/?q=gardens&offset=20'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->drawer->templates);
        [$name, $bodyVars] = $this->drawer->templates[0];
        $this->assertSame('pages/home', $name);
        $this->assertSame(['items', 'contentTypes', 'offset', 'count', 'subject', 'content'], array_keys($bodyVars));
        $this->assertSame([$this->stream->items, ['a-type'], 20, 41, 'gardens', []], array_values($bodyVars));
        $this->assertCount(1, $this->drawer->pages);
        [$vars, $status, $shell] = $this->drawer->pages[0];
        $this->assertSame(['title', 'description', 'content', 'body'], array_keys($vars));
        $this->assertSame(['The Title', 'The description', [], '<pages/home>'], array_values($vars));
        $this->assertSame([200, 'shell'], [$status, $shell]);
        $this->assertSame('page:The Title:<pages/home>', $response->getContent());
    }

    public function testTheStreamIsAskedForTheResolvedTypesTheQueryAndTheOffset(): void
    {
        $this->stream->resolved = ['types' => ['T1', 'T2'], 'friendly' => []];

        $this->pages->home($this->context('/?q=word&types=Requested&offset=40'));

        $this->assertSame([['types', null, 'word', 'Requested'], ['page', ['T1', 'T2'], 'word', 40]], array_slice($this->stream->calls, 0, 2));
    }

    public function testLastModifiedIsTheFirstItemsUpdated(): void
    {
        // a hole (a record whose class is gone) is skipped, as the page skipped it
        $this->stream->items = [false, self::item(1_700_000_123), self::item(5)];

        $this->pages->home($context = $this->context());

        $this->assertSame(['Last-Modified' => ['RFC2616(1700000123)']], $context->headers());
    }

    public function testThereIsNoLastModifiedWithoutAnItem(): void
    {
        foreach ([[], false] as $items) {
            $this->stream->items = $items;
            $this->pages->home($context = $this->context());
            $this->assertSame([], $context->headers());
        }
    }

    public function testTheSingleUserOwnerIsThePagesOwner(): void
    {
        $owner = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->stream->owner = $owner;

        $this->pages->home($context = $this->context());

        $this->assertSame($owner, $context->owner());
    }

    public function testAnOwnerThePageHasStays(): void
    {
        $mine = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->stream->owner = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $context = $this->context();
        $context->setOwner($mine);

        $this->pages->home($context);

        $this->assertSame($mine, $context->owner());
    }

    public function testContentPassesTheFriendlyTypes(): void
    {
        $this->stream->resolved = ['types' => ['T'], 'friendly' => [0 => 'note', 2 => 'article']];

        $this->pages->content($context = $this->context('/content/note//article'), 'note//article');

        $this->assertSame(['types', 'note//article', null, null], $this->stream->calls[0]);
        $this->assertSame([0 => 'note', 2 => 'article'], $this->drawer->templates[0][1]['content']);
        $this->assertSame([0 => 'note', 2 => 'article'], $this->drawer->pages[0][0]['content']);
    }

    public function testARequestedTypeFiltersASearch(): void
    {
        $this->pages->content($this->context('/?q=x&types=IdnoPlugins%5CText%5CEntry'), 'all');

        $this->assertSame(['types', 'all', 'x', 'IdnoPlugins\Text\Entry'], $this->stream->calls[0]);
    }

    public function testTheFeedDrawsTheStreamAndNeverAsksForAReplacement(): void
    {
        $this->events->answer = new Response('the replacement');
        $this->stream->items = [self::item(1_700_000_000)];

        $response = $this->pages->feed($context = $this->context('/feed.xml'));

        $this->assertSame([], $this->events->calls, 'the feed is not the site\'s root: no plugin replaces it');
        $this->assertStringStartsWith('page:', (string) $response->getContent());
        $this->assertSame(['types', null, null, null], $this->stream->calls[0], 'the home page\'s own types, no friendly path');
        $this->assertSame(['Last-Modified' => ['RFC2616(1700000000)']], $context->headers());
        $this->assertSame('pages/home', $this->drawer->templates[0][0]);
    }

    public function testTheFeedAsksForItsStreamLikeTheHomePage(): void
    {
        $this->pages->feed($this->context('/rss.xml?q=word&offset=40&types=Requested'));

        $this->assertSame([['types', null, 'word', 'Requested'], ['page', [], 'word', 40]], array_slice($this->stream->calls, 0, 2));
    }

    public function testTheFeedOfAnEmptySiteRedirectsToBegin(): void
    {
        $this->stream->hasUsers = false;

        $response = $this->pages->feed($this->context('/feed'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/begin/', $response->headers->get('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: string}> the query string, the location's query
     */
    public static function searches(): array
    {
        return [
            'a word' => ['q=gardens', 'gardens'],
            'two words' => ['q=a%20b', 'a+b'],
            'no query' => ['', ''],
            'a plus and an ampersand' => ['q=a%2Bb%26c', 'a%2Bb%26c'],
            'not text' => ['q%5B%5D=x', ''],
        ];
    }

    /**
     * @dataProvider searches
     */
    public function testSearchRedirectsWithTheQueryEncoded(string $queryString, string $expected): void
    {
        $response = $this->pages->search($this->context('/search?' . $queryString));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/?q=' . $expected, $response->headers->get('Location'));
        $this->assertSame([], $this->stream->calls, 'the stream is not asked for anything');
        $this->assertSame([], $this->drawer->pages, 'nothing is drawn');
    }

    public function testSearchTakesTheQueryFromAPostedForm(): void
    {
        $page = new PageContext(Request::create('/search', 'POST', ['q' => 'posted']), new SitePage('Idno\Pages\Homepage'));

        $this->assertSame('https://example.com/?q=posted', $this->pages->search($page)->headers->get('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: string}> the path as sent, the subject the page lists
     */
    public static function tagPaths(): array
    {
        return [
            'plain' => ['/tag/books', '#books'],
            'a doubled leading slash' => ['//tag/books', '#books'],
            'a plus is a space' => ['/tag/a+b', '#a b'],
            'an encoded plus is a plus' => ['/tag/a%2Bb', '#a+b'],
            'encoded letters' => ['/tag/caf%C3%A9', '#café'],
            'encoded letters in lower case hex' => ['/tag/caf%c3%a9', '#café'],
            'bytes that are not UTF-8' => ['/tag/%FF', "#\xFF"],
            'a deeper path is the tag' => ['/tag/a/b', '#a/b'],
            'a percent sign that is not an escape' => ['/tag/100%', '#100%'],
            'a query string is not the tag' => ['/tag/books?q=x&_t=json', '#books'],
        ];
    }

    /**
     * @dataProvider tagPaths
     */
    public function testTagReadsTheRawPathAndDecodesItLikeUrldecode(string $path, string $subject): void
    {
        $this->stream->resolved = ['types' => ['T'], 'friendly' => ['all']];

        $this->pages->tag($context = $this->sent($path));

        $this->assertSame([['types', 'all', $subject, null], ['page', ['T'], $subject, 0]], array_slice($this->stream->calls, 0, 2));
        $this->assertSame($subject, $this->drawer->templates[0][1]['subject']);
        $this->assertSame(['all'], $this->drawer->templates[0][1]['content']);
    }

    public function testTagIgnoresATrailingSlash(): void
    {
        foreach (['/tag/books/', '//tag/books/'] as $path) {
            $this->stream->calls = [];
            $this->pages->tag($this->sent($path));

            $this->assertSame('#books', $this->stream->calls[0][2], $path);
        }
        $this->stream->calls = [];
        $this->pages->tag($this->sent('/tag/books//'));
        $this->assertSame('#books/', $this->stream->calls[0][2], 'one slash only');
    }

    public function testTagWinsOverQ(): void
    {
        $this->pages->tag($this->sent('/tag/books?q=x'));

        $this->assertSame('#books', $this->stream->calls[0][2]);
        $this->assertSame('#books', $this->stream->calls[1][2]);
    }

    public function testATagThatIsOnlySlashesIsNoTag(): void
    {
        $response = $this->pages->tag($this->sent('/tag//'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->stream->calls);
    }

    public function testTheTagPagesTypesAreEveryTypeWhateverTheRequestAsks(): void
    {
        $this->pages->tag($this->sent('/tag/books?types=IdnoPlugins%5CText%5CEntry'));

        $this->assertSame(['types', 'all', '#books', 'IdnoPlugins\Text\Entry'], $this->stream->calls[0], 'the stream decides what the friendly path overrides');
    }

    public function testAnEmptySiteHasNoTagPage(): void
    {
        $this->stream->hasUsers = false;

        $this->assertSame('https://example.com/begin/', $this->pages->tag($this->sent('/tag/books'))->headers->get('Location'));
    }

    public function testARootWebmentionOnASingleUserSiteGoesToTheProfile(): void
    {
        $owner = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->stream->single = true;
        $this->stream->administrator = $owner;
        $this->stream->profileAccepts = true;

        $accepted = $this->pages->receiveWebmention('https://a.example/post', 'https://example.com/', ['response' => 200], ['items' => []]);

        $this->assertTrue($accepted);
        $this->assertSame([['profile', $owner, 'https://a.example/post', 'https://example.com/', ['response' => 200], ['items' => []]]], $this->stream->profile);
        $this->assertSame([['info', 'received homepage mention from https://a.example/post']], $this->logger->lines);
    }

    public function testARootWebmentionOnAMultiUserSiteIsRefused(): void
    {
        $this->stream->single = false;

        $this->assertFalse($this->pages->receiveWebmention('https://a.example/post', 'https://example.com/', [], []));

        $this->assertSame([], $this->stream->profile);
        $this->assertSame([['info', 'received homepage mention from https://a.example/post'], ['debug', 'disregarding mention to multi-user site']], $this->logger->lines);
    }

    public function testARootWebmentionOnASingleUserSiteWithNoAdministratorIsRefused(): void
    {
        $this->stream->single = true;
        $this->stream->administrator = null;

        $this->assertFalse($this->pages->receiveWebmention('https://a.example/post', 'https://example.com/', [], []));

        $this->assertSame([], $this->stream->profile);
        $this->assertSame([['info', 'received homepage mention from https://a.example/post'], ['debug', 'query for an admin-user failed to find one']], $this->logger->lines);
    }

    public function testAProfilePageThatRefusesTheMentionRefusesIt(): void
    {
        $this->stream->single = true;
        $this->stream->administrator = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->stream->profileAccepts = false;

        $this->assertFalse($this->pages->receiveWebmention('s', 't', [], []));
    }
}

/** A stream that answers what the test sets and records what it is asked. */
final class HomeStreamDouble implements HomeStream
{
    public bool $hasUsers = true;

    public ?User $owner = null;

    /** @var array{types: array<int|string, mixed>, friendly: array<int, string>} */
    public array $resolved = ['types' => [], 'friendly' => []];

    /** @var array<int, mixed>|false */
    public array|false $items = [];

    public int $count = 0;

    /** @var array<int|string, mixed>|false */
    public array|false $create = false;

    /** @var array{title: string, description: string} */
    public array $titles = ['title' => 'T', 'description' => 'D'];

    public bool $single = false;

    public ?User $administrator = null;

    public bool $profileAccepts = false;

    /** @var list<array<int, mixed>> */
    public array $calls = [];

    /** @var list<array<int, mixed>> */
    public array $profile = [];

    public function siteHasUsers(): bool
    {
        return $this->hasUsers;
    }

    public function singleUserOwner(): ?User
    {
        return $this->owner;
    }

    public function isSingleUser(): bool
    {
        return $this->single;
    }

    public function firstAdministrator(): ?User
    {
        return $this->administrator;
    }

    public function beginUrl(): string
    {
        return 'https://example.com/begin/';
    }

    public function siteAddress(): string
    {
        return 'https://example.com/';
    }

    public function types(?string $friendly, string|array|null $query, string|array|null $requested): array
    {
        $this->calls[] = ['types', $friendly, $query, $requested];

        return $this->resolved;
    }

    public function page(array $types, string|array|null $query, int $offset): array
    {
        $this->calls[] = ['page', $types, $query, $offset];

        return ['items' => $this->items, 'count' => $this->count];
    }

    public function creatableTypes(): array|false
    {
        return $this->create;
    }

    public function titles(): array
    {
        return $this->titles;
    }

    public function lastModified(int $timestamp): string
    {
        return 'RFC2616(' . $timestamp . ')';
    }

    public function profilePageWebmention(User $user, string $source, string $target, array $sourceResponse, array $sourceMf2): bool
    {
        $this->profile[] = ['profile', $user, $source, $target, $sourceResponse, $sourceMf2];

        return $this->profileAccepts;
    }
}

final class HomeDrawerDouble implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    /** @var list<array{0: array<string, mixed>, 1: int, 2: string}> */
    public array $pages = [];

    public int $finished = 0;

    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function finishSession(): void
    {
        ++$this->finished;
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        $this->templates[] = [$name, $vars];

        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('page:' . ($vars['title'] ?? '') . ':' . ($vars['body'] ?? ''), [['Content-type', 'text/html']]);
    }
}

final class HomeLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = [(string) $level, (string) $message];
    }
}

final class HomeEvents implements \Idno\Queue\EventTrigger
{
    /** @var list<array{0: string, 1: array<mixed>}> */
    public array $calls = [];

    public mixed $answer = true;

    public ?\Throwable $throw = null;

    public function trigger(string $event, array $data): mixed
    {
        $this->calls[] = [$event, $data];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->answer;
    }
}
