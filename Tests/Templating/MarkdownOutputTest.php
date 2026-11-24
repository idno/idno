<?php

declare(strict_types=1);

namespace Tests\Templating;

use Symfony\Component\Yaml\Yaml;
use Tests\Acceptance\Browser;
use Tests\Characterisation\SiteResponse;

/**
 * Markdown over HTTP on a seeded site. A post's permalink: the three ways to ask for it, the headers, what an Accept header
 * prefers, who may read it, and the front matter of hostile values. Every other page: `?_t=markdown` (or `md`), and what
 * does not ask (`.md`, `format`, the Accept header).
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
            'short template type' => $this->get($paths['article'] . '?_t=md'),
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

    public function testAPostTheViewerMayNotReadIsNeverInTheMarkdown(): void
    {
        $paths = $this->seedSite();
        $ada = $this->loginAsAdmin();
        $bob = (new Browser($this->site()))->login(self::MEMBER, self::PASSWORD);

        foreach (['private' => 'A private note only Ada can read.', 'draft' => 'A draft note, not yet published.'] as $name => $text) {
            foreach (['a visitor' => [], 'another member' => ['Cookie' => $bob]] as $who => $cookie) {
                $html = $this->get($paths[$name], $cookie);
                $this->assertContains($html->status, [403, 404, 410], $name . ' is closed to ' . $who);
                $this->assertSame('html', $this->kind($html));
                foreach ([
                    $this->get($paths[$name] . '.md', $cookie),
                    $this->get($paths[$name] . '?format=markdown', $cookie),
                    $this->get($paths[$name] . '?_t=markdown', $cookie),
                    $this->get($paths[$name] . '?_t=md', $cookie),
                    $this->get($paths[$name], $cookie + ['Accept' => 'text/markdown']),
                ] as $response) {
                    // The same refusal as the HTML permalink's, as a Markdown document that says so and nothing of the post
                    $this->assertSame($html->status, $response->status, $name . ' for ' . $who);
                    $this->assertSame('markdown', $this->kind($response), $name . ' for ' . $who);
                    [$fields, $body] = $this->parse($response->body);
                    $this->assertNotContains('author', array_keys($fields));
                    $this->assertNotContains('pubDate', array_keys($fields));
                    $this->assertNotContains('guid', array_keys($fields));
                    $this->assertStringNotContainsString($text, $response->body, $name . ' for ' . $who);
                    $this->assertStringNotContainsString('.md', (string) ($fields['link'] ?? ''), 'the address of the page, not of its Markdown form');
                    $this->assertStringNotContainsString('Ada', $body);
                    $this->assertStringNotContainsString('rel="canonical"', (string) $response->header('Link'), 'an error has no canonical address');
                    $this->assertStringContainsStringIgnoringCase('accept', (string) $response->header('Vary'));
                }
            }

            $owner = $this->get($paths[$name] . '.md', ['Cookie' => $ada]);
            $this->assertMarkdown($owner, $this->baseUrl() . $paths[$name]);
            $this->assertStringContainsString($text, $owner->body, 'the owner reads the ' . $name);
            // The owner's document is the post, with none of the controls the owner's page has
            $this->assertStringContainsStringIgnoringCase('edit', $this->get($paths[$name], ['Cookie' => $ada])->body, 'the HTML page has the owner\'s controls');
            foreach (['/edit', 'delete', 'Edit', '__bTk', 'annotation', 'Publish'] as $control) {
                $this->assertStringNotContainsString($control, $owner->body, 'the owner\'s ' . $name . ' as Markdown');
            }
            $this->assertSame($text, trim($this->parse($owner->body)[1]), 'the content is the post\'s text and nothing else');
        }
    }

    public function testAPostThatIsNotThereAnswersWithTheStatusOfItsHtmlPermalink(): void
    {
        $paths = $this->seedSite();
        $missing = dirname($paths['status']) . '/no-such-post-at-all';

        $html = $this->get($missing);
        $this->assertContains($html->status, [404, 410]);
        foreach ([$this->get($missing . '.md'), $this->get($missing . '?format=markdown'), $this->get($missing . '?_t=markdown'), $this->get($missing, ['Accept' => 'text/markdown'])] as $response) {
            $this->assertSame($html->status, $response->status);
            $this->assertSame('markdown', $this->kind($response));
            $this->assertStringStartsWith("---\n", $response->body);
            $this->assertStringNotContainsString('rel="canonical"', (string) $response->header('Link'));
        }
    }

    public function testAnyPageAnswersMarkdownWhenTheTemplateTypeIsAskedFor(): void
    {
        $paths = $this->seedSite();
        $base = $this->baseUrl();

        // A list of posts: the page's title and address, then each post under a heading that links to it
        foreach (['?_t=markdown' => $base, '?_t=md' => $base, 'profile/' . self::ADMIN . '?_t=markdown' => $base . 'profile/' . self::ADMIN, 'tag/templates?_t=markdown' => $base . 'tag/templates'] as $path => $address) {
            $response = $this->get($path);
            $this->assertMarkdown($response, $address, false);
            [$fields, $body] = $this->parse($response->body);
            $this->assertSame($address, $fields['link'], $path);
            $this->assertNotSame('', $fields['title'], $path);
            $this->assertStringContainsString('## [A longer article about templates](' . $base . $paths['article'] . ')', $body, $path);
            $this->assertStringContainsString("Templates draw pages. **Hello** again.", $body, $path);
            $this->assertStringContainsString('*Ada Admin, Mon, 02 Mar 2026 12:00:00 +0000*', $body, $path);
            $this->assertStringNotContainsString('A private note only Ada can read.', $body, $path);
            $this->assertStringNotContainsString('A draft note, not yet published.', $body, $path);
            $this->assertDoesNotMatchRegularExpression('/<(?!https?:)[a-zA-Z\/!]/', $body, $path . ': no HTML is left');
        }

        // The list goes on: a link to the older page, which is Markdown too and links back
        [, $home] = $this->parse($this->get('?_t=markdown')->body);
        $this->assertSame(1, preg_match('/\[Older\]\(([^)]+)\)/', $home, $older), $home);
        $this->assertStringNotContainsString('[Newer]', $home);
        $this->assertStringStartsWith($base, $older[1]);
        $second = $this->get(substr(html_entity_decode($older[1]), strlen($base)));
        $this->assertSame('markdown', $this->kind($second));
        $this->assertStringContainsString('An older note, number', $second->body);
        $this->assertStringContainsString('[Newer](', $second->body);

        // The owner's list has what the owner may read
        $ada = $this->loginAsAdmin();
        $this->assertStringContainsString('A private note only Ada can read.', $this->get('?_t=markdown', ['Cookie' => $ada])->body);

        // A page that lists nothing is its title and its body, without the controls of its forms
        $login = $this->get('session/login?_t=markdown');
        $this->assertMarkdown($login, $base . 'session/login', false);
        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!]/', $this->parse($login->body)[1]);
        $this->assertStringNotContainsString('__bTk', $login->body, 'no form token');

        // A page that is not there, and one a visitor may not see, keep their status
        $this->assertSame(404, $this->get('no-such-page?_t=markdown')->status);
        $this->assertSame('markdown', $this->kind($this->get('no-such-page?_t=markdown')));
        $admin = $this->get('admin/?_t=markdown');
        $this->assertSame($this->get('admin/')->status, $admin->status);
        $this->assertStringNotContainsString('rel="canonical"', (string) $admin->header('Link'));
    }

    public function testOnlyAPermalinkAnswersTheSuffixTheFormatAndTheAcceptHeader(): void
    {
        $paths = $this->seedSite();

        // `format` and the Accept header ask a permalink only: another page is what it is without them
        foreach (['', 'profile/' . self::ADMIN, 'content/all/', 'session/login', 'tag/templates'] as $page) {
            $plain = $this->get($page);
            $this->assertSame('html', $this->kind($plain), $page);
            foreach ([$this->get($page . '?format=markdown'), $this->get($page, ['Accept' => 'text/markdown'])] as $response) {
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

        // A mail type is still not a request's to choose
        $this->assertSame('html', $this->kind($this->get('?_t=email-text')));
    }

    /**
     * The older permalink routes take anything after the entity's id (`/entry/<id>/<anything>`), so a path that ends in `.md`
     * is theirs as given: it is the same post, and the suffix asks for its Markdown.
     */
    public function testAnOldStylePermalinkAnswersTheSuffixToo(): void
    {
        $paths = $this->seedSite();
        $base = $this->baseUrl();

        foreach (['entry' => 'article', 'bookmark' => 'bookmark', 'event' => 'event', 'rsvp' => 'rsvp'] as $route => $name) {
            $old = $route . '/' . $paths['ids'][$name] . '/some-old-title';
            $this->assertSame('html', $this->kind($this->get($old)), $old);
            $this->assertSame(200, $this->get($old)->status, $old);

            $response = $this->get($old . '.md');
            $this->assertMarkdown($response, $base . $paths[$name]);
            $this->assertSame($this->get($paths[$name] . '.md')->body, $response->body, $old . '.md is the Markdown of the post');
            $this->assertSame($response->body, $this->get($old . '?_t=md')->body);

            // Not the suffix: another ending, and the suffix in the middle
            $this->assertSame('html', $this->kind($this->get($old . '.markdown')), $old);
            $this->assertSame('html', $this->kind($this->get($route . '/' . $paths['ids'][$name] . '/a.md/b')), $old);
        }

        // A post the visitor may not read, by its old address
        // (the seeded private note's id)
        $private = '00000000-0000-4000-8000-000000000019';
        $refused = $this->get('entry/' . $private . '/x.md');
        $this->assertSame($this->get('entry/' . $private . '/x')->status, $refused->status);
        $this->assertContains($refused->status, [403, 410]);
        $this->assertStringNotContainsString('A private note only Ada can read.', $refused->body);
    }

    public function testAHeadRequestAndANotModifiedAnswerSayTheyVaryByAccept(): void
    {
        $paths = $this->seedSite();

        foreach ([$paths['article'], $paths['article'] . '.md', $paths['page'], $paths['page'] . '.md', dirname($paths['article']) . '/no-such-post'] as $path) {
            $head = $this->site()->request('HEAD', $path);
            $this->assertStringContainsStringIgnoringCase('accept', (string) $head->header('Vary'), 'HEAD ' . $path);
        }

        // A request the permalink answers 304 to (Page::lastModifiedGatekeeper() answers it when the date is NOT after the
        // post's last change, 20 March 2026, which is the wrong way round and not this test's business)
        foreach ([[$paths['article'], []], [$paths['article'] . '.md', []], [$paths['article'], ['Accept' => 'text/markdown']]] as [$path, $headers]) {
            $response = $this->get($path, $headers + ['If-Modified-Since' => 'Thu, 01 Jan 2026 00:00:00 GMT']);
            $this->assertSame(304, $response->status, $path);
            $this->assertSame('', $response->body);
            $this->assertStringContainsStringIgnoringCase('accept', (string) $response->header('Vary'), '304 ' . $path);
        }
    }

    /**
     * The onboarding pages draw a shell of their own (`shell/simple`), in HTML, whatever type is asked for: they are the steps a
     * new member clicks through, and have no Markdown form. The registration and password pages are account pages: a line
     * that says so, and none of their form.
     */
    public function testTheOnboardingPagesStayHtmlAndTheAccountFormsHaveNoMarkdownForm(): void
    {
        $this->seedSite();

        foreach (['begin/', 'begin/register'] as $page) {
            $plain = $this->get($page);
            $asked = $this->get($page . '?_t=markdown');
            $this->assertSame($plain->status, $asked->status, $page);
            if ($asked->status === 200) {
                $this->assertSame('html', $this->kind($asked), $page);
            }
        }

        foreach (['account/register', 'account/password'] as $page) {
            $asked = $this->get($page . '?_t=markdown');
            if ($asked->status !== 200) {
                $this->assertSame($this->get($page)->status, $asked->status, $page);
                continue;
            }
            $this->assertSame('markdown', $this->kind($asked), $page);
            $this->assertStringContainsString('has no Markdown form', $asked->body, $page);
            $this->assertStringNotContainsString('__bTk', $asked->body);
        }
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
     * The settings pages hold secrets in the fields of their forms. As Markdown they are their text only: every stored secret
     * is absent from the document, for the administrator, for a member and for a visitor.
     */
    public function testTheSettingsPagesAsMarkdownHoldNoSecret(): void
    {
        $this->seedSite();
        $this->assertTrue($this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->plugins()->enable('Styles');

        return true;
        PHP));
        $this->assertTrue($this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $config = $site->config();
        $config->config['smtp_host'] = 'smtp.example.org';
        $config->config['smtp_username'] = 'mailer-user-7731';
        $config->config['smtp_password'] = 'mailer-secret-9142';
        $config->webhook_syndication = [['title' => 'Chat room', 'url' => 'https://hooks.example/services/T000/key-hook-8812']];
        $config->styles = ['css' => 'body.custom-sheet-5530 { color: red }'];
        $config->save();
        foreach (['ada' => 'A', 'bob' => 'B'] as $handle => $mark) {
            $user = \Idno\Entities\User::getByHandle($handle);
            $user->apikey = 'api-key-' . $mark . '-3391';
            $user->indieauth_tokens = ['token-secret-' . $mark . '-6620' => ['client_id' => 'https://client.example/', 'redirect_uri' => 'https://client.example/cb', 'scope' => 'create', 'issued_at' => 1773000000]];
            $user->bridgy = ['twitter' => ['status' => 'enabled', 'user' => $handle, 'key' => 'bridgy-key-' . $mark . '-4471']];
            $user->save(true);
        }

        return true;
        PHP));
        $secrets = ['mailer-user-7731', 'mailer-secret-9142', 'key-hook-8812', 'custom-sheet-5530', 'api-key-A-3391', 'api-key-B-3391', 'token-secret-A-6620', 'token-secret-B-6620', 'bridgy-key-A-4471', 'bridgy-key-B-4471'];
        $pages = ['admin/email/', 'account/settings/tools/', 'account/indiepub/', 'account/bridgy/', 'admin/webhooks/', 'admin/styles/', 'account/settings/', 'admin/'];

        $ada = $this->loginAsAdmin();
        $bob = (new Browser($this->site()))->login(self::MEMBER, self::PASSWORD);
        // The form token of each session, from the HTML of the same session: it is a secret too
        $tokens = [];
        foreach (['the administrator' => $ada, 'a member' => $bob] as $who => $cookie) {
            $this->assertSame(1, preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $this->get('account/settings/', ['Cookie' => $cookie])->body, $found), $who . ' has a token in the HTML');
            $tokens[$who] = $found[1];
        }

        // The test is looking at pages that hold the secrets: the HTML of the administrator's has each of these
        foreach (['admin/email/' => 'mailer-secret-9142', 'account/indiepub/' => 'token-secret-A-6620', 'account/bridgy/' => 'bridgy-key-A-4471', 'admin/webhooks/' => 'key-hook-8812', 'admin/styles/' => 'custom-sheet-5530'] as $page => $secret) {
            $this->assertStringContainsString($secret, $this->get($page, ['Cookie' => $ada])->body, $page . ' holds its secret as HTML');
        }

        $drawn = 0;
        foreach (['the administrator' => ['Cookie' => $ada], 'a member' => ['Cookie' => $bob], 'a visitor' => []] as $who => $cookie) {
            foreach ($pages as $page) {
                foreach (['markdown', 'md'] as $type) {
                    $html = $this->get($page, $cookie);
                    $response = $this->get($page . '?_t=' . $type, $cookie);
                    $this->assertSame($html->status, $response->status, $page . ' for ' . $who . ': the status of the HTML page');
                    if ($response->status >= 300 && $response->status < 400) {
                        continue;
                    }
                    $this->assertSame('markdown', $this->kind($response), $page . ' for ' . $who);
                    foreach ($secrets as $secret) {
                        $this->assertStringNotContainsString($secret, $response->body, $page . ' for ' . $who);
                    }
                    $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!]/', $this->parse($response->body)[1], $page . ' for ' . $who . ': no HTML is left');
                    $this->assertStringNotContainsString('__bTk', $response->body, 'no form token');
                    if (isset($tokens[$who])) {
                        $this->assertStringNotContainsString($tokens[$who], $response->body, $page . ' for ' . $who . ': not the session\'s token');
                    }
                    $drawn += $response->status === 200 ? 1 : 0;
                }
            }
        }
        $this->assertGreaterThanOrEqual(2 * count($pages), $drawn, 'the administrator was shown every page');
    }

    /**
     * Which pages are settings pages is decided from how their route was registered, not from the address as written: a
     * doubled slash (the router matches it) and a request seen as https on a site configured for http are still `/admin`.
     */
    public function testAnAdminPageIsASettingsPageWhateverWayItsAddressIsWritten(): void
    {
        $this->seedSite();
        $this->assertTrue($this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->plugins()->enable('Styles');
        $config = $site->config();
        $config->config['smtp_host'] = 'smtp.example.org';
        $config->config['smtp_username'] = 'mailer-user-7731';
        $config->config['smtp_password'] = 'mailer-secret-9142';
        $config->webhook_syndication = [['title' => 'Chat room', 'url' => 'https://hooks.example/services/T000/key-hook-8812']];
        $config->styles = ['css' => 'body.custom-sheet-5530 { color: red }'];
        $config->save();

        return true;
        PHP));
        $ada = $this->loginAsAdmin();
        $secrets = ['mailer-user-7731', 'mailer-secret-9142', 'key-hook-8812', 'custom-sheet-5530'];

        foreach ([
            '//admin/email/?_t=markdown' => [],
            'admin/email/?_t=markdown' => ['X-Forwarded-Proto' => 'https'],
            '//admin/webhooks/?_t=markdown' => [],
            'admin/webhooks/?_t=markdown' => ['X-Forwarded-Proto' => 'https'],
            'admin/styles/?_t=markdown' => ['X-Forwarded-Proto' => 'https'],
            '//admin/styles/?_t=md' => [],
        ] as $path => $headers) {
            $response = $this->get($path, ['Cookie' => $ada] + $headers);

            $this->assertSame(200, $response->status, $path);
            $this->assertSame('markdown', $this->kind($response), $path);
            $this->assertStringContainsString('This page is a settings page and has no Markdown form.', $response->body, $path);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $response->body, $path);
            }
        }
    }

    public function testAnExplicitTemplateTypeWinsOverTheMarkdownSuffix(): void
    {
        $paths = $this->seedSite();

        $json = $this->get($paths['status'] . '.md?_t=json');

        $this->assertStringStartsWith('application/json', (string) $json->header('Content-Type'));
        $this->assertSame('markdown', $this->kind($this->get($paths['status'] . '.md?_t=markdown')));
        $this->assertSame('markdown', $this->kind($this->get($paths['status'] . '.md')));
    }

    public function testAPageThatIsNotAPermalinkDoesNotEchoItsQueryString(): void
    {
        $this->seedSite();

        $response = $this->get('content/all/?utm_x=find-me-secret-7788&_t=markdown');

        $this->assertSame(200, $response->status);
        $this->assertSame($this->baseUrl() . 'content/all/', $this->parse($response->body)[0]['link'], 'the front matter\'s link has no query string');
        $this->assertSame(1, preg_match('/<([^>]*)>; rel="canonical"/', (string) $response->header('Link'), $canonical));
        $this->assertStringNotContainsString('find-me-secret-7788', $canonical[1], 'the canonical link');
        $this->assertStringContainsString('<' . $this->baseUrl() . 'content/all/>; rel="canonical"', (string) $response->header('Link'));
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

    private function assertMarkdown(SiteResponse $response, string $canonical, bool $variesByAccept = true): void
    {
        $this->assertSame(200, $response->status, substr($response->body, 0, 300));
        $this->assertSame('text/markdown; charset=utf-8', strtolower((string) $response->header('Content-Type')));
        if ($variesByAccept) {
            $this->assertStringContainsStringIgnoringCase('accept', (string) $response->header('Vary'));
        }
        $this->assertSame('nosniff', strtolower((string) $response->header('X-Content-Type-Options')));
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
