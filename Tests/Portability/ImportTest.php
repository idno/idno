<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Core\Migration;
use Idno\Legacy\LegacyPostWriter;
use Idno\Legacy\LegacySiteFacts;
use Idno\Portability\BloggerImporter;
use Idno\Portability\ImportedMedia;
use Idno\Portability\ImportResult;
use Idno\Portability\ImportMode;
use Idno\Portability\WordPressImporter;
use Idno\Storage\Files\FileStore;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The importers, WordPressImporter and BloggerImporter (the feed importer, which also reads the RSS Idno
 * exports), run in this process as the administrator, on the two hand-written exports in fixtures/ and on
 * small documents of their own. The pictures of a post are fetched through a mock remote client that
 * records its requests and serves a one-pixel PNG, so no test reaches a host. Migration's static methods
 * are checked for the one thing they still are: delegates.
 */
final class ImportTest extends PortabilityTestCase
{
    private const TIDE_POOL = 'Notes from the tide pool — café edition 🌊';

    private const LOW_TIDE = 'Low tide, high hopes';

    private const WORDPRESS_DRAFT = 'Draft: what lives under the jetty';

    private const HERONS = 'Három gém & a mist over the Dráva 🦩';

    private const WEIR = 'The heron on the weir';

    private const DRAFT = 'Notes for the autumn count (unfinished)';

    /** @var list<string> */
    private array $knownUuids = [];

    private bool $libxmlInternalErrors = false;

