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

    /** The address the sources are fetched from. */
    private const SOURCES = 'http://93.184.216.34/';

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
     * @param array<string, string> $headers
     * @param array<string, string>|string|null $form
     */
    private function post(string $case, array|string|null $form, string $viewer = 'visitor', array $headers = []): SiteResponse
    {
        return $this->record($case, 'POST', 'webmention/', $headers, $viewer, $form);
    }

    /** The post's annotations as the record holds them, with the times left out (an annotation's address ends in a random id). */
    private function recordStored(string $case, string $id): void
    {
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
        $this->record('put-visitor', 'PUT', 'webmention/', [], 'visitor', 'source=a&target=b');
        $this->record('delete-visitor', 'DELETE', 'webmention/');

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
        foreach (['mention-feed-xml', 'mention-feed-rss-xml', 'mention-tag-address', 'mention-search-address', 'mention-robots-address'] as $case) {
            $this->assertNotContains($case, $fetched, 'a mention of an address no page takes mentions at does not make the site fetch its source: ' . $case);
        }

        // A mention of each kind, again (the same source again is an update), and a source that has gone
        $target = $this->targets()['mention-post-public'];
        $this->post('kind-like', ['source' => $this->source('kind-like', $target, 'like'), 'target' => $target]);
        $this->post('kind-share', ['source' => $this->source('kind-share', $target, 'share'), 'target' => $target]);
        $this->post('kind-mention', ['source' => $this->source('kind-mention', $target, 'mention'), 'target' => $target]);
        $this->post('post-public-again', ['source' => $this->source('mention-post-public', $target), 'target' => $target]);
        $this->post('post-public-source-gone', ['source' => $this->source('mention-post-public', $target, 'reply', 410, false), 'target' => $target]);

        // Who sends it: the sender's own session is not what the page sees
        $this->post('post-public-admin-cookie', ['source' => $this->source('admin-cookie', $target), 'target' => $target], 'admin');
        $this->post('private-post-owner-cookie', ['source' => $this->source('owner-cookie', $this->targets()['mention-private-post']), 'target' => $this->targets()['mention-private-post']], 'owner');
        $this->post('private-post-admin-cookie', ['source' => $this->source('admin-private', $this->targets()['mention-private-post']), 'target' => $this->targets()['mention-private-post']], 'admin');
        $this->post('members-only-post-member-cookie', ['source' => $this->source('member-cookie', $this->targets()['mention-members-only-post']), 'target' => $this->targets()['mention-members-only-post']], 'member');

        $this->recordStored('stored-bob-public', $postId);
        $this->recordStored('stored-private-post', '00000000000040008000000000000043');

        // The home page of a multi-user site has no page of its own to take a mention, and on a single-user site (the default) the root is its owner's profile
        $root = $base;
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->single_user = false; $config->save(); return true;');
        $this->post('home-multi-user', ['source' => $this->source('home-multi', $root), 'target' => $root]);
        $this->post('content-multi-user', ['source' => $this->source('content-multi', $this->targets()['mention-content-address']), 'target' => $this->targets()['mention-content-address']]);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->single_user = true; $config->save(); return true;');
        $this->post('home-single-user', ['source' => $this->source('home-single', $root), 'target' => $root]);
        $this->post('content-single-user', ['source' => $this->source('content-single', $this->targets()['mention-content-address']), 'target' => $this->targets()['mention-content-address']]);

        // A private site: the endpoint is open to a sender, its page and the pages it is aimed at are the site's
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->walled_garden = true; $config->save(); return true;');
        $this->record('get-visitor-private-site', 'GET', 'webmention/');
        $this->post('post-public-private-site', ['source' => $this->source('walled-post', $target), 'target' => $target]);
        $this->post('missing-fields-private-site', []);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->walled_garden = false; $config->save(); return true;');
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
