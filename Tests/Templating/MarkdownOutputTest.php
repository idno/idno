<?php

declare(strict_types=1);

namespace Tests\Templating;

use Symfony\Component\Yaml\Yaml;
use Tests\Acceptance\Browser;
use Tests\Characterisation\SiteResponse;

/**
 * A post's permalink as Markdown, over HTTP on a seeded site: the three ways to ask for it, the headers, what an Accept
 * header prefers, who may read it, the pages that have no Markdown form, and the front matter of hostile values.
 * (The whole document of each bundled content type is a golden page: `fixtures/pages/non-html/markdown-*.md`.)
 */
final class MarkdownOutputTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    public function testTheSuffixTheQueryAndTheAcceptHeaderAllAnswerTheSameMarkdown(): void
    {
        $paths = $this->seedSite();
        $address = $this->baseUrl() . $paths['article'];

        $bySuffix = $this->get($paths['article'] . '.md');
        $this->assertMarkdown($bySuffix, $address);

        foreach ([
            'format' => $this->get($paths['article'] . '?format=markdown'),
            'template type' => $this->get($paths['article'] . '?_t=markdown'),
            'Accept' => $this->get($paths['article'], ['Accept' => 'text/markdown']),
            'suffix with a query' => $this->get($paths['article'] . '.md?utm_source=x'),
        ] as $way => $response) {
            $this->assertMarkdown($response, $address);
            $this->assertSame($bySuffix->body, $response->body, $way);
        }

        [$fields, $body] = $this->parse($bySuffix->body);
        $this->assertSame(
            [
                'title' => 'A longer article about templates',
                'author' => 'Ada Admin',
                'pubDate' => 'Mon, 02 Mar 2026 12:00:00 +0000',
                'description' => 'Templates draw pages. Hello again. A second paragraph.',
                'link' => $address,
                'guid' => $this->baseUrl() . 'view/00000000-0000-4000-8000-000000000012',
                'category' => ['templates', 'idno'],
            ],
            $fields
        );
        $this->assertStringContainsString("Templates draw pages. **Hello** again.\n\nA second paragraph.", $body);
        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!]/', $body, 'no HTML is left');
    }

    public function testTheHtmlPermalinkSaysThatItVariesByAccept(): void
    {
        $paths = $this->seedSite();

        $html = $this->get($paths['article']);

        $this->assertSame(200, $html->status);
        $this->assertStringStartsWith('text/html', (string) $html->header('Content-Type'));
        $this->assertStringContainsStringIgnoringCase('accept', (string) $html->header('Vary'));
        $this->assertStringNotContainsString('rel="canonical"', (string) $html->header('Link'), 'the canonical link is the Markdown form\'s');

        $page = $this->get($paths['page']);
        $this->assertStringStartsWith('text/html', (string) $page->header('Content-Type'));
        $this->assertStringContainsStringIgnoringCase('accept', (string) $page->header('Vary'));
    }

    public function testAnAcceptHeaderChoosesMarkdownOnlyWhenItPrefersIt(): void
    {
        $paths = $this->seedSite();
        $kinds = [];
        foreach ([
            'text/markdown' => 'markdown',
            'text/markdown, text/html' => 'markdown',
            'text/markdown, text/html;q=0.9, */*;q=0.8' => 'markdown',
            'text/html;q=0.5, text/markdown' => 'markdown',
            'text/html, text/markdown;q=0.9' => 'html',
            'text/markdown;q=0.5, */*;q=0.8' => 'html',
            'text/markdown;q=0' => 'html',
            '*/*' => 'html',
            'text/*' => 'html',
            'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' => 'html',
        ] as $accept => $expected) {
            $kinds[$accept] = [$expected, $this->kind($this->get($paths['status'], ['Accept' => $accept]))];
        }
        foreach ($kinds as $accept => [$expected, $actual]) {
            $this->assertSame($expected, $actual, 'Accept: ' . $accept);
        }

        // A request that names a template type has chosen: the header does not override it
        $this->assertStringStartsWith('application/json', (string) $this->get($paths['status'] . '?_t=json', ['Accept' => 'text/markdown'])->header('Content-Type'));
        $this->assertStringStartsWith('application/rss+xml', (string) $this->get($paths['status'] . '?_t=rss', ['Accept' => 'text/markdown'])->header('Content-Type'));
        // and the suffix is a choice too
        $this->assertSame('markdown', $this->kind($this->get($paths['status'] . '.md', ['Accept' => 'text/html'])));
    }

    public function testAPostTheViewerMayNotReadAnswersAsItsHtmlPermalinkDoes(): void
    {
        $paths = $this->seedSite();
        $ada = $this->loginAsAdmin();
        $bob = (new Browser($this->site()))->login(self::MEMBER, self::PASSWORD);

        foreach (['private' => 'A private note only Ada can read.', 'draft' => 'A draft note, not yet published.'] as $name => $text) {
            foreach (['a visitor' => [], 'another member' => ['Cookie' => $bob]] as $who => $cookie) {
                $html = $this->get($paths[$name], $cookie);
                $this->assertContains($html->status, [403, 404, 410], $name . ' is closed to ' . $who);
                foreach ([
                    $this->get($paths[$name] . '.md', $cookie),
                    $this->get($paths[$name] . '?format=markdown', $cookie),
                    $this->get($paths[$name] . '?_t=markdown', $cookie),
                    $this->get($paths[$name], $cookie + ['Accept' => 'text/markdown']),
                ] as $response) {
                    $this->assertSame($html->status, $response->status, $name . ' for ' . $who);
                    $this->assertSame('html', $this->kind($response), $name . ' for ' . $who);
                    $this->assertStringNotContainsString($text, $response->body);
                    $this->assertStringNotContainsString('rel="canonical"', (string) $response->header('Link'));
                }
            }

            $owner = $this->get($paths[$name] . '.md', ['Cookie' => $ada]);
            $this->assertMarkdown($owner, $this->baseUrl() . $paths[$name]);
            $this->assertStringContainsString($text, $owner->body, 'the owner reads the ' . $name);
        }
    }

    public function testAPostThatIsNotThereAnswersAsItsHtmlPermalinkDoes(): void
    {
        $paths = $this->seedSite();
        $missing = dirname($paths['status']) . '/no-such-post-at-all';

        $html = $this->get($missing);
        $this->assertContains($html->status, [404, 410]);
        foreach ([$this->get($missing . '.md'), $this->get($missing . '?format=markdown'), $this->get($missing, ['Accept' => 'text/markdown'])] as $response) {
            $this->assertSame($html->status, $response->status);
            $this->assertSame('html', $this->kind($response));
        }
    }

    public function testOnlyAPermalinkHasAMarkdownForm(): void
    {
        $paths = $this->seedSite();

        // Asked of another page, the type is ignored: the page is what it is without it
        foreach (['', 'profile/' . self::ADMIN, 'content/all/', 'session/login', 'tag/templates'] as $page) {
            $plain = $this->get($page);
            $this->assertSame('html', $this->kind($plain), $page);
            foreach ([$this->get($page . '?_t=markdown'), $this->get($page . '?format=markdown'), $this->get($page, ['Accept' => 'text/markdown'])] as $response) {
                $this->assertSame($plain->status, $response->status, $page);
                $this->assertSame('html', $this->kind($response), $page);
            }
        }

        // The suffix on an address that is no permalink is no address
        foreach (['index.md', 'session/login.md', 'content/all.md', 'no-such-thing.md', 'admin.md', '.md', $paths['article'] . '.md.md', $paths['article'] . '.MD', $paths['article'] . '.md/'] as $path) {
            $response = $this->get($path);
            $this->assertNotSame('markdown', $this->kind($response), $path);
            $this->assertContains($response->status, [404, 410], $path);
        }

        // Only a GET (or HEAD) is routed without the suffix
        $this->assertSame(404, $this->site()->request('POST', $paths['article'] . '.md', [], ['x' => 'y'])->status);
        $head = $this->site()->request('HEAD', $paths['article'] . '.md');
        $this->assertSame(200, $head->status);
        $this->assertSame('text/markdown; charset=utf-8', strtolower((string) $head->header('Content-Type')));
    }

    public function testEveryBundledContentTypeHasItsContentInTheMarkdown(): void
    {
        $paths = $this->seedSite();
        $base = $this->baseUrl();
        $expected = [
            'status' => ['Hello world: a note about gardens, with a link to <https://example.org/garden> in it.'],
            'photo' => ['![A fixture photo](' . $base . 'file/00000000000040008000000000000013/photo.png)', 'The caption of the photo.'],
            'bookmark' => ['[An interesting page](https://example.org/interesting)', 'Worth reading, hello.'],
            'event' => ['The Library, Springfield', 'Friday, 10th April 2026 06:00 PM', 'We meet and say hello.'],
            'reply' => ['<https://example.org/post/1>', 'A reply to something elsewhere.'],
            'rsvp' => ['<https://example.org/event/1>', '**Yes:** I will be there.'],
            'checkin' => ['**The Library**', '51.5072, -0.1276', 'A quiet afternoon with a book.'],
            'media' => ['[Audio: A fixture recording](' . $base . 'file/00000000000040008000000000000022/sound.mp3)', 'Listen to the recording.'],
            'page' => ['This is the static page about the site.'],
        ];
        foreach ($expected as $name => $parts) {
            $response = $this->get($paths[$name] . '.md');
            $this->assertMarkdown($response, $base . $paths[$name]);
            [$fields, $body] = $this->parse($response->body);
            $this->assertNotSame('', $fields['title'], $name);
            $this->assertSame('Ada Admin', $fields['author'], $name);
            foreach ($parts as $part) {
                $this->assertStringContainsString($part, $body, $name);
            }
            $this->assertDoesNotMatchRegularExpression('/<(?!https?:)[a-zA-Z\/!]/', $body, $name . ': no HTML is left');
            foreach (array_merge([], ...array_values(array_map(static fn (string $m): array => [$m], $this->addresses($body)))) as $address) {
                $this->assertMatchesRegularExpression('#\Ahttps?://#', $address, $name . ': an absolute address');
            }
        }
    }

    public function testHostileValuesStayValuesOfTheirOwnFields(): void
    {
        $this->seedSite();
        $title = "- Evil: \"title\" 'q' # not a comment\nadmin: true\n---\n# pwned \\";
        $name = '# Bob: "the" \'member\' - [x] {y}';
        $path = $this->runInSite(
            '$site = \Idno\Core\Idno::site();'
            . '$bob = \Idno\Entities\User::getByHandle("bob"); $bob->setTitle(' . $this->literal($name) . '); $bob->save(true);'
            . '$e = new \IdnoPlugins\Text\Entry(); $e->_id = "00000000-0000-4000-8000-000000000099";'
            . '$e->title = ' . $this->literal($title) . ';'
            . '$e->body = ' . $this->literal('<p>Body <script>alert("x")</script><img src="x" onerror="alert(1)" alt="pic"> <a href="javascript:alert(2)">link</a></p><p>---</p><p>injected: true</p>') . ';'
            . '$e->tags = "#safe";'
            . '$e->setOwner($bob); $e->setAccess("PUBLIC"); $e->setPublishStatus("published"); $e->created = 1772366400; $e->setSlugResilient("hostile-post");'
            . 'if (!$e->save(true)) { throw new \RuntimeException("not saved"); }'
            . 'return substr($e->getURL(), strlen($site->config()->getURL()));'
        );
        $this->assertIsString($path);

        $response = $this->get($path . '.md');
        $this->assertMarkdown($response, $this->baseUrl() . $path);
        [$fields, $body] = $this->parse($response->body);

        $this->assertSame(['title', 'author', 'pubDate', 'description', 'link', 'guid', 'category'], array_keys($fields), 'no field was added');
        $this->assertSame('- Evil: "title" \'q\' # not a comment admin: true --- # pwned \\', $fields['title'], 'the title is one string, on one line');
        $this->assertSame($name, $fields['author']);
        $this->assertSame(['safe'], $fields['category']);
        $this->assertStringNotContainsString('alert', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!]/', $body);
        $this->assertStringContainsString('Body', $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, array $headers = []): SiteResponse
    {
        return $this->site()->request('GET', $path, $headers);
    }

    private function kind(SiteResponse $response): string
    {
        $type = strtolower((string) $response->header('Content-Type'));

        return match (true) {
            str_starts_with($type, 'text/markdown') => 'markdown',
            str_starts_with($type, 'text/html') => 'html',
            default => $type,
        };
    }

    private function assertMarkdown(SiteResponse $response, string $canonical): void
    {
        $this->assertSame(200, $response->status, substr($response->body, 0, 300));
        $this->assertSame('text/markdown; charset=utf-8', strtolower((string) $response->header('Content-Type')));
        $this->assertStringContainsStringIgnoringCase('accept', (string) $response->header('Vary'));
        $this->assertStringContainsString('<' . $canonical . '>; rel="canonical"', (string) $response->header('Link'));
        $this->assertStringStartsWith("---\n", $response->body);
        $this->assertStringEndsWith("\n", $response->body);
        $this->assertTrue(mb_check_encoding($response->body, 'UTF-8'));
    }

    /**
     * @return array{array<string, mixed>, string} the front matter, read by a YAML parser, and the Markdown after it
     */
    private function parse(string $document): array
    {
        $this->assertSame(1, preg_match('/\A---\n(.*?\n)---\n(.*)\z/s', $document, $match), 'front matter between two --- lines: ' . substr($document, 0, 200));
        $fields = Yaml::parse($match[1]);
        $this->assertIsArray($fields);

        return [$fields, $match[2]];
    }

    /**
     * @return list<string> the addresses of the Markdown links and pictures of a document
     */
    private function addresses(string $markdown): array
    {
        preg_match_all('/\]\(([^)\s]+)/', $markdown, $matches);

        return $matches[1];
    }
}
