<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Migration;
use Idno\Core\Webservice;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The three importers, Migration::importWordPressXML(), importBloggerXML() and importFeedXML() (which
 * the Blogger one is), run in this process as the administrator, on the two hand-written exports in
 * fixtures/ and on small documents of their own.
 *
 * The importers fetch the images of a post with fopen(), outside the site's HTTP client, so no test may
 * let them: RecordingStreamWrapper takes the place of PHP's http, https, ftp and ftps wrappers for the
 * length of each test, records every URL opened and serves a one-pixel PNG.
 *
 * Tests named "testToday..." pin what the code does now where that is a defect (the next change must flip
 * them); each is followed by the test of the wanted behaviour, marked incomplete until it passes.
 */
final class ImportTest extends PortabilityTestCase
{
    private const TIDE_POOL = 'Notes from the tide pool — café edition 🌊';

    private const LOW_TIDE = 'Low tide, high hopes';

    private const HERONS = 'Három gém & a mist over the Dráva 🦩';

    private const DRAFT = 'Notes for the autumn count (unfinished)';

    /** @var list<string> */
    private array $knownUuids = [];

    private bool $libxmlInternalErrors = false;

    protected function setUp(): void
    {
        parent::setUp();
        // The importer turns libxml's internal error handling on and leaves it on: put it back as it was.
        $this->libxmlInternalErrors = libxml_use_internal_errors();
        $this->logOn($this->admin());
        RecordingStreamWrapper::install();
        $this->knownUuids = $this->postUuids();
    }