    /** @var list<string> the requests of the mock remote client, as "GET <url>" */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->libxmlInternalErrors = libxml_use_internal_errors();
        $this->logOn($this->admin());
        $this->requests = [];
        $this->knownUuids = $this->postUuids();
    }

    protected function tearDown(): void
    {
        libxml_clear_errors();
        libxml_use_internal_errors($this->libxmlInternalErrors);
        // Whatever the test imported goes, with the files its bodies point at.
        foreach ($this->importedPosts() as $post) {
            $this->track($post);
            foreach ($this->fileIdsIn((string) $post->body) as $id) {
                $this->removeFileAfterwards($id);
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ building and running an importer

    private function client(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $method . ' ' . $url;

            return new MockResponse((string) base64_decode(self::PNG_BASE64, true), ['response_headers' => ['content-type' => 'image/png']]);
        });
    }

    private function media(?HttpClientInterface $client = null): ImportedMedia
    {
        $client ??= $this->client();
        $facts = new LegacySiteFacts(Idno::site()->config());

        return new ImportedMedia(static fn (): HttpClientInterface => $client, Idno::container()->get(FileStore::class), $facts, Idno::site()->logging());
    }

    /**
     * An importer built as the container builds it, but with the mock remote client; it shares the
     * container's ImportMode, which is what the places that would announce a post ask.
     *
     * @param class-string<WordPressImporter|BloggerImporter> $class
     */
    private function importer(string $class, ?HttpClientInterface $client = null, ?\Idno\Portability\PostWriter $writer = null, ?\Psr\Log\LoggerInterface $logger = null): WordPressImporter|BloggerImporter
    {
        $logger ??= Idno::site()->logging();

        return new $class(
            $this->media($client),
            $writer ?? new LegacyPostWriter(Idno::site()->plugins(), $logger),
            Idno::container()->get(ImportMode::class),
            new LegacySiteFacts(Idno::site()->config()),
            $logger
        );
    }

    /**
     * @return array{posts: int, comments: int, media: int, skipped: int}
     */
    private function wordpress(string $xml, ?HttpClientInterface $client = null): array
    {
        return $this->runImporter($this->importer(WordPressImporter::class, $client), $xml);
    }

    /**
     * @return array{posts: int, comments: int, media: int, skipped: int}
     */
    private function blogger(string $xml, ?HttpClientInterface $client = null): array
    {
        return $this->runImporter($this->importer(BloggerImporter::class, $client), $xml);
    }

    /**
     * @return array{posts: int, comments: int, media: int, skipped: int}
     */
    private function runImporter(WordPressImporter|BloggerImporter $importer, string $xml): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        $this->removeAfterwards($path);
        file_put_contents($path, $xml);

        return $importer->import($path, $this->admin()->getUUID());
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

    public function testTheWordPressFixtureCreatesItsPostsAndNothingElse(): void
    {
        $counts = $this->wordpress(self::fixture('wordpress-export.xml'));

        // The two published posts and the draft; the page and the attachment of the export are not imported.
        $this->assertSame(['posts' => 3, 'comments' => 1, 'media' => 1, 'skipped' => 2], $counts);
        $this->assertSame([self::WORDPRESS_DRAFT, self::LOW_TIDE, self::TIDE_POOL], $this->titlesImported());
        foreach ($this->importedPosts() as $post) {
            $this->assertInstanceOf(Entry::class, $post, 'a titled post is an article');
        }
    }

    public function testAWordPressDraftIsImportedAsADraftAndNeverPublished(): void
    {
        $this->wordpress(self::fixture('wordpress-export.xml'));
        $draft = $this->articleTitled(self::WORDPRESS_DRAFT);

        $this->assertSame('draft', $draft->getPublishStatus());
        $this->assertTrue($draft->isHiddenByPublishStatus($this->user()->getUUID()), 'nobody but its owner (and the administrators) can read it');
        $this->assertStringContainsString('Half a thought about barnacles', $draft->body);
        $this->assertSame($this->admin()->getUUID(), $draft->getOwnerID());
    }

    /**
     * @return array<string, array{string, string, bool, bool}> the status in the file, and whether the post is a draft, whether it is private
     */
    public static function statuses(): array
    {
        return [
            'publish' => ['publish', '', false, false],
            'private' => ['private', '', false, true],
            'draft' => ['draft', '', true, false],
            'pending' => ['pending', '', true, false],
            'future' => ['future', '', true, false],
            'auto-draft' => ['auto-draft', '', true, false],
            'a published post with a password' => ['publish', '<wp:post_password>letmein</wp:post_password>', false, true],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testAWordPressPostIsNeverMorePublicThanItWasAtItsSource(string $status, string $extra, bool $draft, bool $private): void
    {
        $xml = $this->wxr('<item><title>Status ' . $status . '</title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>' . $status . '</wp:status><wp:post_type>post</wp:post_type>' . $extra . '<content:encoded><![CDATA[<p>Body.</p>]]></content:encoded></item>');

        $this->assertSame(1, $this->wordpress($xml)['posts']);
        $post = $this->articleTitled('Status ' . $status);

        $this->assertSame($draft ? 'draft' : 'published', $post->getPublishStatus());
        $this->assertSame($private ? $this->admin()->getUUID() : 'PUBLIC', $post->access, $private ? 'private to the importing user' : 'public');
    }

    public function testAWordPressItemInTheTrashOrOfAnotherTypeIsSkipped(): void
    {
        $item = static fn (string $title, string $status, string $type): string => '<item><title>' . $title . '</title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>' . $status . '</wp:status>' . ($type === '' ? '' : '<wp:post_type>' . $type . '</wp:post_type>') . '<content:encoded><![CDATA[<p>Body.</p>]]></content:encoded></item>';

        $counts = $this->wordpress($this->wxr($item('In the trash', 'trash', 'post') . $item('A page', 'publish', 'page') . $item('A menu entry', 'publish', 'nav_menu_item') . $item('No type at all', 'publish', '') . $item('Kept', 'publish', 'post')));

        $this->assertSame(['posts' => 1, 'comments' => 0, 'media' => 0, 'skipped' => 4], $counts);
        $this->assertSame(['Kept'], $this->titlesImported());
    }

    public function testAWordPressPostKeepsItsBodyDateAndTagsAndBelongsToTheImportingUser(): void
    {
        $this->wordpress(self::fixture('wordpress-export.xml'));
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

    /**
     * A post writer that fails for the item whose turn it is (the second), as a database error would.
     */
    private function writerFailingOnThe(int $nth, string $failure = 'writer'): \Idno\Portability\PostWriter
    {
        $inner = new LegacyPostWriter(Idno::site()->plugins(), Idno::site()->logging());

        return new class($inner, $nth, $failure) implements \Idno\Portability\PostWriter {
            private int $writes = 0;

            public function __construct(private readonly LegacyPostWriter $inner, private readonly int $nth, private readonly string $failure)
            {
            }

            public function available(): bool
            {
                return $this->inner->available();
            }

            public function write(\Idno\Portability\ImportedPost $post, string $ownerUuid): ?string
            {
                if (++$this->writes === $this->nth && $this->failure === 'writer') {
                    throw new \RuntimeException('the database went away: secret detail');
                }

                return $this->inner->write($post, $ownerUuid);
            }

            public function addComments(string $postUuid, array $comments): int
            {
                if ($this->failure === 'comments') {
                    throw new \RuntimeException('comments failed');
                }

                return $this->inner->addComments($postUuid, $comments);
            }
        };
    }

    private function recordingLogger(): \Psr\Log\AbstractLogger
    {
        return new class() extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
    }

    public function testAnItemThatFailsIsSkippedAndLoggedAndTheImportGoesOn(): void
    {
        $long = str_repeat('Long title ', 12) . "\nwith a line break";
        $item = static fn (string $title): string => '<item><title>' . htmlspecialchars($title) . '</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body of ' . 'x' . '.</p>]]></content:encoded></item>';
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title>'
            . $item('First kept') . $item($long) . $item('Third kept') . '</channel></rss>';
        $logger = $this->recordingLogger();

        $counts = $this->runImporter($this->importer(WordPressImporter::class, null, $this->writerFailingOnThe(2), $logger), $xml);

        $this->assertSame(['posts' => 2, 'comments' => 0, 'media' => 0, 'skipped' => 1], $counts);
        $this->assertSame(['First kept', 'Third kept'], array_values(array_filter($this->titlesImported(), static fn (string $t): bool => str_ends_with($t, 'kept'))));
        $line = implode("\n", $logger->lines);
        $this->assertStringContainsString('RuntimeException', $line, 'the exception\'s class');
        $this->assertStringContainsString(substr(str_replace("\n", ' ', $long), 0, 80), $line, 'the title cut to 80 characters');
        $this->assertStringNotContainsString(substr(str_replace("\n", ' ', $long), 0, 90), $line);
        $this->assertStringNotContainsString('secret detail', $line, 'never the exception\'s message');
        $this->assertSame(1, preg_match_all('/\n/', $line) + 1, 'one line, no line break from the title');
    }

    /**
     * A WordPress file of $count published posts.
     */
    private function wordpressFileOf(int $count): string
    {
        $items = '';
        for ($i = 1; $i <= $count; $i++) {
            $items .= '<item><title>Item ' . $i . '</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body ' . $i . '.</p>]]></content:encoded></item>';
        }

        return '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title>' . $items . '</channel></rss>';
    }

    /**
     * A post writer that fails for the writes the callback says, and counts every call.
     *
     * @param \Closure(int): bool $fails given the number of the write (from 1)
     */
    private function countingWriter(\Closure $fails, int &$calls): \Idno\Portability\PostWriter
    {
        $inner = new LegacyPostWriter(Idno::site()->plugins(), Idno::site()->logging());

        return new class($inner, $fails, $calls) implements \Idno\Portability\PostWriter {
            public function __construct(private readonly LegacyPostWriter $inner, private readonly \Closure $fails, private int &$calls)
            {
            }

            public function available(): bool
            {
                return $this->inner->available();
            }

            public function write(\Idno\Portability\ImportedPost $post, string $ownerUuid): ?string
            {
                if (($this->fails)(++$this->calls)) {
                    throw new \RuntimeException('the database is gone');
                }

                return $this->inner->write($post, $ownerUuid);
            }

            public function addComments(string $postUuid, array $comments): int
            {
                return $this->inner->addComments($postUuid, $comments);
            }
        };
    }

    public function testAnImportStopsAfterTwentyFiveItemsInARowFail(): void
    {
        $calls = 0;
        $writer = $this->countingWriter(static fn (int $n): bool => $n > 3, $calls);

        $counts = $this->runImporter($this->importer(WordPressImporter::class, null, $writer, $this->recordingLogger()), $this->wordpressFileOf(60));

        $this->assertSame(3 + ImportResult::MAX_CONSECUTIVE_FAILURES, $calls, 'three imported, then 25 failures, and the other 32 items untouched');
        $this->assertSame(28, $calls);
        $this->assertSame(['posts' => 3, 'comments' => 0, 'media' => 0, 'skipped' => 25, 'stopped' => true], $counts);
    }

    public function testASuccessBetweenFailuresResetsTheCount(): void
    {
        $calls = 0;
        // 24 failures, one success, 24 failures, one success, ...: never 25 in a row
        $writer = $this->countingWriter(static fn (int $n): bool => $n % 25 !== 0, $calls);

        $counts = $this->runImporter($this->importer(WordPressImporter::class, null, $writer, $this->recordingLogger()), $this->wordpressFileOf(100));

        $this->assertSame(100, $calls, 'every item was tried');
        $this->assertArrayNotHasKey('stopped', $counts);
        $this->assertSame(4, $counts['posts']);
        $this->assertSame(96, $counts['skipped']);
    }

    public function testABloggerImportStopsAfterTwentyFiveEntriesInARowFail(): void
    {
        $entries = '';
        for ($i = 1; $i <= 40; $i++) {
            $entries .= '<entry><id>tag:x,1999:post-' . $i . '</id><published>2023-01-01T00:00:00Z</published><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/><title>Post ' . $i . '</title><content type="html">&lt;p&gt;Body&lt;/p&gt;</content></entry>';
        }
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:thr="http://purl.org/syndication/thread/1.0">' . $entries . '</feed>';
        $calls = 0;
        $writer = $this->countingWriter(static fn (int $n): bool => $n > 2, $calls);

        $counts = $this->runImporter($this->importer(BloggerImporter::class, null, $writer, $this->recordingLogger()), $xml);

        $this->assertSame(27, $calls);
        $this->assertSame(2, $counts['posts']);
        $this->assertTrue($counts['stopped']);
    }

    public function testABloggerEntryThatFailsIsSkippedToo(): void
    {
        $entry = static fn (string $n): string => '<entry><id>tag:x,1999:post-' . $n . '</id><published>2023-01-01T00:00:00Z</published><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/><title>Post ' . $n . '</title><content type="html">&lt;p&gt;Body ' . $n . '&lt;/p&gt;</content></entry>';
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:thr="http://purl.org/syndication/thread/1.0">' . $entry('1') . $entry('2') . $entry('3') . '</feed>';

        $counts = $this->runImporter($this->importer(BloggerImporter::class, null, $this->writerFailingOnThe(2), $this->recordingLogger()), $xml);

        $this->assertSame(2, $counts['posts']);
        $this->assertSame(1, $counts['skipped']);
    }

    public function testAPostWhoseCommentsFailAreCountedAndTheCommentsAreNot(): void
    {
        $xml = self::fixture('wordpress-export.xml');

        $counts = $this->runImporter($this->importer(WordPressImporter::class, null, $this->writerFailingOnThe(0, 'comments'), $this->recordingLogger()), $xml);

        $this->assertSame(3, $counts['posts']);
        $this->assertSame(0, $counts['comments']);
    }

    public function testAPictureIsFetchedIntoTheJobsPrivateDirectoryAndNotTheSharedTemporaryOne(): void
    {
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');
        $private = $temporary . '/idno-import-' . bin2hex(random_bytes(16));
        mkdir($private, 0700);
        $this->removeAfterwards($private);
        $media = $this->media()->forDomain('wordpress.com');
        $sharedBefore = glob($temporary . '/import_*') ?: [];

        $path = $media->inDirectory($private)->fetch('https://tidepoolnotes.files.wordpress.com/2024/02/anemone.png');

        $this->assertNotNull($path);
        $this->assertSame(realpath($private), realpath(dirname($path)), 'in the working directory, where a sweep or the job\'s clean-up removes it');
        $this->assertSame($sharedBefore, glob($temporary . '/import_*') ?: [], 'nothing in the shared temporary directory');
        @unlink($path);

        $other = $temporary . '/some-other-directory';
        mkdir($other, 0700);
        $this->removeAfterwards($other);
        $elsewhere = $media->inDirectory($other)->fetch('https://tidepoolnotes.files.wordpress.com/2024/02/anemone.png');
        $this->assertNotNull($elsewhere);
        $this->assertNotSame(realpath($other), realpath(dirname($elsewhere)), 'a directory that is not one of this install\'s working directories is ignored');
        @unlink($elsewhere);
    }

    public function testATagTheBodyAlreadyShowsIsNotAppendedAgain(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title>'
            . '<item><title>Exported here</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type>'
            . '<content:encoded><![CDATA[<p>Text <a href="https://x.example/tag/TidePools" class="p-category" rel="tag">#TidePools</a> and #heronsnest</p>]]></content:encoded>'
            . '<category>tidepools</category><category>Tide Pools</category><category>herons</category><category>fieldnotes</category><category>fieldnotes</category><category>tide</category></item></channel></rss>';

        $this->wordpress($xml);

        $post = $this->articleTitled('Exported here');
        // #tidepools is in the body (another case); #herons is not a whole tag there (#heronsnest); #fieldnotes twice is once;
        // #tide is only the start of a longer tag in the body, and "Tide Pools" is the tag #tidepools again.
        $this->assertSame(1, substr_count(strtolower((string) $post->body), '#tidepools'), 'not repeated');
        $this->assertStringEndsWith('<p>#herons #fieldnotes #tide</p>', $post->body);
    }

    public function testAWordPressPostInTheUncategorizedCategoryGetsNoHashtag(): void
    {
        $this->wordpress(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::LOW_TIDE);

        $this->assertSame(1708796700, (int) $post->created);
        $this->assertSame([], $post->getTags());
        $this->assertStringNotContainsString('#', strip_tags((string) $post->body));
    }

    public function testTheWordPressCommentBecomesAReplyAnnotationOfItsPost(): void
    {
        $this->wordpress(self::fixture('wordpress-export.xml'));
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
        $this->wordpress(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::LOW_TIDE);

        $this->assertSame(
            ['GET https://tidepoolnotes.files.wordpress.com/2024/02/anemone.png?w=640'],
            $this->requests,
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
        $this->assertSame((string) base64_decode(self::PNG_BASE64, true), $file->getBytes());
    }

    public function testTheStoredPictureBelongsToItsPostSoTheFileRouteAppliesThePostsAccess(): void
    {
        $this->wordpress(self::fixture('wordpress-export.xml'));
        $post = $this->articleTitled(self::LOW_TIDE);
        $id = $this->fileIdsIn((string) $post->body)[0];

        $file = \Idno\Entities\File::getByID($id);

        $this->assertSame($post->getUUID(), $file->file['attached_entity'], 'attached_entity names the post');
        $this->assertSame([$id], $post->getAttachedFileIDs());
    }

    public function testAnUntitledShortWordPressPostBecomesAStatusNote(): void
    {
        $xml = $this->wxr('<item><title></title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Just a short note.</p>]]></content:encoded></item>');

        $this->assertSame(1, $this->wordpress($xml)['posts']);

        $posts = $this->importedPosts();
        $this->assertCount(1, $posts);
        $this->assertInstanceOf(Status::class, $posts[0]);
        $this->assertSame('<p>Just a short note.</p>', $posts[0]->body);
        $this->assertSame(1709294400, (int) $posts[0]->created);
    }

    public function testImportingTheSameFileTwiceCreatesEveryPostTwice(): void
    {
        $xml = self::fixture('wordpress-export.xml');
        $this->assertSame(3, $this->wordpress($xml)['posts']);
        $this->assertSame(3, $this->wordpress($xml)['posts']);

        $this->assertSame([self::WORDPRESS_DRAFT, self::WORDPRESS_DRAFT, self::LOW_TIDE, self::LOW_TIDE, self::TIDE_POOL, self::TIDE_POOL], $this->titlesImported(), 'nothing recognises a post that is already here');
    }

    private function wxrComment(string $name, string $date, string $extra = '<wp:comment_approved>1</wp:comment_approved>', string $url = ''): string
    {
        return '<wp:comment><wp:comment_author>' . $name . '</wp:comment_author><wp:comment_author_url>' . $url . '</wp:comment_author_url><wp:comment_date_gmt>' . $date . '</wp:comment_date_gmt><wp:comment_content>Hello from ' . $name . '</wp:comment_content>' . $extra . '</wp:comment>';
    }

    public function testAWordPressFileWithMoreThanOneCommentOnAPostImportsAllOfThem(): void
    {
        $xml = $this->wxr('<item><title>Two comments</title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>' . $this->wxrComment('Ana', '2024-03-02 10:00:00') . $this->wxrComment('Ben', '2024-03-02 11:00:00') . '</item>');

        $this->assertSame(2, $this->wordpress($xml)['comments']);
        $replies = array_values($this->articleTitled('Two comments')->getAnnotations('reply'));

        $this->assertCount(2, $replies);
        $this->assertSame(['Ana', 'Ben'], [$replies[0]['owner_name'], $replies[1]['owner_name']]);
    }

    public function testOnlyApprovedCommentsOfTypeCommentAreImported(): void
    {
        $comments = $this->wxrComment('Approved', '2024-03-02 10:00:00')
            . $this->wxrComment('Typed', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved><wp:comment_type>comment</wp:comment_type>')
            . $this->wxrComment('Pending', '2024-03-02 10:00:00', '<wp:comment_approved>0</wp:comment_approved>')
            . $this->wxrComment('Spam', '2024-03-02 10:00:00', '<wp:comment_approved>spam</wp:comment_approved>')
            . $this->wxrComment('Trash', '2024-03-02 10:00:00', '<wp:comment_approved>trash</wp:comment_approved>')
            . $this->wxrComment('Pingback', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved><wp:comment_type>pingback</wp:comment_type>')
            . $this->wxrComment('Trackback', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved><wp:comment_type>trackback</wp:comment_type>')
            . $this->wxrComment('Unmarked', '2024-03-02 10:00:00', '');
        $xml = $this->wxr('<item><title>Comments</title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>' . $comments . '</item>');

        $this->assertSame(2, $this->wordpress($xml)['comments']);

        $names = array_map(static fn (array $reply): string => $reply['owner_name'], array_values($this->articleTitled('Comments')->getAnnotations('reply')));
        $this->assertSame(['Approved', 'Typed'], $names);
    }

    public function testACommentAuthorsAddressIsKeptOnlyWhenItIsHttpOrHttps(): void
    {
        $comments = $this->wxrComment('Web', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved>', 'https://shoreline.example/ana')
            . $this->wxrComment('Script', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved>', 'javascript:alert(1)')
            . $this->wxrComment('Data', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved>', 'data:text/html,hi')
            . $this->wxrComment('Mail', '2024-03-02 10:00:00', '<wp:comment_approved>1</wp:comment_approved>', 'mailto:ana@example.org');
        $xml = $this->wxr('<item><title>Authors</title><wp:post_date>2024-03-01 12:00:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>' . $comments . '</item>');

        $this->wordpress($xml);

        $urls = [];
        foreach ($this->articleTitled('Authors')->getAnnotations('reply') as $reply) {
            $urls[$reply['owner_name']] = $reply['owner_url'];
        }
        $this->assertSame(['Web' => 'https://shoreline.example/ana', 'Script' => '', 'Data' => '', 'Mail' => ''], $urls);
    }

    // ------------------------------------------------------------------ Blogger

    public function testTheBloggerFixtureCreatesItsPostsWithTheirDatesTagsAndImage(): void
    {
        $counts = $this->blogger(self::fixture('blogger-export.xml'));

        // The two posts, the draft and the page; the settings entry is skipped, and the comment is a reply.
        $this->assertSame(['posts' => 4, 'comments' => 1, 'media' => 1, 'skipped' => 1], $counts);

        $herons = $this->articleTitled(self::HERONS);
        $this->assertSame(1686752460, (int) $herons->created, '2023-06-14T07:21:00-07:00');
        $this->assertStringStartsWith('<p>Three grey herons stood in the shallows at <b>5:40</b>, perfectly still.</p>', $herons->body);
        $this->assertStringContainsString('my grandfather used to say — <a href="https://heronwatch.blogspot.com/p/about.html">more about me</a>.', $herons->body);
        // Blogger's own categories are tags: spaces and non-ASCII characters dropped, case kept.
        $this->assertStringEndsWith('<p>#herons #riverlife #Drva</p>', $herons->body);
        $this->assertSame(['#herons', '#riverlife', '#Drva'], $herons->getTags());
        // The picture of this post is on blogger.googleusercontent.com, which is not a blogspot.com host.
        $this->assertStringContainsString('https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEgmist/s320/mist.jpg', $herons->body);

        $weir = $this->articleTitled(self::WEIR);
        $this->assertSame(1688000700, (int) $weir->created);
        $ids = $this->fileIdsIn((string) $weir->body);
        $this->assertCount(1, $ids, 'the picture on 1.bp.blogspot.com is fetched and replaced');
        $this->assertStringContainsString('src="' . Idno::site()->config()->getURL() . 'file/' . $ids[0] . '"', $weir->body);
        $this->assertSame(['GET https://1.bp.blogspot.com/-QzF7xKj1b2A/ZIm5C0rY6TI/AAAAAAAAB0g/abc/s1600/heron.jpg'], $this->requests);

        foreach ($this->importedPosts() as $post) {
            $this->assertSame($this->admin()->getUUID(), $post->getOwnerID());
            if ($post->title !== self::DRAFT) {
                $this->assertSame('PUBLIC', $post->access);
            }
        }
    }

    public function testTheBloggerSettingsEntryIsSkipped(): void
    {
        $this->blogger(self::fixture('blogger-export.xml'));

        $this->assertNotContains('BLOG_DESCRIPTION', $this->titlesImported());
    }

    public function testABloggerDraftIsImportedAsADraftAndNeverPublished(): void
    {
        $this->blogger(self::fixture('blogger-export.xml'));

        $draft = $this->articleTitled(self::DRAFT);
        $this->assertSame('draft', $draft->getPublishStatus());
        $this->assertTrue($draft->isHiddenByPublishStatus($this->user()->getUUID()), 'nobody but its owner (and the administrators) can read it');
        $this->assertSame('published', $this->articleTitled(self::WEIR)->getPublishStatus(), 'the posts that were live are published');
    }

    public function testABloggerPostThatIsMarkedAsADraftOnlyByTheAtomPublishingProtocolIsADraft(): void
    {
        $entry = '<entry><id>tag:x,1999:post-1</id><published>2023-01-01T00:00:00Z</published><app:control xmlns:app="http://purl.org/atom/app#"><app:draft>yes</app:draft></app:control><title>Marked by app:draft</title><content type="html">&lt;p&gt;Body&lt;/p&gt;</content></entry>'
            . '<entry><id>tag:x,1999:post-2</id><published>2023-01-01T00:00:00Z</published><blogger:status>DRAFT</blogger:status><title>Marked by blogger:status</title><content type="html">&lt;p&gt;Body&lt;/p&gt;</content></entry>'
            . '<entry><id>tag:x,1999:post-3</id><published>2023-01-01T00:00:00Z</published><app:control xmlns:app="http://purl.org/atom/app#"><app:draft>no</app:draft></app:control><title>Published</title><content type="html">&lt;p&gt;Body&lt;/p&gt;</content></entry>'
            . '<entry><id>tag:x,1999:post-4</id><published>2023-01-01T00:00:00Z</published><blogger:status>SOFT_TRASHED</blogger:status><title>In the trash</title><content type="html">&lt;p&gt;Body&lt;/p&gt;</content></entry>';

        $counts = $this->blogger($this->atom($entry));

        $this->assertSame(3, $counts['posts']);
        $this->assertSame('draft', $this->articleTitled('Marked by app:draft')->getPublishStatus());
        $this->assertSame('draft', $this->articleTitled('Marked by blogger:status')->getPublishStatus());
        $this->assertSame('published', $this->articleTitled('Published')->getPublishStatus());
        $this->assertNotContains('In the trash', $this->titlesImported());
    }

    public function testABloggerPageStaysAPostBecauseIdnoHasNoPages(): void
    {
        // The page "About" (kind#page, blogger:type PAGE) becomes an article dated as the page.
        $this->blogger(self::fixture('blogger-export.xml'));

        $page = $this->articleTitled('About');
        $this->assertSame(1673316600, (int) $page->created);
        $this->assertSame('published', $page->getPublishStatus());
    }

    public function testABloggerCommentBecomesAReplyOfItsPost(): void
    {
        $this->blogger(self::fixture('blogger-export.xml'));

        $replies = array_values($this->articleTitled(self::HERONS)->getAnnotations('reply'));
        $this->assertCount(1, $replies);
        $this->assertSame('Zsófia', $replies[0]['owner_name']);
        $this->assertSame('https://www.blogger.com/profile/01234567890123456789', $replies[0]['owner_url']);
        $this->assertStringContainsString('Lovely photo, ¿which lens? 📷', $replies[0]['content']);
        $this->assertSame(strtotime('2023-06-15T03:12:00.000-07:00'), $replies[0]['time']);
        $this->assertNotContains('Lovely photo, ¿which lens?', $this->titlesImported(), 'the comment is not a post');
        $this->assertSame([], $this->articleTitled(self::WEIR)->getAnnotations('reply'));
    }

    public function testABloggerCommentThatComesBeforeItsPostIsStillItsReplyAndOneWhosePostIsMissingIsSkipped(): void
    {
        $comment = static fn (string $ref, string $who): string => '<entry><id>tag:x,1999:comment-' . $who . '</id><published>2023-02-01T00:00:00Z</published><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#comment"/><title>c</title><content type="html">Hello from ' . $who . '</content><thr:in-reply-to ref="' . $ref . '"/><author><name>' . $who . '</name><uri>javascript:alert(1)</uri></author></entry>';
        $post = '<entry><id>tag:x,1999:post-1</id><published>2023-01-01T00:00:00Z</published><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/><title>The post</title><content type="html">&lt;p&gt;Body&lt;/p&gt;</content></entry>';

        $counts = $this->blogger($this->atom($comment('tag:x,1999:post-1', 'Early') . $post . $comment('tag:x,1999:post-1', 'Late') . $comment('tag:x,1999:post-404', 'Orphan')));

        $this->assertSame(['posts' => 1, 'comments' => 2, 'media' => 0, 'skipped' => 1], $counts);
        $replies = array_values($this->articleTitled('The post')->getAnnotations('reply'));
        $this->assertSame(['Early', 'Late'], array_map(static fn (array $reply): string => $reply['owner_name'], $replies));
        $this->assertSame('', $replies[0]['owner_url'], 'an address that is not http or https is not kept');
    }

    // ------------------------------------------------------------------ Idno's own export

    public function testIdnosOwnRssExportCanBeImportedThroughTheFeedImporter(): void
    {
        // The Blogger importer reads an RSS file as well, which is what the feed importer was: the RSS that
        // Idno exports comes back as articles.
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

        $document = $this->exportedFeed(true, false);

        $this->assertTrue(Migration::importFeedXML($document), 'the delegate Migration keeps');

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

    /**
     * @return array<string, array{string}>
     */
    public static function importers(): array
    {
        return ['the WordPress importer' => ['wordpress'], 'the feed importer' => ['feed']];
    }

    /**
     * @dataProvider importers
     */
    public function testAnIdnoExportThatIncludesPrivatePostsAndDraftsImportsThemPrivateAndAsDraftsThroughBothImporters(string $importer): void
    {
        $admin = $this->admin();
        $private = new Status();
        $private->body = 'A private note that must stay private';
        $this->store($private, $admin, $admin->getUUID());
        $draft = new Entry();
        $draft->title = 'A draft that must stay a draft';
        $draft->body = '<p>Not ready.</p>';
        $this->store($draft, $admin, 'PUBLIC', 'draft');
        $public = new Entry();
        $public->title = 'A public article';
        $public->body = '<p>Out in the open.</p>';
        $this->store($public, $admin);
        foreach ([$private, $draft, $public] as $post) {
            $this->knownUuids[] = $post->getUUID();
        }
        $document = $this->exportedFeed(false, true);
        $this->assertStringContainsString('<wp:status>private</wp:status>', $document);
        $this->assertStringContainsString('<wp:status>draft</wp:status>', $document);

        $counts = $importer === 'wordpress' ? $this->wordpress($document) : $this->blogger($document);

        $this->assertGreaterThanOrEqual(3, $counts['posts']);
        $found = [];
        foreach ($this->importedPosts() as $post) {
            foreach (['private note' => 'A private note that must stay private', 'draft' => 'Not ready.', 'public' => 'Out in the open.'] as $name => $text) {
                if (str_contains((string) $post->body, $text)) {
                    $found[$name] = $post;
                }
            }
        }
        $this->assertEqualsCanonicalizing(['private note', 'draft', 'public'], array_keys($found));
        $this->assertSame($admin->getUUID(), $found['private note']->access, 'the private note stays private');
        $this->assertSame('draft', $found['draft']->getPublishStatus(), 'the draft stays a draft');
        $this->assertSame('PUBLIC', $found['public']->access);
        $this->assertSame('published', $found['public']->getPublishStatus());
    }

    /**
     * The RSS or WXR document of the whole site as the administrator, in a process of its own.
     */
    private function exportedFeed(bool $hidePrivate, bool $wxr): string
    {
        $run = $this->probe('rss', ['hide_private' => $hidePrivate, 'wxr' => $wxr, 'login' => $this->admin()->getUUID(), 'uri' => '/admin/export/rss']);
        $this->assertSame(0, $run['exit'], $run['output']);

        return (string) $run['result']['document'];
    }

    // ------------------------------------------------------------------ what an import must not announce

    /**
     * A follower of the importing user whose inbox is a sink of the site itself (the rig's queue is
     * synchronous, so what ActivityPub delivers has been delivered when the import returns).
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

    public function testImportingAFileDeliversNothingToTheFollowersOfTheImportingUser(): void
    {
        $sink = $this->followerSink();

        $this->assertSame(3, $this->wordpress(self::fixture('wordpress-export.xml'))['posts']);
        $this->assertSame(4, $this->blogger(self::fixture('blogger-export.xml'))['posts']);

        $this->assertSame([], $this->delivered($sink), 'an imported post is history, not news');
        $this->assertNotSame([], $this->importedPosts(), 'the posts were imported');
        $this->assertFalse(Idno::importInProgress(), 'and the mode ended with the import');
    }

    public function testTheModeEndsEvenWhenTheImportFailsSoLaterPostsAreAnnouncedAgain(): void
    {
        $sink = $this->followerSink();
        $this->wordpress('not xml at all');
        $this->assertFalse(Idno::importInProgress());

        $note = new Status();
        $note->body = 'A note written after the import';
        $this->store($note, $this->admin());

        $this->assertNotSame([], $this->delivered($sink), 'a post written normally is federated as before');
    }

    public function testNothingIsSyndicatedWhileAnImportRuns(): void
    {
        $mode = Idno::container()->get(ImportMode::class);
        $events = [];
        $armed = true;
        // There is no way to remove a listener: this one records only while the test runs.
        Idno::site()->events()->addListener('syndicate', static function () use (&$events, &$armed): void {
            if ($armed) {
                $events[] = 'syndicate';
            }
        });
        try {
            $note = new Status();
            $note->body = 'Syndicate me';
            $this->store($note, $this->admin());

            $mode->during(function () use ($note, &$events): void {
                $note->syndicate();
                $this->assertSame([], $events, 'nothing is syndicated during an import');
            });

            $note->syndicate();
            $this->assertSame(['syndicate'], $events, 'and it is again afterwards');
        } finally {
            $armed = false;
        }
    }

    public function testAnImportPingsNoHubSendsNoWebmentionAndSyncsNothing(): void
    {
        $events = [];
        $armed = true;
        foreach (['pubsubhubbub/ping', 'standardsitesync/sync'] as $name) {
            // There is no way to remove a listener: these record only while the test runs.
            Idno::site()->events()->addListener($name, static function () use (&$events, &$armed, $name): void {
                if ($armed) {
                    $events[] = $name;
                }
            });
        }
        $siteRequests = [];
        $remoteRequests = [];
        $recorder = static fn (array &$into): MockHttpClient => new MockHttpClient(static function (string $method, string $url) use (&$into): MockResponse {
            $into[] = $method . ' ' . $url;

            return new MockResponse('<html></html>', ['response_headers' => ['Content-Type: text/html']]);
        });
        \Idno\Core\Webservice::useClient($recorder($siteRequests), $recorder($remoteRequests));
        // Standard.site sync is not one of the rig's plugins: its hooks are registered here, and given what the plugin
        // needs to decide to queue a sync (site-wide switch on, an AT Protocol session on the owner; the queueing
        // needs no network). Listeners cannot be removed: with the switch off again they do nothing.
        $config = Idno::site()->config();
        $switch = $config->standardsitesync_enabled ?? null;
        $admin = $this->admin();
        $session = $admin->standardsitesync_session ?? null;
        (new \IdnoPlugins\StandardSiteSync\Main())->registerEventHooks();
        $config->standardsitesync_enabled = true;
        $admin->standardsitesync_session = ['did' => 'did:plc:testadmin', 'access_token' => 'not-a-real-token'];
        $admin->save(true);
        try {
            $this->wordpress(self::fixture('wordpress-export.xml'));
            $this->assertNotSame([], $this->importedPosts(), 'the import made posts');
            $this->assertSame([], $events, 'no hub is told and nothing is queued for a PDS while a post is imported');

            $mode = Idno::container()->get(ImportMode::class);
            $mode->during(function () use (&$siteRequests, &$remoteRequests): void {
                $this->assertTrue(\Idno\Core\Webmention::pingMentions(Idno::site()->config()->getURL() . 'x', '<a href="http://target.example/post">a link</a>'));
                $this->assertSame([], [...$siteRequests, ...$remoteRequests], 'no webmention is sent during an import');
            });

            // The control: outside an import the same post does all three.
            $note = new Status();
            $note->body = 'Written normally';
            $this->store($note, $this->admin());
            $note->publish();
            $this->assertContains('pubsubhubbub/ping', $events, 'a post published normally pings the hub (the queue here is the synchronous one)');
            \Idno\Core\Webmention::pingMentions(Idno::site()->config()->getURL() . 'x', '<a href="http://target.example/post">a link</a>');
            $this->assertNotSame([], [...$siteRequests, ...$remoteRequests], 'and a webmention is looked for outside one');
            $this->assertContains('standardsitesync/sync', $events, 'and a post saved normally is queued for the PDS: the absence during the import is the guard\'s');
        } finally {
            $armed = false;
            $config->standardsitesync_enabled = $switch;
            $admin->standardsitesync_session = $session;
            $admin->save(true);
            \Idno\Core\Webservice::useClient(null);
        }
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
    public function testAnImportThatCreatesNothingReturnsFalse(string $importer, string $xml): void
    {
        $this->assertFalse(Migration::$importer($xml));
        $this->assertSame([], $this->importedPosts());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function filesThatAreNoExport(): array
    {
        return [
            'empty' => [''],
            'text' => ['This is not an export file.'],
            'XML that holds no post' => ['<?xml version="1.0"?><rss version="2.0"><channel><title>Nothing here</title></channel></rss>'],
            'an Atom feed with no entry' => ['<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>x</title></feed>'],
            'a document type' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "b">]><rss><channel><item><title>&a;</title></item></channel></rss>'],
        ];
    }

    /**
     * @dataProvider filesThatAreNoExport
     */
    public function testAnImporterThatFindsNothingReturnsZeroCountsAndCreatesNothing(string $xml): void
    {
        $nothing = ['posts' => 0, 'comments' => 0, 'media' => 0, 'skipped' => 0];

        $this->assertSame($nothing, $this->wordpress($xml));
        $this->assertSame($nothing, $this->blogger($xml));
        $this->assertSame([], $this->importedPosts());
    }

    public function testAnImporterWithNoFileOnDiskReturnsZeroCountsToo(): void
    {
        $importer = $this->importer(WordPressImporter::class);

        $this->assertSame(['posts' => 0, 'comments' => 0, 'media' => 0, 'skipped' => 0], $importer->import(sys_get_temp_dir() . '/idno-portability-missing-' . bin2hex(random_bytes(4)), $this->admin()->getUUID()));
    }

    public function testAnImportLeavesLibxmlsErrorHandlingAsItWas(): void
    {
        libxml_use_internal_errors(false);
        $this->wordpress('not xml');
        $this->wordpress(self::fixture('wordpress-export.xml'));

        $this->assertFalse(libxml_use_internal_errors());
    }

    // ------------------------------------------------------------------ the delegates

    public function testMigrationsImportersAreDelegatesThatReadTheStringAsTheLoggedInUser(): void
    {
        $this->assertTrue(Migration::importWordPressXML(self::fixture('wordpress-export.xml')));
        $this->assertTrue(Migration::importBloggerXML(self::fixture('blogger-export.xml')));

        $this->assertContains(self::TIDE_POOL, $this->titlesImported());
        $this->assertContains(self::HERONS, $this->titlesImported());
        foreach ($this->importedPosts() as $post) {
            $this->assertSame($this->admin()->getUUID(), $post->getOwnerID());
        }
    }

    public function testMigrationsImageDelegateLeavesAnAddressTheSiteClientCannotReach(): void
    {
        // The rig's clients go to no host but the site's own: the address stays, nothing is stored.
        $html = '<p><img src="https://a.wordpress.com/a.png"></p>';

        $this->assertSame($html, Migration::importImagesFromBodyHTML($html, 'wordpress.com'));
    }

    private function atom(string $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:blogger="http://schemas.google.com/blogger/2008" xmlns:thr="http://purl.org/syndication/thread/1.0"><id>tag:x,1999:blog</id><title>Inline</title>' . $entries . '</feed>';
    }

    private function wxr(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Inline</title>' . $items . '</channel></rss>';
    }
}
