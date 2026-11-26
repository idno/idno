<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the webmention endpoint (`/webmention/`) answers, recorded from the legacy page over HTTP: its own page, each way a request can be refused, and a
 * mention of each kind of address of the site. The answers are `Tests/Characterisation/fixtures/answers/webmention/`.
 *
 * The sources are real: the site fetches each from `http://93.184.216.34/<name>`, a public address that passes the first check and that the test
 * environment answers from the site's own `/_test/remote/<name>` (`Tests\Fixtures\RemoteDocumentController`, `LocalOnlyHttpClient`'s stand-in), so
 * a mention goes through the endpoint, the fetch and the page it is aimed at, and what the post holds afterwards is read. An accepted mention changes
 * the site, so the class is not shared: the scenario runs on a site of its own, and again on a second one when a capture is verified.
 */
final class WebmentionAnswersTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'webmention';

    /** The cases the endpoint that replaced the legacy one answers differently on purpose, each with why (`EXPECTED` holds what it answers). */
    private const METHOD_WHY = 'a method the endpoint does not take is the legacy router\'s 404 page, as for every page that moved to a controller (the legacy page answered the invalid-token 403 page)';

    private const SOURCE_WHY = 'a source the site may not fetch is source_not_allowed (the legacy endpoint answered it with the self-mention error)';

    private const HOST_WHY = 'a target is the site\'s only on the site\'s own host and port (the legacy endpoint matched the path alone)';

    private const NO_MENTIONS_WHY = 'a page that takes no mention is no target: the answer is target_not_found, before the source is fetched (the legacy endpoint fetched it and answered source_not_supported)';

    private const NOBODY_WHY = 'a mention is received as nobody, whoever sent it: a post only some members may read takes none (the legacy endpoint received it as the sender\'s session)';

    private const NOT_THERE_WHY = 'a target that names nothing a visitor may see is target_not_found before the source is fetched, and a private post, a draft, a deleted post and a missing one are that one answer (the legacy endpoint fetched the source and answered source_not_supported)';

    /** The cases whose source the site must never fetch: every refused kind of target (the sources are named as the cases are). */
    private const NEVER_FETCHED = [
        'mention-post-other-host-name', 'mention-post-other-port', 'mention-post-no-host', 'mention-actor-address', 'mention-webmention-endpoint-address',
        'mention-file-address', 'mention-annotation-list', 'mention-annotation-one', 'mention-feed-xml', 'mention-feed-rss-xml', 'mention-tag-address',
        'mention-search-address', 'mention-robots-address', 'mention-nothing-serves', 'mention-missing-post', 'mention-private-post', 'mention-members-only-post',
        'mention-draft-post', 'mention-deleted-post', 'mention-bookmark-address', 'mention-shortlink-unknown', 'mention-profile-unknown',
    ];

    private const CHANGED = [
        'mention-nothing-serves' => self::NOT_THERE_WHY,
        'mention-bookmark-address' => self::NOT_THERE_WHY,
        'mention-missing-post' => self::NOT_THERE_WHY,
        'mention-private-post' => self::NOT_THERE_WHY,
        'mention-members-only-post' => self::NOT_THERE_WHY,
        'mention-draft-post' => self::NOT_THERE_WHY,
        'mention-deleted-post' => self::NOT_THERE_WHY,
        'mention-profile-unknown' => self::NOT_THERE_WHY,
        'home-multi-user' => 'nothing receives a mention of a multi-user site\'s home page: it is target_not_found before the source is fetched',
        'content-multi-user' => 'nothing receives a mention of a multi-user site\'s home page: it is target_not_found before the source is fetched',
        'source-loopback' => self::SOURCE_WHY,
        'source-ftp' => self::SOURCE_WHY,
        'mention-post-markdown-address' => 'the Markdown form of a post is the post: its address resolves to the permalink (the legacy page answered target_not_found for it after the permalink moved)',
        'mention-post-other-host-name' => self::HOST_WHY,
        'mention-post-other-port' => self::HOST_WHY,
        'mention-post-no-host' => 'a target with no host is no address of the site: the target must be an absolute address (the legacy endpoint matched the path alone)',
        'mention-shortlink' => 'a shortlink takes a mention as the post it names does (the legacy endpoint answered target_not_found for it after the page moved)',
        'mention-shortlink-unknown' => self::NOT_THERE_WHY,
        'mention-actor-address' => self::NO_MENTIONS_WHY,
        'mention-webmention-endpoint-address' => self::NO_MENTIONS_WHY,
        'mention-home-index-php' => 'the front controller\'s own address is the home page (the legacy router had no page for it)',
        'private-post-owner-cookie' => self::NOBODY_WHY,
        'private-post-admin-cookie' => self::NOBODY_WHY,
        'members-only-post-member-cookie' => self::NOBODY_WHY,
        'put-visitor' => self::METHOD_WHY,
        'delete-visitor' => self::METHOD_WHY,
        'stored-bob-public' => 'the mentions the endpoint takes are the ones it resolves to the post: see the cases that differ',
        'stored-private-post' => 'a mention is received as nobody: a post only its owner may read takes none, whoever sends it',
        'target-not-a-url' => 'an address with a space or a control character in it is refused before anything is looked up (unknown_error): the target is the sender\'s',
    ];

    /** What the changed cases answer: status and, for a refusal, its error. @var array<string, array{int, string|null}> */
    private const EXPECTED = [
        'source-loopback' => [400, 'source_not_allowed'],
        'source-ftp' => [400, 'source_not_allowed'],
        'mention-post-markdown-address' => [202, null],
        'mention-post-other-host-name' => [400, 'target_not_found'],
        'mention-post-other-port' => [400, 'target_not_found'],
        'mention-post-no-host' => [400, 'target_not_found'],
        'mention-shortlink' => [202, null],
        'mention-shortlink-unknown' => [400, 'target_not_found'],
        'mention-nothing-serves' => [400, 'target_not_found'],
        'mention-bookmark-address' => [400, 'target_not_found'],
        'mention-missing-post' => [400, 'target_not_found'],
        'mention-private-post' => [400, 'target_not_found'],
        'mention-members-only-post' => [400, 'target_not_found'],
        'mention-draft-post' => [400, 'target_not_found'],
        'mention-deleted-post' => [400, 'target_not_found'],
        'mention-profile-unknown' => [400, 'target_not_found'],
        'home-multi-user' => [400, 'target_not_found'],
        'content-multi-user' => [400, 'target_not_found'],
        'mention-actor-address' => [400, 'target_not_found'],
        'mention-webmention-endpoint-address' => [400, 'target_not_found'],
        'mention-home-index-php' => [202, null],
        'private-post-owner-cookie' => [400, 'target_not_found'],
        'private-post-admin-cookie' => [400, 'target_not_found'],
        'members-only-post-member-cookie' => [400, 'target_not_found'],
        'target-not-a-url' => [400, 'unknown_error'],
    ];

    /** The address the sources are fetched from. */
    private const SOURCES = 'http://93.184.216.34/';

    /** @var list<string> the cases whose answer is not the recording's, in this run */
    private array $differences = [];

    private const KINDS = ['reply' => 'u-in-reply-to', 'like' => 'u-like-of', 'share' => 'u-repost-of', 'mention' => ''];

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    protected function siteEnvironment(): array
    {
        return parent::siteEnvironment() + ['IDNO_TEST_REMOTE_STAND_IN' => '93.184.216.34:80'];
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    /** Stores a source page: it links to `$target` as `$kind` says, and declares itself at its own address. Returns the source's address. */
    private function source(string $name, string $target, string $kind = 'reply', int $status = 200, bool $linksToTarget = true): string
    {
        $address = self::SOURCES . $name;
        $class = self::KINDS[$kind];
        $link = !$linksToTarget ? '<p>No link here.</p>' : ($class === '' ? '<a href="' . $target . '">a post</a>' : '<a class="' . $class . '" href="' . $target . '">a post</a>');
        $html = '<!doctype html><html><head><title>A page on another site</title></head><body><div class="h-entry"><a class="u-url" href="' . $address . '">permalink</a>'
            . '<div class="p-author h-card"><a class="p-name u-url" href="https://remote.example/">Remote Author</a></div>' . $link
            . '<div class="e-content p-name">The text of ' . $name . '</div></div></body></html>';
        $stored = $this->site()->request('PUT', '_test/remote/' . $name . '?status=' . $status, ['Content-Type' => 'text/html'], $html);
        $this->assertSame(204, $stored->status);

        return $address;
    }

    /** @return list<string> the sources the site has fetched since this was last asked */
    private function fetched(): array
    {
        $answer = $this->site()->request('GET', '_test/remote-fetched');
        $this->assertSame(200, $answer->status);

        return $answer->json();
    }

    /**
     * A POST to the endpoint, recorded. The page the endpoint is now has its own lifecycle, which the legacy endpoint (it took the request in its own `post()`)
     * had none of: the `Link` headers that the pages of the site carry (the webmention endpoint, the hub, `self`) `Vary: Accept` and `X-Content-Type-Options: nosniff` are added to every answer,
     * and a refusal is `application/json` (the legacy endpoint sent its JSON as `text/html`). The answer is compared with the recording with those left
     * out, and they are asserted to be there. A case in `CHANGED` is held to what `EXPECTED` says of it.
     *
     * @param array<string, string>|string|null $form
     * @param array<string, string> $headers
     */
    private function post(string $case, array|string|null $form, string $viewer = 'visitor', array $headers = []): SiteResponse
    {
        if (static::captureMode() !== 'compare') {
            return $this->record($case, 'POST', 'webmention/', $headers, $viewer, $form);
        }
        $response = $this->site()->request('POST', 'webmention/', $this->asViewer($viewer, $headers), $form);
        if (isset(self::CHANGED[$case])) {
            $this->assertRecordedAnswer(self::GROUP, $case, $response);
            $this->assertChanged($case, $response);

            return $response;
        }
        $file = self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt';
        $this->assertFileExists($file, $case);
        $recorded = (string) file_get_contents($file);
        $actual = self::withoutThePagesHeaders(self::normalise($response, $this->baseUrl()), $case);
        if (str_starts_with($recorded, 'status 400')) {
            $expected = str_replace("content-type: text/html; charset=UTF-8\n", "content-type: application/json\n", $recorded);
            $this->assertNotSame($recorded, $expected, $case . ': the recording holds the legacy type');
            // (the JSON is written with an apostrophe as \u0027 now, so that nothing a sender wrote is markup)
            [$head, $body] = explode("\n\n", $expected, 2) + [1 => ''];
            $recorded = $head . "\n\n" . str_replace("'", '\u0027', $body);
        }
        if ($recorded !== $actual) {
            $this->differences[] = $case . ': ' . $response->status . ' ' . (json_decode($response->body, true)['error'] ?? '') . ' (recorded ' . strtok($recorded, "\n") . ' ' . (preg_match('/"error":"([a-z_]+)"/', $recorded, $m) === 1 ? $m[1] : '') . ')';
        }

        return $response;
    }

    /** The answer without the headers every page of the site has, which each of them must hold (once). */
    private static function withoutThePagesHeaders(string $answer, string $case): string
    {
        [$head, $body] = explode("\n\n", $answer, 2) + [1 => ''];
        $lines = explode("\n", $head);
        foreach (['link: <{site}/webmention/>; rel="http://webmention.org/"', 'link: <{site}/webmention/>; rel="webmention"', 'link: <https://hub.example/>; rel="hub"', 'link: <{site}/webmention/>; rel="self"', 'vary: Accept', 'x-content-type-options: nosniff'] as $line) {
            self::assertSame(1, count(array_keys($lines, $line, true)), $case . ': ' . $line);
            $lines = array_values(array_diff($lines, [$line]));
        }

        return implode("\n", $lines) . "\n\n" . $body;
    }

    /** A case whose answer is not the legacy endpoint's, on purpose: its status and, for a refusal, its error, with the reason in `CHANGED`. */
    private function assertChanged(string $case, SiteResponse $response): void
    {
        [$status, $error] = self::EXPECTED[$case] ?? throw new \LogicException('No expectation for ' . $case);
        $this->assertSame($status, $response->status, $case);
        if ($error !== null) {
            $this->assertSame($error, json_decode($response->body, true)['error'] ?? null, $case);
            $this->assertStringContainsString('application/json', (string) $response->header('Content-Type'), $case);
        } else {
            $this->assertSame('', $response->body, $case);
        }
    }

    /** @return array<string, array<string, array<string, mixed>>> the post's annotations as the record holds them */
    private function stored(string $id): array
    {
        $stored = $this->runInSite('return \\Idno\\Core\\Idno::container()->get(\\Idno\\Storage\\RecordStore::class)->findById(\'entities\', ' . $this->literal($id) . ')[\'annotations\'] ?? [];');
        $this->assertIsArray($stored);

        return $stored;
    }

    /** The post's annotations as the record holds them, with the times left out (an annotation's address ends in a random id). */
    private function recordStored(string $case, string $id): void
    {
        if (isset(self::CHANGED[$case])) {
            $this->assertRecordedAnswer(self::GROUP, $case, new SiteResponse(200, [], ''));

            return;
        }
        $code = <<<'PHP'
        $record = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById('entities', %ID%);
        $stored = $record['annotations'] ?? null;
        if (is_array($stored)) {
            array_walk_recursive($stored, static function (&$value, $key) { if ($key === 'time') { $value = '{time}'; } });
        }

        return $stored;
        PHP;
        $stored = $this->runInSite(str_replace('%ID%', $this->literal($id), $code));
        $text = (string) preg_replace('~/annotations/[0-9a-f]{32}~', '/annotations/{id}', (string) json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertRecordedAnswer(self::GROUP, $case, new SiteResponse(200, ['Content-Type' => 'text/plain'], $text . "\n"));
    }

    /**
     * The address of each kind of target, by the case's name: an address of the site, spelled as the case says.
     *
     * @return array<string, string>
     */
    private function targets(): array
    {
        $paths = $this->seedPermalinkContent();
        $base = rtrim($this->baseUrl(), '/') . '/';
        $at = static fn (string $path): string => $base . ltrim($path, '/');
        $post = $at($paths['bob-public']);
        $port = (int) parse_url($base, PHP_URL_PORT);

        return [
            'mention-post-public' => $post,
            'mention-post-markdown-address' => $post . '.md',
            'mention-post-fragment' => $post . '#reply-1',
            'mention-post-query' => $post . '?_t=json',
            'mention-post-trailing-slash' => $post . '/',
            'mention-post-other-scheme' => 'https' . substr($post, 4),
            'mention-post-other-host-name' => str_replace('127.0.0.1', 'localhost', $post),
            'mention-post-other-port' => str_replace(':' . $port . '/', ':' . ($port + 1) . '/', $post),
            'mention-post-no-host' => '/' . ltrim($paths['bob-public'], '/'),
            'mention-post-cafe' => $at($paths['cafe']),
            'mention-post-cafe-lower-hex' => (string) preg_replace_callback('/%[0-9A-F]{2}/', static fn (array $m): string => strtolower($m[0]), $at($paths['cafe'])),
            'mention-view-address' => $at('view/' . $paths['ids']['bob-public']),
            'mention-view-note-address' => $at($paths['view-note']),
            'mention-entry-address' => $at('entry/00000000000040008000000000000046/caf-notes'),
            'mention-bookmark-address' => $at('bookmark/00000000000040008000000000000099/x'),
            'mention-private-post' => $at($paths['bob-owner']),
            'mention-members-only-post' => $at($paths['bob-site']),
            'mention-draft-post' => $at($paths['bob-draft']),
            'mention-deleted-post' => $at($paths['deleted']),
            'mention-missing-post' => $at('2026/no-such-slug-here'),
            'mention-nothing-serves' => $at('2000/does-not-exist'),
            'mention-shortlink' => $at('s/bobpub'),
            'mention-shortlink-unknown' => $at('s/nosuchcode'),
            'mention-profile' => $at('profile/bob'),
            'mention-profile-types' => $at('profile/bob/note'),
            'mention-profile-unknown' => $at('profile/nobody'),
            'mention-actor-address' => $at('actor/bob'),
            'mention-static-page-address' => $at('pages/no-such-page'),
            'mention-file-address' => $at('file/00000000000040008000000000000013/photo.png'),
            'mention-annotation-list' => $post . '/annotations/',
            'mention-annotation-one' => $post . '/annotations/' . md5('reply'),
            'mention-feed-xml' => $at('feed.xml'),
            'mention-feed-rss-xml' => $at('rss.xml'),
            'mention-tag-address' => $at('tag/x'),
            'mention-search-address' => $at('search'),
            'mention-robots-address' => $at('robots.txt'),
            'mention-content-address' => $at('content/all'),
            'mention-home-index-php' => $at('index.php'),
            'mention-webmention-endpoint-address' => $at('webmention/'),
        ];
    }

    /** The scenario of the class: run on a fresh site by each of the two tests (a verified capture records every case on two sites). */
    private function scenario(): void
    {
        $paths = $this->seedPermalinkContent();
        $base = rtrim($this->baseUrl(), '/') . '/';
        $this->runInSite('$store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class); $record = $store->findById(\'entities\', \'00000000000040008000000000000041\'); $record[\'shorturl\'] = \'bobpub\'; $store->save(\'entities\', $record); return true;');
        $postId = '00000000000040008000000000000041';

        // The endpoint's own page
        $this->record('get-visitor', 'GET', 'webmention/');
        $this->record('get-admin', 'GET', 'webmention/', [], 'admin');
        $this->record('head-visitor', 'HEAD', 'webmention/');
        $this->record('options-visitor', 'OPTIONS', 'webmention/');
        $this->assertSame(404, $this->record('put-visitor', 'PUT', 'webmention/', [], 'visitor', 'source=a&target=b')->status);
        $this->assertSame(404, $this->record('delete-visitor', 'DELETE', 'webmention/')->status);

        // A request that is missing something
        $this->post('no-fields', []);
        $this->post('only-source', ['source' => 'http://93.184.216.34/x']);
        $this->post('only-target', ['target' => $base]);
        $this->post('empty-fields', ['source' => '', 'target' => '']);
        $this->post('equal-fields', ['source' => $base . 'x', 'target' => $base . 'x']);
        $this->post('multipart', "--b\r\nContent-Disposition: form-data; name=\"source\"\r\n\r\nhttp://93.184.216.34/x\r\n--b\r\nContent-Disposition: form-data; name=\"target\"\r\n\r\n" . $base . "\r\n--b--\r\n", 'visitor', ['Content-Type' => 'multipart/form-data; boundary=b']);

        // A target that is no page of the site
        $post = $this->targets()['mention-post-public'];
        $remote = $this->source('foreign-target', 'https://other.invalid/b');
        $this->post('target-on-another-site', ['source' => $remote, 'target' => 'https://other.invalid/b']);
        $this->post('target-not-a-url', ['source' => $remote, 'target' => 'not a url']);
        $this->post('target-ftp', ['source' => $remote, 'target' => 'ftp://example.org/x']);

        // The source the site may not fetch, one that does not answer, one that does not link
        $this->post('source-loopback', ['source' => 'http://127.0.0.1:9/', 'target' => $post]);
        $this->post('source-ftp', ['source' => 'ftp://93.184.216.34/x', 'target' => $post]);
        $this->post('source-unreachable', ['source' => 'http://93.184.216.35/x', 'target' => $post]);
        $this->post('source-missing-document', ['source' => self::SOURCES . 'no-such-document', 'target' => $post]);
        $this->post('source-no-link', ['source' => $this->source('no-link', $post, 'reply', 200, false), 'target' => $post]);
        // (a local source with the target's path: the self-mention)
        $this->post('source-is-the-target-on-the-other-scheme', ['source' => 'https' . substr($post, 4), 'target' => $post]);

        // A mention of each kind of address
        $this->fetched();
        foreach ($this->targets() as $case => $target) {
            $this->post($case, ['source' => $this->source($case, $target), 'target' => $target]);
        }
        $fetched = $this->fetched();
        foreach (self::NEVER_FETCHED as $case) {
            $this->assertNotContains($case, $fetched, 'a mention of an address that names nothing a visitor may see does not make the site fetch its source: ' . $case);
        }
        $this->assertContains('mention-post-public', $fetched, 'while a mention of a post is fetched');
        // (an alias of the host name is no address of the site either)
        $alias = str_replace('://', '://www.', $base) . ltrim($paths['bob-public'], '/');
        $answer = $this->site()->request('POST', 'webmention/', [], ['source' => $this->source('www-alias', $alias), 'target' => $alias]);
        $this->assertSame([400, 'target_not_found'], [$answer->status, json_decode($answer->body, true)['error']]);
        $this->assertNotContains('www-alias', $this->fetched());

        // A mention of each kind, again (the same source again is an update), and a source that has gone
        $target = $this->targets()['mention-post-public'];
        $this->post('kind-like', ['source' => $this->source('kind-like', $target, 'like'), 'target' => $target]);
        $this->post('kind-share', ['source' => $this->source('kind-share', $target, 'share'), 'target' => $target]);
        $this->post('kind-mention', ['source' => $this->source('kind-mention', $target, 'mention'), 'target' => $target]);
        $this->post('post-public-again', ['source' => $this->source('mention-post-public', $target), 'target' => $target]);
        $this->post('post-public-source-gone', ['source' => $this->source('mention-post-public', $target, 'reply', 410, false), 'target' => $target]);

        // Who sends it: the sender's own session is not what the page sees
        $this->post('post-public-admin-cookie', ['source' => $this->source('admin-cookie', $target), 'target' => $target], 'admin');
        $this->fetched();
        $this->post('private-post-owner-cookie', ['source' => $this->source('owner-cookie', $this->targets()['mention-private-post']), 'target' => $this->targets()['mention-private-post']], 'owner');
        $this->post('private-post-admin-cookie', ['source' => $this->source('admin-private', $this->targets()['mention-private-post']), 'target' => $this->targets()['mention-private-post']], 'admin');
        $this->post('members-only-post-member-cookie', ['source' => $this->source('member-cookie', $this->targets()['mention-members-only-post']), 'target' => $this->targets()['mention-members-only-post']], 'member');

        $this->assertSame([], array_intersect(['owner-cookie', 'admin-private', 'member-cookie'], $this->fetched()), 'nor is the source of a mention of what a visitor may not see, whoever sends it');
        $this->recordStored('stored-bob-public', $postId);
        $this->recordStored('stored-private-post', '00000000000040008000000000000043');
        // (the post's own annotations: the mention of the address that is the post's, spelled other ways, and each kind; none for a source that has gone, and none
        // for a mention of a post, a profile or a shortlink that the site refused)
        $this->assertSame(
            ['reply' => ['mention-post-markdown-address', 'mention-post-fragment', 'mention-post-trailing-slash', 'mention-post-other-scheme', 'mention-view-address', 'mention-shortlink', 'admin-cookie'], 'mention' => ['mention-post-query', 'kind-mention'], 'like' => ['kind-like'], 'share' => ['kind-share']],
            array_map(fn (array $list): array => array_map(static fn (array $annotation): string => substr((string) $annotation['permalink'], strlen(self::SOURCES)), array_values($list)), $this->stored($postId)),
            'what the public post holds'
        );
        $this->assertSame([], $this->stored('00000000000040008000000000000043'), 'a post only its owner may read holds no mention, whoever sent it');

        // The home page of a multi-user site has no page of its own to take a mention, and on a single-user site (the default) the root is its owner's profile
        $root = $base;
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->single_user = false; $config->save(); return true;');
        $this->fetched();
        $this->post('home-multi-user', ['source' => $this->source('home-multi', $root), 'target' => $root]);
        $this->post('content-multi-user', ['source' => $this->source('content-multi', $this->targets()['mention-content-address']), 'target' => $this->targets()['mention-content-address']]);
        $this->assertSame([], $this->fetched(), 'a multi-user site\'s home page takes no mention: nothing is fetched');
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->single_user = true; $config->save(); return true;');
        $this->post('home-single-user', ['source' => $this->source('home-single', $root), 'target' => $root]);
        $this->post('content-single-user', ['source' => $this->source('content-single', $this->targets()['mention-content-address']), 'target' => $this->targets()['mention-content-address']]);

        // A private site: the endpoint is open to a sender, its page and the pages it is aimed at are the site's
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->walled_garden = true; $config->save(); return true;');
        $this->record('get-visitor-private-site', 'GET', 'webmention/');
        $this->post('post-public-private-site', ['source' => $this->source('walled-post', $target), 'target' => $target]);
        $this->post('missing-fields-private-site', []);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->walled_garden = false; $config->save(); return true;');
        $this->assertSame([], $this->differences, 'the answers that are not the recordings');
    }

    /** The scenario on a site of its own. */
    public function testWhatTheEndpointAnswersToEachRequest(): void
    {
        $this->scenario();
    }

    /** The same on another site (the second recording of each case in a verified capture). */
    public function testTheSameRequestsOnAnotherSiteAreAnsweredTheSame(): void
    {
        $this->scenario();
    }
}