    protected function tearDown(): void
    {
        libxml_clear_errors();
        libxml_use_internal_errors($this->libxmlInternalErrors);
        RecordingStreamWrapper::restore();
        Webservice::useClient(null);
        // Whatever the test imported goes, with the files its bodies point at.
        foreach ($this->importedPosts() as $post) {
            $this->track($post);
            foreach ($this->fileIdsIn((string) $post->body) as $id) {
                $this->removeFileAfterwards($id);
            }
        }
        parent::tearDown();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /**
     * @return list<string> the UUIDs of every article and note on the site
     */
    private function postUuids(): array
    {
        $uuids = [];
        foreach ([...Entry::get([], [], 5000), ...Status::get([], [], 5000)] as $post) {
            $uuids[] = $post->getUUID();
        }

        return $uuids;
    }

    /**
     * @return list<Entity> the articles and notes made since setUp()
     */
    private function importedPosts(): array
    {
        $posts = [];
        foreach ([...Entry::get([], [], 5000), ...Status::get([], [], 5000)] as $post) {
            if (!in_array($post->getUUID(), $this->knownUuids, true)) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    /**
     * @return list<string>
     */
    private function titlesImported(): array
    {
        $titles = array_map(static fn (Entity $post): string => (string) $post->getTitle(), $this->importedPosts());
        sort($titles);

        return $titles;
    }

    private function articleTitled(string $title): Entry
    {
        foreach ($this->importedPosts() as $post) {
            if ($post instanceof Entry && $post->title === $title) {
                return $post;
            }
        }
        $this->fail('No article titled "' . $title . '" was imported. Imported: ' . implode(' | ', $this->titlesImported()));
    }

    /**
     * @return list<string> the ids of the stored files the HTML points at
     */
    private function fileIdsIn(string $html): array
    {
        preg_match_all('~' . preg_quote(Idno::site()->config()->getURL(), '~') . 'file/([0-9a-f]{32})~', $html, $matches);

        return $matches[1];
    }

    // ------------------------------------------------------------------ WordPress

    public function testTheWordPressFixtureCreatesItsTwoPublishedPostsAndNothingElse(): void
    {
        $this->assertTrue(Migration::importWordPressXML(self::fixture('wordpress-export.xml')));

        // The draft, the page and the attachment of the export are not imported.
        $this->assertSame([self::LOW_TIDE, self::TIDE_POOL], $this->titlesImported());
        foreach ($this->importedPosts() as $post) {
            $this->assertInstanceOf(Entry::class, $post, 'a titled post is an article');
        }
    }

    public function testAWordPressPostKeepsItsBodyDateAndTagsAndBelongsToTheImportingUser(): void
    {
        Migration::importWordPressXML(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::TIDE_POOL);

        $this->assertSame(1707553800, (int) $post->created, 'wp:post_date, read in the site\'s time zone (UTC here)');
        $this->assertStringStartsWith('<p>Low tide at <strong>6:12</strong> this morning, and the pools were <em>full</em> of life.</p>', $post->body);
        $this->assertStringContainsString('<blockquote>“Anemones close up when you look at them too long,” Ana said.</blockquote>', $post->body);
        $this->assertStringContainsString('a flat white at the café', $post->body);
        $this->assertStringContainsString('“estrella de mar” &amp; “sea star”', $post->body);
        $this->assertStringContainsString('<img src="https://example.org/images/pool-overview.jpg" alt="An overview of the pools" />', $post->body, 'an image on another host is left as it is');

        // Categories and tags are one list in the export; each becomes a hashtag in a last paragraph:
        // lower case, and every character that is not an ASCII letter, digit or underscore is dropped.
        $this->assertStringEndsWith('<p>#fieldnotes #tidepools #caf</p>', $post->body);
        $this->assertSame(['#fieldnotes', '#tidepools', '#caf'], $post->getTags());

        $this->assertSame('PUBLIC', $post->access);
        $this->assertSame('published', $post->getPublishStatus());
        $this->assertSame($this->admin()->getUUID(), $post->getOwnerID(), 'the author named in the export (dc:creator) is not read: the importing user owns the post');
    }

    public function testAWordPressPostInTheUncategorizedCategoryGetsNoHashtag(): void
    {
        Migration::importWordPressXML(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::LOW_TIDE);

        $this->assertSame(1708796700, (int) $post->created);
        $this->assertSame([], $post->getTags());
        $this->assertStringNotContainsString('#', strip_tags((string) $post->body));
    }

    public function testTheWordPressCommentBecomesAReplyAnnotationOfItsPost(): void
    {
        Migration::importWordPressXML(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::TIDE_POOL);

        $replies = $post->getAnnotations('reply');
        $this->assertCount(1, $replies);
        $reply = array_values($replies)[0];
        $this->assertSame('Ana Okafor', $reply['owner_name']);
        $this->assertSame('https://shoreline.example/ana', $reply['owner_url']);
        $this->assertSame('Lovely photos — ¡gracias! 🐚 <a href="https://shoreline.example/tides">Tide table</a>', $reply['content']);
        $this->assertSame(1707656722, $reply['time'], 'wp:comment_date_gmt');
        $this->assertSame([], $this->articleTitled(self::LOW_TIDE)->getAnnotations('reply'));
    }

    public function testTheWordPressImageOnAnAllowedHostIsFetchedAndItsAddressReplacedByAFileOfThisSite(): void
    {
        Migration::importWordPressXML(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::LOW_TIDE);

        $this->assertSame(
            ['https://tidepoolnotes.files.wordpress.com/2024/02/anemone.png?w=640'],
            RecordingStreamWrapper::$opened,
            'one fetch: the image on files.wordpress.com; the one on example.org is not fetched'
        );
        $ids = $this->fileIdsIn((string) $post->body);
        $this->assertCount(1, $ids);
        $this->assertStringContainsString('<img src="' . Idno::site()->config()->getURL() . 'file/' . $ids[0] . '" alt="A green anemone" />', $post->body);
        $this->assertStringNotContainsString('wordpress.com', $post->body);

        $file = \Idno\Entities\File::getByID($ids[0]);
        $this->assertNotFalse($file);
        $this->assertSame('image/png', $file->file['mime_type']);
        $this->assertSame('anemone.png', $file->file['filename']);
        $this->assertSame((string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true), $file->getBytes());
    }

    public function testAnUntitledShortWordPressPostBecomesAStatusNote(): void
    {
        $xml = $this->wxr('<item><title></title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Just a short note.</p>]]></content:encoded></item>');

        $this->assertTrue(Migration::importWordPressXML($xml));

        $posts = $this->importedPosts();
        $this->assertCount(1, $posts);
        $this->assertInstanceOf(Status::class, $posts[0]);
        $this->assertSame('<p>Just a short note.</p>', $posts[0]->body);
        $this->assertSame(1709294400, (int) $posts[0]->created);
    }

    public function testImportingTheSameFileTwiceCreatesEveryPostTwice(): void
    {
        $xml = self::fixture('wordpress-export.xml');
        $this->assertTrue(Migration::importWordPressXML($xml));
        $this->assertTrue(Migration::importWordPressXML($xml));

        $this->assertSame([self::LOW_TIDE, self::LOW_TIDE, self::TIDE_POOL, self::TIDE_POOL], $this->titlesImported(), 'nothing recognises a post that is already here');
    }

    public function testAWordPressFileWithMoreThanOneCommentOnAPostImportsAllOfThem(): void
    {
        $comment = static fn (string $name, string $date): string => '<wp:comment><wp:comment_author>' . $name . '</wp:comment_author><wp:comment_author_url></wp:comment_author_url><wp:comment_date_gmt>' . $date . '</wp:comment_date_gmt><wp:comment_content>Hello from ' . $name . '</wp:comment_content></wp:comment>';
        $xml = $this->wxr('<item><title>Two comments</title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>' . $comment('Ana', '2024-03-02 10:00:00') . $comment('Ben', '2024-03-02 11:00:00') . '</item>');

        $this->assertTrue(Migration::importWordPressXML($xml));
        $replies = array_values($this->articleTitled('Two comments')->getAnnotations('reply'));

        $this->assertCount(2, $replies);
        $this->assertSame(['Ana', 'Ben'], [$replies[0]['owner_name'], $replies[1]['owner_name']]);
    }

    // ------------------------------------------------------------------ Blogger

    public function testTheBloggerFixtureCreatesItsTwoPostsWithTheirDatesTagsAndImage(): void
    {
        $this->assertTrue(Migration::importBloggerXML(self::fixture('blogger-export.xml')));

        $herons = $this->articleTitled(self::HERONS);
        $this->assertSame(1686752460, (int) $herons->created, '2023-06-14T07:21:00-07:00');
        $this->assertStringStartsWith('<p>Three grey herons stood in the shallows at <b>5:40</b>, perfectly still.</p>', $herons->body);
        $this->assertStringContainsString('my grandfather used to say — <a href="https://heronwatch.blogspot.com/p/about.html">more about me</a>.', $herons->body);
        // Blogger's own categories are tags: spaces and non-ASCII characters dropped, case kept.
        $this->assertStringEndsWith('<p>#herons #riverlife #Drva</p>', $herons->body);
        $this->assertSame(['#herons', '#riverlife', '#Drva'], $herons->getTags());
        // The picture of this post is on blogger.googleusercontent.com, which is not a blogspot.com host.
        $this->assertStringContainsString('https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEgmist/s320/mist.jpg', $herons->body);

        $weir = $this->articleTitled('The heron on the weir');
        $this->assertSame(1688000700, (int) $weir->created);
        $ids = $this->fileIdsIn((string) $weir->body);
        $this->assertCount(1, $ids, 'the picture on 1.bp.blogspot.com is fetched and replaced');
        $this->assertStringContainsString('src="' . Idno::site()->config()->getURL() . 'file/' . $ids[0] . '"', $weir->body);
        $this->assertSame(['https://1.bp.blogspot.com/-QzF7xKj1b2A/ZIm5C0rY6TI/AAAAAAAAB0g/abc/s1600/heron.jpg'], RecordingStreamWrapper::$opened);

        foreach ($this->importedPosts() as $post) {
            $this->assertSame('PUBLIC', $post->access);
            $this->assertSame($this->admin()->getUUID(), $post->getOwnerID());
        }
    }

    public function testTheBloggerSettingsEntryIsSkipped(): void
    {
        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $this->assertNotContains('BLOG_DESCRIPTION', $this->titlesImported());
    }

    public function testTodayTheBloggerImporterImportsADraftAsAPublicPost(): void
    {
        // importFeedXML() skips an entry only when its Google "kind" is settings, template or comment
        // (Migration.php:314-327); the draft marker (app:control/app:draft, blogger:status DRAFT) is not
        // read. The next change must flip this to the test below.
        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $draft = $this->articleTitled(self::DRAFT);
        $this->assertSame('PUBLIC', $draft->access);
        $this->assertSame('published', $draft->getPublishStatus());
    }

    public function testABloggerDraftIsNotImportedAsAPublishedPost(): void
    {
        $this->markTestIncomplete('importFeedXML() ignores the draft markers of a Blogger entry (app:control/app:draft, blogger:status) and saves it as a public, published article (Migration.php:309-358)');

        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $this->assertNotContains(self::DRAFT, $this->titlesImported());
    }

    public function testTodayTheBloggerImporterImportsAPageAsAPost(): void
    {
        // The page "About" (kind#page, blogger:type PAGE) becomes an article dated as the page.
        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $page = $this->articleTitled('About');
        $this->assertSame(1673316600, (int) $page->created);
    }

    public function testABloggerPageIsNotImportedAsAPost(): void
    {
        $this->markTestIncomplete('importFeedXML() imports every Blogger entry that is not settings, template or comment, so a page (kind#page) becomes an article (Migration.php:314-358)');

        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $this->assertNotContains('About', $this->titlesImported());
    }

    public function testTodayABloggerCommentIsDroppedAndItsPostHasNoAnnotation(): void
    {
        // The comment's kind is "comment", so importFeedXML() skips it and nothing else reads it.
        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $this->assertNotContains('Lovely photo, ¿which lens?', $this->titlesImported());
        $this->assertSame([], $this->articleTitled(self::HERONS)->getAnnotations('reply'));
        $this->assertSame([], $this->articleTitled(self::HERONS)->getAnnotations('comment'));
    }

    public function testABloggerCommentBecomesAnAnnotationOfItsPost(): void
    {
        $this->markTestIncomplete('importFeedXML() skips entries of kind#comment (Migration.php:319-323) and never reads thr:in-reply-to, so Blogger comments are lost');

        Migration::importBloggerXML(self::fixture('blogger-export.xml'));

        $replies = array_values($this->articleTitled(self::HERONS)->getAnnotations('reply'));
        $this->assertCount(1, $replies);
        $this->assertSame('Zsófia', $replies[0]['owner_name']);
        $this->assertStringContainsString('Lovely photo, ¿which lens? 📷', $replies[0]['content']);
    }

    // ------------------------------------------------------------------ Idno's own export (the "Known" import)

    public function testIdnosOwnRssExportCanBeImportedThroughTheFeedImporter(): void
    {
        // The import form offers "Idno" (import_type Known) and the page has no case for it, but the
        // feed importer that Blogger uses reads the RSS that Idno exports. This is what a Known case
        // would call; it is pinned here as it works today.
        $admin = $this->admin();
        $article = new Entry();
        $article->title = 'Round trip: ' . self::TIDE_POOL;
        $article->body = '<p>A body with <em>markup</em>, café and 🌊. #roundtrip</p>';
        $article->created = 1707553800;
        $this->store($article, $admin);
        $this->knownUuids[] = $article->getUUID();
        $note = new Status();
        $note->body = 'A note that has no title of its own';
        $this->store($note, $admin);
        $this->knownUuids[] = $note->getUUID();

        $run = $this->probe('rss', ['hide_private' => true, 'wxr' => false, 'uri' => '/admin/export/rss']);
        $this->assertSame(0, $run['exit'], $run['output']);
        $document = (string) $run['result']['document'];
        $this->removeAfterwards(dirname(dirname((string) $run['result']['file'])));

        $this->assertTrue(Migration::importFeedXML($document));

        $imported = $this->importedPosts();
        $titles = array_map(static fn (Entity $post): string => (string) $post->title, $imported);
        $this->assertContains('Round trip: ' . self::TIDE_POOL, $titles);
        $this->assertContains('A note that has no title of ...', $titles, 'a note comes back as an article titled with the start of its text');
        $this->assertStringContainsString('A note that has no title of its own', $this->articleTitled('A note that has no title of ...')->body);
        foreach ($imported as $post) {
            $this->assertInstanceOf(Entry::class, $post, 'everything comes back as an article');
        }
        $round = $this->articleTitled('Round trip: ' . self::TIDE_POOL);
        $this->assertSame(1707553800, (int) $round->created);
        $this->assertStringContainsString('<em>markup</em>', $round->body);
        $this->assertStringContainsString('#roundtrip', $round->body);
    }

    // ------------------------------------------------------------------ what an import must not announce

    /**
     * A follower of the importing user whose inbox is a sink of the site itself (the rig's queue is
     * synchronous, so what ActivityPub delivers has been delivered when the import returns), and the
     * requests the sink has recorded.
     */
    private function followerSink(): string
    {
        $name = 'portability-' . bin2hex(random_bytes(4));
        $follower = new \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower();
        $follower->user_uuid = $this->admin()->getUUID();
        $follower->actor_uri = 'https://remote.example/users/bob';
        $follower->actor_inbox = Idno::site()->config()->getURL() . '_test/sink/' . $name;
        $this->assertNotFalse($follower->save(true));
        $this->track($follower);

        return $name;
    }

    /**
     * @return list<array<string, mixed>> the requests the sink recorded; it is emptied
     */
    private function delivered(string $sink): array
    {
        $site = new \Tests\Characterisation\HttpSiteClient(Idno::site()->config()->getURL());
        $response = $site->request('GET', '_test/sink/' . $sink . '/log');
        $this->assertSame(200, $response->status);

        return $response->json();
    }

    public function testTodayImportingAFileDeliversEveryPostToTheFollowersOfTheImportingUser(): void
    {
        // The page defines IDNO_NOMENTION, which stops webmentions only; the "saved" hook of the ActivityPub
        // plugin federates each imported post as a new one. The next change must flip this to the test below.
        $sink = $this->followerSink();

        Migration::importWordPressXML(self::fixture('wordpress-export.xml'));

        $requests = $this->delivered($sink);
        $types = array_map(static fn (array $request): ?string => json_decode((string) base64_decode((string) $request['body'], true), true)['type'] ?? null, $requests);
        $this->assertGreaterThanOrEqual(2, count(array_keys($types, 'Create', true)), 'a Create for each of the two posts: ' . json_encode($types));
    }

    public function testImportingAFileDeliversNothingToTheFollowersOfTheImportingUser(): void
    {
        $this->markTestIncomplete('Entity::save() triggers "saved" for each imported post, and the ActivityPub hook (IdnoPlugins/ActivityPub/Main.php) delivers a Create to the follower inboxes: an imported post is history, not news');

        $sink = $this->followerSink();

        Migration::importWordPressXML(self::fixture('wordpress-export.xml'));

        $this->assertSame([], $this->delivered($sink));
        $this->assertNotSame([], $this->importedPosts(), 'the posts were imported');
    }

    // ------------------------------------------------------------------ what a bad file does

    /**
     * @return array<string, array{string, string}>
     */
    public static function documentsWithNoPost(): array
    {
        $none = '<?xml version="1.0"?><rss version="2.0"><channel><title>Nothing here</title></channel></rss>';
        $cases = [];
        foreach (['importWordPressXML', 'importBloggerXML', 'importFeedXML'] as $importer) {
            $cases[$importer . ' with an empty string'] = [$importer, ''];
            $cases[$importer . ' with text that is not XML'] = [$importer, 'This is not an export file.'];
            $cases[$importer . ' with XML that holds no post'] = [$importer, $none];
        }
        // A missing file is not here: an importer is never called without a readable file. The import page
        // is what refuses a request with none (AdminImportWithoutPedanticModeTest, AdminPortabilityPagesTest).

        return $cases;
    }

    /**
     * @dataProvider documentsWithNoPost
     */
    public function testTodayAnImportThatCreatesNothingThrowsATypeError(string $importer, string $xml): void
    {
        // The importers are declared ": bool" and end without a return when nothing was imported
        // (Migration.php:296, 437, 447), so PHP throws a TypeError. It is an \Error, which the import
        // page's catch (\Exception) does not catch. The next change must flip this to the test below.
        $this->expectException(\TypeError::class);

        Migration::$importer($xml);
    }

    /**
     * @dataProvider documentsWithNoPost
     */
    public function testAnImportThatCreatesNothingReturnsFalse(string $importer, string $xml): void
    {
        $this->markTestIncomplete('Migration::importWordPressXML() and importFeedXML() are declared ": bool" and fall off their end when nothing was imported, which is a TypeError (Migration.php:296-365, 447-576)');

        $this->assertFalse(Migration::$importer($xml));
        $this->assertSame([], $this->importedPosts());
    }

    // ------------------------------------------------------------------ the images

    private function bodyWithImages(string ...$sources): string
    {
        return '<p>Pictures</p>' . implode('', array_map(static fn (string $src): string => '<p><img src="' . $src . '"></p>', $sources));
    }

    /**
     * @return list<string> the ids of the files made, removed in tearDown()
     */
    private function adopt(string $html): array
    {
        $ids = $this->fileIdsIn($html);
        foreach ($ids as $id) {
            $this->removeFileAfterwards($id);
        }

        return $ids;
    }

    public function testImagesAreFetchedOnlyFromTheSourceDomainAndSubdomainsAndOnlyWhenTheyAreImageFiles(): void
    {
        $fetched = [
            'https://wordpress.com/a.png',
            'https://tidepoolnotes.files.wordpress.com/b.JPG',
            'http://a.b.wordpress.com/c.jpeg',
            'https://a.wordpress.com/d.webp?w=640&h=480',
            'https://A.WordPress.com/e.gif',
        ];
        $refused = [
            'https://evilwordpress.com/f.png' => 'a host that only ends with the domain',
            'https://wordpress.com.evil.example/g.png' => 'the domain as a prefix of another host',
            'https://a.wordpress.com@evil.example/h.png' => 'the domain as the user name of a URL',
            'https://example.org/i.png' => 'another host',
            'https://a.wordpress.com/j.php' => 'a script',
            'https://a.wordpress.com/k.svg' => 'a type that is not listed',
            'https://a.wordpress.com/no-extension' => 'no extension',
            '/relative/l.png' => 'no host',
            'data:image/png;base64,iVBORw0KGgo=' => 'a data URI',
        ];

        $body = Migration::importImagesFromBodyHTML($this->bodyWithImages(...$fetched, ...array_keys($refused)), 'wordpress.com');
        $ids = $this->adopt($body);

        $this->assertSame($fetched, RecordingStreamWrapper::$opened);
        $this->assertCount(count($fetched), $ids);
        foreach (array_keys($refused) as $src) {
            $this->assertStringContainsString('src="' . $src . '"', $body, 'left as it was: ' . $refused[$src]);
        }
        foreach ($fetched as $src) {
            $this->assertStringNotContainsString('src="' . $src . '"', $body);
        }
    }

    public function testTodayTheSchemeAndPortOfAnImageUrlAreNotRestrictedOnAnAllowedHost(): void
    {
        // Only the host and the extension are checked (Migration.php:377-393): an ftp:// address, or an
        // http one on any port, is opened. The next change must restrict this (http and https, and the
        // remote HTTP client, which refuses private addresses).
        $sources = ['ftp://a.wordpress.com/a.png', 'https://a.wordpress.com:8443/b.png', 'http://a.wordpress.com:25/c.png'];

        $this->adopt(Migration::importImagesFromBodyHTML($this->bodyWithImages(...$sources), 'wordpress.com'));

        $this->assertSame($sources, RecordingStreamWrapper::$opened);
    }

    public function testTodayAnImageIsFetchedOutsideTheSitesHttpClient(): void
    {
        // The site's clients refuse private addresses, bound the size and the time, and are what tests
        // replace; fopen() does none of that. A client given to Webservice sees nothing.
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse((string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true));
        });
        Webservice::useClient($client, $client);

        $this->adopt(Migration::importImagesFromBodyHTML($this->bodyWithImages('https://a.wordpress.com/a.png'), 'wordpress.com'));

        $this->assertSame(['https://a.wordpress.com/a.png'], RecordingStreamWrapper::$opened, 'opened as a PHP stream');
        $this->assertSame([], $requests, 'the HTTP client was not asked');
    }

    public function testAnImageIsFetchedThroughTheRemoteHttpClient(): void
    {
        $this->markTestIncomplete('Migration::importImagesFromBodyHTML() reads the image with fopen($src) (Migration.php:402), not through the remote HTTP client; it sees no private-address refusal, size limit or timeout');

        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return new MockResponse((string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true), ['response_headers' => ['content-type' => 'image/png']]);
        });
        Webservice::useClient($client, $client);

