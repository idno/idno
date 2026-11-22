<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Queue\AsyncSiteTestCase;

/**
 * The owner of one site exports it as a WordPress file and the owner of another, empty, site imports that file
 * (the whole of it over HTTP: the export page's download, the import page's upload, the queue worker).
 *
 * Site A holds public articles (with a title, HTML, tags and a date of their own), a note, a private article and
 * a draft. The WXR the admin downloads (private posts left out) is imported into site B, which is a second site of
 * the test's own: the test stops site A and starts B on a new, empty database (restartSite()).
 */
final class RoundTripTest extends AsyncSiteTestCase
{
    private Browser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = new Browser($this->site());
    }

    /**
     * Stops the site (and its database) and starts a new one on a new, empty database, as another server would be.
     */
    private function restartSite(): void
    {
        $this->tearDown();
        $this->setUp();
    }

    /**
     * What the round trip keeps of a public post, each checked below (nothing here is assumed):
     *
     *  - KEPT: the title (an untitled note gets its own text as its title); the date, to the second; the
     *    address's path (`/2024/plain-second`: the slug is made again from the title, so it is the same unless
     *    another post has it); the text of the body, in order, with entities decoded and white space folded (the
     *    comparison's one normalisation); the tags, as the hashtags of the text; the comments (content, author's
     *    name, time to the second).
     *  - NOT KEPT, by design or limit of the file:
     *    the author (every imported post, and the posts of members too, belongs to the administrator who
     *    imports: the file names the author by display name and the importer maps none; a comment keeps its
     *    author's name and URL, which is the old site's);
     *    the host of the address and the post's id and UUID (new on the new site);
     *    the type: a note, a photo (and a reply, bookmark, check-in, event or media post) is imported as an
     *    article whose body is its HTML, so none of their own fields (a location, a bookmarked link, an RSVP)
     *    survive as fields;
     *    the body's markup: the file carries the post DRAWN, so the imported body is the old site's markup (a
     *    wrapper `div`, the tag block with links to the old site's tag pages), and the tags are a second time
     *    at its end (testTheTagsOfAPostAreNotRepeatedByTheRoundTrip);
     *    pictures: the imported body still points at the old site's file (the importer fetches pictures of
     *    wordpress.com only), asserted below for the photo;
     *    private posts and drafts: left out of the file by the admin's choice (a draft that IS in a file stays a
     *    draft: the walkthrough);
     *    reactions other than replies (likes, reposts, webmentions): the file has comments only.
     */
    public function testAWordPressFileExportedFromOneSiteIsImportedIntoAnother(): void
    {
        [$before, $after] = $this->roundTrip();
        $expectedTitles = array_map(static fn (array $post): string => $post['title'] !== '' ? $post['title'] : self::normalise($post['body']), $before);
        $this->assertSame($expectedTitles, array_column($after, 'title'), 'every public post, and only those: ' . json_encode($after));
        $this->assertCount(4, $after);
        foreach ($before as $i => $post) {
            $name = $expectedTitles[$i];
            $this->assertSame($post['created'], $after[$i]['created'], $name . ': the date, to the second');
            $this->assertSame('PUBLIC', $after[$i]['access'], $name);
            $this->assertSame('published', $after[$i]['status'], $name);
            $this->assertSame('article', $after[$i]['type'], $name . ': an exported post, a note too, is imported as an article');
            $this->assertSame('bob', $after[$i]['owner'], $name . ': the importer is the author');
            $this->assertSame('alice', $post['owner']);
            $this->assertSame($post['comments'], $after[$i]['comments'], $name . ': the comments');
            $this->assertSame(parse_url($post['url'], PHP_URL_PATH), parse_url($after[$i]['url'], PHP_URL_PATH), $name . ': the address, under the new host');

            // The body: the text is the same, in the same order. What follows it is hashtags only (the tags).
            $sent = self::normalise($post['body']);
            $got = self::normalise($after[$i]['body']);
            $this->assertStringStartsWith($sent, $got, $name . ': the body text, with tags and entities decoded and white space folded');
            $rest = trim(substr($got, strlen($sent)));
            $this->assertMatchesRegularExpression('/^(#\w+\s*)*$/u', $rest, $name . ': nothing but hashtags is added after the text');

            // The tags: the hashtags of the old post are the ones of the new.
            $hashtags = static fn (string $text): array => array_values(array_unique((preg_match_all('/#\w+/u', $text, $m) ? $m[0] : [])));
            $this->assertEqualsCanonicalizing(array_values(array_unique([...$hashtags($sent), ...$hashtags((string) $post['tags'])])), $hashtags($got), $name . ': the tags');
        }
        $photo = array_values(array_filter($after, static fn (array $p): bool => $p['title'] === 'Tide pool picture'))[0];
        $this->assertSame(1, preg_match('~<img[^>]+src="([^"]+)"~', $photo['body'], $image), 'the photo\'s body has its picture');
        $this->assertStringStartsNotWith($this->baseUrl(), $image[1], 'which is still the old site\'s file');
        $this->assertSame([], array_filter($after, static fn (array $p): bool => $p['comments'] !== [] && $p['title'] !== 'Plain second'));
        $this->assertNotSame($before[0]['url'], $after[0]['url'], 'the address is the new site\'s');
        $this->assertStringStartsWith($this->baseUrl(), $after[0]['url']);
        foreach ($after as $post) {
            // By its UUID: the address with a slug of a title with an accent is not served (the next test).
            $this->assertSame(200, $this->browser->get(substr($post['uuid'], strlen($this->baseUrl())))->status, $post['title'] . ' is served publicly at ' . $post['uuid']);
        }
    }

    /**
     * Fills the first site, exports it as WXR, imports the file into a second, empty site.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} the public posts of each site
     */
    private function roundTrip(): array
    {
        $alice = $this->registerUser('alice');
        $posts = [
            ['kind' => 'entry', 'title' => 'Café notes — tides & <pools> 🌊', 'body' => "<p>Low tide at <strong>6:12</strong> &amp; calm.</p>\n<p>A second paragraph with a <a href=\"https://example.org/x?a=1&amp;b=2\">link</a> and #tidepool and #cafe.</p>", 'created' => '2024-02-10 08:30:00', 'tags' => '#tidepool #cafe'],
            ['kind' => 'entry', 'title' => 'Plain second', 'body' => '<p>Just some text, no tags.</p>', 'created' => '2024-03-01 12:00:00', 'tags' => ''],
            ['kind' => 'entry', 'title' => 'Hidden from the export', 'body' => '<p>For members only.</p>', 'created' => '2024-03-02 12:00:00', 'tags' => '', 'access' => 'SITE'],
            ['kind' => 'entry', 'title' => 'An unfinished draft', 'body' => '<p>Not yet.</p>', 'created' => '2024-03-03 12:00:00', 'tags' => '', 'publish_status' => 'draft'],
        ];
        foreach ($posts as $post) {
            $response = $this->browser->submit('entry/edit', 'entry/edit/', [
                'title' => $post['title'], 'body' => $post['body'], 'created' => $post['created'], 'tags' => $post['tags'],
                'access' => $post['access'] ?? 'PUBLIC', 'publish_status' => $post['publish_status'] ?? 'published',
            ], $alice);
            $this->assertSame(302, $response->status, $post['title'] . ': ' . substr($response->body, 0, 500) . $this->serverLog());
        }
        $this->publishStatus($alice, 'A short note with a #tidepool tag');
        $png = (string) base64_decode(\Tests\Portability\PortabilityTestCase::PNG_BASE64, true);
        $photo = $this->browser->upload('photo/edit', 'photo/edit/', ['title' => 'Tide pool picture', 'body' => 'One pixel of a pool', 'created' => '2024-03-04 09:00:00', 'access' => 'PUBLIC'], ['field' => 'photo', 'name' => 'pool.png', 'type' => 'image/png', 'bytes' => $png], $alice);
        $this->assertSame(302, $photo->status, substr($photo->body, 0, 500) . $this->serverLog());
        $plain = array_values(array_filter($this->publicPosts(), static fn (array $p): bool => $p['title'] === 'Plain second'))[0];
        $comment = $this->browser->submit($this->path($plain['url']), 'annotation/post', ['object' => $plain['uuid'], 'body' => 'A comment on the tide report, with <b>no</b> markup & an ampersand', 'type' => 'reply'], $alice);
        $this->assertSame(302, $comment->status, substr($comment->body, 0, 500));

        $before = $this->publicPosts();
        $this->assertCount(4, $before, 'the site has its four public posts: ' . json_encode($before));
        $this->assertCount(1, $before[1]['comments'], 'and the comment');

        $export = $this->browser->submit('admin/export/', 'admin/export/wxr', [], $alice);
        $this->assertSame(200, $export->status, substr($export->body, 0, 500));
        $this->assertStringContainsString('application/xml', (string) $export->header('Content-Type'));
        $wxr = $export->body;
        $this->assertStringContainsString('Plain second', $wxr);
        $this->assertStringNotContainsString('Hidden from the export', $wxr, 'a private post is not in the file');
        $this->assertStringNotContainsString('An unfinished draft', $wxr, 'a draft is not in the file');

        // Site B: new, empty. Its administrator imports the file.
        $this->restartSite();
        $this->browser = new Browser($this->site());
        $bob = $this->registerUser('bob');
        $this->assertSame([], $this->publicPosts(), 'the second site starts empty');
        $upload = $this->browser->upload('admin/import/', 'admin/import/', ['import_type' => 'WordPress'], ['field' => 'import', 'name' => 'export.xml', 'type' => 'text/xml', 'bytes' => $wxr], $bob);
        $this->assertSame(302, $upload->status, $this->serverLog());
        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertSame([], $this->failedEvents());

        $after = $this->publicPosts();

        return [$before, $after];
    }

    /**
     * What the file carries as tags is the post's hashtags, once: the export draws the post (its tag block among the
     * content) and names the tags as categories, and the importer appends a category only when the body does not show
     * that hashtag already (Idno\Portability\WordPressImporter::importItem()).
     */
    public function testTheTagsOfAPostAreNotRepeatedByTheRoundTrip(): void
    {
        [$before, $after] = $this->roundTrip();
        $repeated = [];
        foreach ($before as $i => $post) {
            $sent = self::normalise($post['body']);
            $rest = trim(substr(self::normalise($after[$i]['body']), strlen($sent)));
            foreach (array_count_values(preg_match_all('/#\w+/u', $rest, $m) ? $m[0] : []) as $tag => $count) {
                if ($count > 1) {
                    $repeated[$post['title'] . ' ' . $tag] = $count;
                }
            }
        }
        $this->assertSame([], $repeated);
    }

    /**
     * An article whose title has an accent is served at its address (`/2024/caf%C3%A9-notes-tides`): slugs are stored
     * percent-encoded, the web server hands the router the path decoded, and the route and the lookup accept both
     * (Idno\Common\Entity::getPermalinkRoute() and getBySlug(); Tests/Characterisation/SlugRoutingTest over HTTP).
     */
    public function testAnArticleWithAnAccentedTitleIsServedAtItsAddress(): void
    {
        $alice = $this->registerUser('alice');
        $this->browser->submit('entry/edit', 'entry/edit/', ['title' => 'Café notes', 'body' => '<p>Tides.</p>', 'access' => 'PUBLIC'], $alice);
        $url = (string) $this->runInSite('foreach (\IdnoPlugins\Text\Entry::get([], [], 10) as $e) { return $e->getURL(); } return "";');
        $this->assertStringStartsWith($this->baseUrl(), $url);
        $status = $this->browser->get(substr($url, strlen($this->baseUrl())))->status;
        $this->assertSame(200, $status);
    }

    /**
     * The site's public, published posts of every type, oldest first, as the round trip compares them.
     *
     * @return list<array{title: string, body: string, created: int, owner: string, comments: list<array{content: string, author: string, time: int}>, tags: string, access: string, status: string, url: string, uuid: string, type: string}>
     */
    private function publicPosts(): array
    {
        $rows = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true);' . "\n"
            . '$rows = [];' . "\n"
            . 'foreach ([\IdnoPlugins\Text\Entry::class, \IdnoPlugins\Status\Status::class, \IdnoPlugins\Photo\Photo::class] as $class) { foreach ($class::get([], [], 100) as $e) {' . "\n"
            . '  if ((string) $e->access !== "PUBLIC" || ($e->getPublishStatus() ?: "published") !== "published") { continue; }' . "\n"
            . '  $rows[] = ["title" => (string) $e->title, "body" => (string) $e->body, "created" => (int) $e->created, "owner" => (string) $e->getOwner()->handle, "comments" => array_map(static fn ($c) => ["content" => (string) $c["content"], "author" => (string) $c["owner_name"], "time" => (int) $c["time"]], array_values((array) ($e->getAnnotations("reply") ?: []))), "tags" => is_array($e->tags ?? null) ? implode(" ", $e->tags) : (string) ($e->tags ?? ""), "access" => (string) $e->access, "status" => "published", "url" => $e->getURL(), "uuid" => $e->getUUID(), "type" => ["article", "note", "photo"][array_search($class, [\IdnoPlugins\Text\Entry::class, \IdnoPlugins\Status\Status::class, \IdnoPlugins\Photo\Photo::class])]];' . "\n"
            . '} }' . "\n"
            . 'usort($rows, static fn ($a, $b) => $a["created"] <=> $b["created"]); return $rows;'
        );
        $this->assertIsArray($rows);

        return $rows;
    }

    /**
     * Normalisation the comparison allows (and no other): tags and entities are compared as the text they make,
     * and runs of white space are one space.
     */
    private function path(string $url): string
    {
        $this->assertStringStartsWith($this->baseUrl(), $url);

        return substr($url, strlen($this->baseUrl()));
    }

    private static function normalise(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</p>', "</p>\n", $html)), ENT_QUOTES)));
    }
}