        $body = Migration::importImagesFromBodyHTML($this->bodyWithImages('https://a.wordpress.com/a.png'), 'wordpress.com');
        $this->adopt($body);

        $this->assertSame(['GET https://a.wordpress.com/a.png'], $requests);
        $this->assertSame([], RecordingStreamWrapper::$opened);
    }

    public function testTodayAnImageOverTheSitesDownloadLimitIsFetchedAndStored(): void
    {
        // webservice_max_download (10 MB by default) bounds every other fetch of the site; this one has
        // no limit at all. A 11 MiB body is read whole and stored as a file of the site.
        $limit = (int) (Idno::site()->config()->webservice_max_download ?: 10 * 1024 * 1024);
        RecordingStreamWrapper::$size = $limit + 1024 * 1024;

        $body = Migration::importImagesFromBodyHTML($this->bodyWithImages('https://a.wordpress.com/big.png'), 'wordpress.com');
        $ids = $this->adopt($body);

        $this->assertCount(1, $ids);
        $this->assertSame($limit + 1024 * 1024, (int) \Idno\Entities\File::getByID($ids[0])->getSize());
    }

    public function testAnImageOverTheSitesDownloadLimitIsNotImported(): void
    {
        $this->markTestIncomplete('importImagesFromBodyHTML() copies the whole body of the image to disk with no size limit (Migration.php:402); webservice_max_download is not applied');

        // The remote client serves a body over the limit. A request through it is cut at the limit, which is
        // not an image, so nothing is stored; the address stays as it was.
        $limit = (int) (Idno::site()->config()->webservice_max_download ?: 10 * 1024 * 1024);
        $served = 0;
        $client = new MockHttpClient(function () use ($limit, &$served): MockResponse {
            $served++;

            return new MockResponse((string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true) . str_repeat("\0", $limit + 1024 * 1024));
        });
        Webservice::useClient($client, $client);

        $body = Migration::importImagesFromBodyHTML($this->bodyWithImages('https://a.wordpress.com/big.png'), 'wordpress.com');

        $this->assertSame(1, $served, 'the client was asked');
        $this->assertSame([], $this->adopt($body));
        $this->assertSame([], RecordingStreamWrapper::$opened, 'and nothing was opened as a PHP stream');
        $this->assertStringContainsString('src="https://a.wordpress.com/big.png"', $body);
    }

    public function testOnlyHttpAndHttpsOnTheDefaultPortsOfAnAllowedHostAreFetched(): void
    {
        $this->markTestIncomplete('Only the host and the extension of an image address are checked (Migration.php:377-393): an ftp:// address, or one on any port, is opened');

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true)));
        Webservice::useClient($client, $client);
        $refused = ['ftp://a.wordpress.com/a.png', 'https://a.wordpress.com:8443/b.png', 'http://a.wordpress.com:25/c.png', 'https://user:pass@a.wordpress.com/d.png'];
        $fetched = ['http://a.wordpress.com:80/e.png', 'https://a.wordpress.com:443/f.png', 'http://a.wordpress.com/g.png'];

        $body = Migration::importImagesFromBodyHTML($this->bodyWithImages(...$refused, ...$fetched), 'wordpress.com');
        $this->adopt($body);

        foreach ($refused as $src) {
            $this->assertStringContainsString('src="' . $src . '"', $body, $src);
        }
        foreach ($fetched as $src) {
            $this->assertStringNotContainsString('src="' . $src . '"', $body, $src);
        }
        $this->assertSame([], RecordingStreamWrapper::$opened);
    }

    public function testAnImageAddressWrittenWithAnAmpersandEntityIsRewrittenToo(): void
    {
        $this->markTestIncomplete('importImagesFromBodyHTML() looks for the decoded address (Migration.php:422): an address written with &amp; in the HTML is fetched but never found in the body, so the post keeps pointing at the other site and the file is stored for nothing');

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true)));
        Webservice::useClient($client, $client);

        $body = Migration::importImagesFromBodyHTML('<p><img src="https://a.wordpress.com/p.png?w=640&amp;h=480" alt="x"></p>', 'wordpress.com');
        $ids = $this->adopt($body);

        $this->assertCount(1, $ids);
        $this->assertStringNotContainsString('wordpress.com', $body);
    }

    private function wxr(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Inline</title>' . $items . '</channel></rss>';
    }
}
