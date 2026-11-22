<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Entities\File;
use Idno\Entities\User;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;

/**
 * The RSS and WXR exports, Migration::getExportRSS(), what the admin and account export pages call.
 * They run in a subprocess (probe.php, operation rss), as a web request would run them: the RSS shell
 * template sends a header, which PHPUnit's own output makes impossible in this process.
 *
 * The site holds, for the whole class: by the administrator a public article with an attachment, a
 * tag and a reply, a public untitled note, a private note and a draft; by the member a public and a
 * private article. They are made once and removed in tearDownAfterClass().
 */
final class FeedExportTest extends PortabilityTestCase
{
    private const TITLE = 'Tide pools — café edition 🌊';

    private const CREATED = 1707553800; // 2024-02-10 08:30:00 UTC

    /** @var array<string, Entity> */
    private static array $posts = [];

    private static string $fileId = '';

    private static string $pngBytes = '';

    /** @var array<string, array<string, mixed>> */
    private static array $runs = [];

    /** @var list<string> */
    private static array $leftBehind = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$posts === []) {
            $this->makeSite();
        }
    }

    public static function tearDownAfterClass(): void
    {
        $admin = static::$testAdmin;
        if ($admin instanceof User) {
            Idno::site()->session()->logUserOff();
            Idno::site()->session()->logUserOn($admin);
            foreach (self::$posts as $post) {
                Entity::invalidateCache($post->getUUID());
                $fresh = Entity::getByUUID($post->getUUID());
                if ($fresh instanceof Entity) {
                    $fresh->delete();
                }
            }
            if (self::$fileId !== '') {
                $file = File::getByID(self::$fileId);
                if (is_object($file)) {
                    $file->remove();
                }
            }
            Idno::site()->session()->logUserOff();
        }
        foreach (self::$leftBehind as $directory) {
            self::removeDirectory($directory);
        }
        self::$posts = [];
        self::$fileId = '';
        self::$runs = [];
        self::$parsed = [];
        self::$leftBehind = [];
        parent::tearDownAfterClass();
    }

    private function makeSite(): void
    {
        $admin = $this->admin();
        $member = $this->user();
        $this->logOn($admin);

        self::$pngBytes = (string) base64_decode(RecordingStreamWrapper::PNG_BASE64, true);
        self::$fileId = $this->storeFile(self::$pngBytes, 'pool.png', 'image/png');

        $article = new Entry();
        $article->title = self::TITLE;
        $article->body = '<p>Low tide at <strong>6:12</strong>, and a flat white at the café. #tidepools #fieldnotes</p>';
        $article->created = self::CREATED;
        $article->attachFile(File::getByID(self::$fileId));
        self::$posts['article'] = $this->keep($this->store($article, $admin));
        $article->addAnnotation('reply', 'Ana Okafor', 'https://shoreline.example/ana', '', 'Lovely photos — ¡gracias! 🐚', null, 1707656722, null, [], false);
        $article->save(true);

        $note = new Status();
        $note->body = 'Three grey herons stood in the shallows this morning #herons';
        self::$posts['note'] = $this->keep($this->store($note, $admin));

        $private = new Status();
        $private->body = 'The private note about the secret rock';
        self::$posts['private'] = $this->keep($this->store($private, $admin, $admin->getUUID()));

        $draft = new Entry();
        $draft->title = 'A draft nobody should read yet';
        $draft->body = '<p>Not ready.</p>';
        self::$posts['draft'] = $this->keep($this->store($draft, $admin, 'PUBLIC', 'draft'));

        $memberPublic = new Entry();
        $memberPublic->title = 'The member\'s public article';
        $memberPublic->body = '<p>Written by the member.</p>';
        self::$posts['memberPublic'] = $this->keep($this->store($memberPublic, $member));

        $memberPrivate = new Entry();
        $memberPrivate->title = 'The member\'s private article';
        $memberPrivate->body = '<p>Only the member should have this.</p>';
        self::$posts['memberPrivate'] = $this->keep($this->store($memberPrivate, $member, $member->getUUID()));
    }

    /**
     * The entities of this class outlive a test: they are removed in tearDownAfterClass(), not in the
     * test's tearDown().
     */
    private function keep(Entity $entity): Entity
    {
        $this->forget($entity);

        return $entity;
    }

    /**
     * Runs Migration::getExportRSS() in a subprocess and returns the document, once per distinct run.
     *
     * @return array{document: string, file: string}
     */
    private function export(?User $loggedOn, bool $hidePrivate, bool $wxr, ?User $forUser = null): array
    {
        $arguments = [
            'login' => $loggedOn?->getUUID(),
            'hide_private' => $hidePrivate,
            'wxr' => $wxr,
            'user_uuid' => $forUser?->getUUID() ?? '',
            'uri' => '/admin/export/' . ($wxr ? 'wxr' : 'rss'),
        ];
        $key = json_encode($arguments, JSON_THROW_ON_ERROR);
        if (!isset(self::$runs[$key])) {
            $run = $this->probe('rss', $arguments);
            $this->assertSame(0, $run['exit'], $run['output']);
            $this->assertIsArray($run['result'], $run['output']);
            $this->assertIsString($run['result']['document'], 'the export returned a document: ' . $run['output']);
            self::$runs[$key] = $run['result'];
            self::$leftBehind[] = dirname(dirname((string) $run['result']['file']));
        }

        return self::$runs[$key];
    }

    /** @var array<string, array{0: \DOMXPath, 1: array<string, \DOMElement>}> */
    private static array $parsed = [];

    /**
     * @return array{0: \DOMXPath, 1: array<string, \DOMElement>} the document's XPath, and its items by their guid
     */
    private function parse(string $document): array
    {
        $key = md5($document);
        if (!isset(self::$parsed[$key])) {
            $xpath = new \DOMXPath($this->assertWellFormedXml($document));
            $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
            $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
            $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
            $items = [];
            foreach ($xpath->query('//channel/item') ?: [] as $item) {
                $items[$xpath->evaluate('string(guid)', $item)] = $item;
            }
            self::$parsed[$key] = [$xpath, $items];
        }

        return self::$parsed[$key];
    }

    /**
     * @return array<string, \DOMElement> the items of the document by their guid
     */
    private function itemsOf(string $document): array
    {
        return $this->parse($document)[1];
    }

    private function xpathOf(string $document): \DOMXPath
    {
        return $this->parse($document)[0];
    }

    private function uuid(string $post): string
    {
        return self::$posts[$post]->getUUID();
    }

    /**
     * @param list<string> $expected the posts, by their name in this test
     */
    private function assertItems(array $expected, string $document): void
    {
        $items = $this->itemsOf($document);
        $wanted = array_map(fn (string $post): string => $this->uuid($post), $expected);
        // Posts of other tests of the suite are not this test's business: only the ones made here count.
        $ours = array_map(fn (Entity $post): string => $post->getUUID(), self::$posts);
        $found = array_values(array_intersect(array_keys($items), $ours));
        sort($found);
        sort($wanted);
        $this->assertSame($wanted, $found);
    }

    // ------------------------------------------------------------------ the RSS document

    public function testTheRssHoldsThePublicPostsOfEveryoneAndNotThePrivateOnesOrTheDraft(): void
    {
        $run = $this->export(null, true, false);

        $this->assertItems(['article', 'note', 'memberPublic'], $run['document']);
    }

    public function testTheRssChannelDescribesTheSite(): void
    {
        $document = $this->export(null, true, false)['document'];
        $xpath = $this->xpathOf($document);

        $this->assertSame(Idno::site()->config()->getTitle(), $xpath->evaluate('string(//channel/title)'));
        $this->assertSame(Idno::site()->config()->getDisplayURL(), $xpath->evaluate('string(//channel/link)'));
        $this->assertSame('en', $xpath->evaluate('string(//channel/language)'));
        $this->assertSame('Idno https://idno.co', $xpath->evaluate('string(//channel/generator)'));
        $this->assertSame('2.0', $xpath->evaluate('string(/rss/@version)'));
        $this->assertSame(1, substr_count($document, '##KNOWNFEEDITEMS##'), 'the marker the items are mixed in at stays in the document');
        $this->assertSame(0.0, $xpath->evaluate('count(//channel/wp:wxr_version)'), 'a plain RSS export is not a WXR one');
    }

    public function testAnRssItemCarriesTheTitleLinkGuidDateAuthorBodyTagsAndAttachment(): void
    {
        $document = $this->export(null, true, false)['document'];
        $item = $this->itemsOf($document)[$this->uuid('article')];
        $xpath = $this->xpathOf($document);

        $this->assertSame(self::TITLE, $xpath->evaluate('string(title)', $item));
        $this->assertSame(self::$posts['article']->getSyndicationURL(), $xpath->evaluate('string(link)', $item));
        $this->assertSame('Sat, 10 Feb 2024 08:30:00 +0000', $xpath->evaluate('string(pubDate)', $item));
        $this->assertSame('Test Admin User', $xpath->evaluate('string(dc:creator)', $item));
        $this->assertSame('post', $xpath->evaluate('string(wp:post_type)', $item));
        $this->assertSame('publish', $xpath->evaluate('string(wp:status)', $item));

        $description = $xpath->evaluate('string(description)', $item);
        $this->assertStringContainsString('<strong>6:12</strong>', $description, 'the body is the drawn HTML, in a CDATA section');
        $this->assertStringContainsString('café', $description);
        $this->assertStringContainsString('e-content', $description, 'it carries its microformats class');
        $this->assertStringContainsString('<![CDATA[', $document);

        $this->assertSame(['#tidepools', '#fieldnotes'], array_map(
            static fn (\DOMNode $node): string => (string) $node->textContent,
            iterator_to_array($xpath->query('category', $item) ?: [])
        ));

        $enclosures = $xpath->query('enclosure', $item);
        $this->assertSame(1, $enclosures->length);
        $this->assertSame(Idno::site()->config()->getDisplayURL() . 'file/' . self::$fileId . '/pool.png', $enclosures->item(0)->getAttribute('url'));
        $this->assertSame('image/png', $enclosures->item(0)->getAttribute('type'));
        $this->assertSame((string) strlen(self::$pngBytes), $enclosures->item(0)->getAttribute('length'));
    }

    public function testAnUntitledNoteIsGivenItsOpeningWordsAsATitle(): void
    {
        $document = $this->export(null, true, false)['document'];
        $item = $this->itemsOf($document)[$this->uuid('note')];

        $this->assertSame('Three grey herons stood in the shallows ...', $this->xpathOf($document)->evaluate('string(title)', $item));
    }

    public function testAnRssItemHasNoWxrElements(): void
    {
        $document = $this->export(null, true, false)['document'];
        $xpath = $this->xpathOf($document);
        $item = $this->itemsOf($document)[$this->uuid('article')];

        foreach (['wp:post_id', 'wp:post_date', 'wp:post_name', 'content:encoded', 'wp:comment'] as $element) {
            $this->assertSame(0.0, $xpath->evaluate('count(' . $element . ')', $item), $element);
        }
    }

    // ------------------------------------------------------------------ the WXR document

    public function testTheWxrDocumentDeclaresItsVersionAndBlogAddress(): void
    {
        $document = $this->export(null, true, true)['document'];
        $xpath = $this->xpathOf($document);

        $this->assertSame('1.2', $xpath->evaluate('string(//channel/wp:wxr_version)'));
        $this->assertSame(Idno::site()->config()->getDisplayURL(), $xpath->evaluate('string(//channel/wp:base_site_url)'));
        $this->assertSame(Idno::site()->config()->getDisplayURL(), $xpath->evaluate('string(//channel/wp:base_blog_url)'));
    }

    public function testAWxrItemCarriesWordPressDatesIdSlugBodyAndTheComment(): void
    {
        $document = $this->export(null, true, true)['document'];
        $xpath = $this->xpathOf($document);
        $item = $this->itemsOf($document)[$this->uuid('article')];

        $this->assertSame(self::$posts['article']->getID(), $xpath->evaluate('string(wp:post_id)', $item));
        $this->assertSame('2024-02-10 08:30:00', $xpath->evaluate('string(wp:post_date)', $item));
        $this->assertSame('2024-02-10 08:30:00', $xpath->evaluate('string(wp:post_date_gmt)', $item));
        $this->assertSame(self::$posts['article']->getSlug(), $xpath->evaluate('string(wp:post_name)', $item));
        $this->assertStringContainsString('<strong>6:12</strong>', $xpath->evaluate('string(content:encoded)', $item));
        $this->assertSame($xpath->evaluate('string(description)', $item), $xpath->evaluate('string(content:encoded)', $item), 'the same HTML twice');

        $comments = $xpath->query('wp:comment', $item);
        $this->assertSame(1, $comments->length);
        $comment = $comments->item(0);
        $this->assertSame('Ana Okafor', $xpath->evaluate('string(wp:comment_author)', $comment));
        $this->assertSame('https://shoreline.example/ana', $xpath->evaluate('string(wp:comment_author_url)', $comment));
        $this->assertSame('Lovely photos — ¡gracias! 🐚', $xpath->evaluate('string(wp:comment_content)', $comment));
        $this->assertSame('2024-02-11 13:05:22', $xpath->evaluate('string(wp:comment_date_gmt)', $comment));
        $this->assertSame('1', $xpath->evaluate('string(wp:comment_approved)', $comment));
        $this->assertSame('comment', $xpath->evaluate('string(wp:comment_type)', $comment));
    }

    public function testAWxrExportCanBeReadByTheWordPressImporterOfThisSite(): void
    {
        // The round trip the two formats exist for: what the export writes, the importer reads.
        $document = $this->export(null, true, true)['document'];
        $data = simplexml_load_string($document, null, LIBXML_NOCDATA);
        $this->assertNotFalse($data);
        $titles = [];
        foreach ($data->channel->item as $item) {
            $wp = $item->children('http://wordpress.org/export/1.2/');
            $this->assertSame('post', (string) $wp->post_type);
            $titles[] = (string) $item->title;
        }
        $this->assertContains(self::TITLE, $titles);
    }

    // ------------------------------------------------------------------ who is in it

    public function testAnAdminsExportWithPrivatePostsIncludedHoldsEveryonesPrivatePostsAndTheDraft(): void
    {
        $document = $this->export($this->admin(), false, true)['document'];

        $this->assertItems(['article', 'note', 'private', 'draft', 'memberPublic', 'memberPrivate'], $document);
    }

    public function testTodayAnAdminsExportThatHidesPrivatePostsStillHoldsThem(): void
    {
        // An administrator reads every record whatever its access, so the PUBLIC group the export asks
        // for (Migration.php:589) narrows nothing for them: "hide private posts" hides nothing.
        // The next change must flip this to the test below.
        $document = $this->export($this->admin(), true, true)['document'];

        $this->assertItems(['article', 'note', 'private', 'draft', 'memberPublic', 'memberPrivate'], $document);
    }

    public function testHidingPrivatePostsHidesThemFromAnAdminsExportToo(): void
    {
        $this->markTestIncomplete('Migration::getExportRSS() with $hide_private asks for the PUBLIC group only (Migration.php:589), which an admin reading as an admin ignores: private posts and drafts are exported');

        $document = $this->export($this->admin(), true, true)['document'];

        $this->assertItems(['article', 'note', 'memberPublic'], $document);
    }

    public function testTodayEveryItemIsMarkedPublishedWhateverItsAccessOrStatus(): void
    {
        // rssSerialise() writes wp:status "publish" for every item (Entity.php:2109): a WordPress that
        // imports the file publishes the private note and the draft. The next change must flip this.
        $document = $this->export($this->admin(), false, true)['document'];
        $xpath = $this->xpathOf($document);
        $items = $this->itemsOf($document);

        foreach (['private', 'draft'] as $post) {
            $this->assertSame('publish', $xpath->evaluate('string(wp:status)', $items[$this->uuid($post)]), $post);
        }
    }

    public function testAPrivateOrDraftItemIsNotMarkedPublished(): void
    {
        $this->markTestIncomplete('Entity::rssSerialise() writes <wp:status>publish</wp:status> for every item (Entity.php:2109), so a private post or a draft is imported as published');

        $document = $this->export($this->admin(), false, true)['document'];
        $xpath = $this->xpathOf($document);
        $items = $this->itemsOf($document);

        $this->assertSame('private', $xpath->evaluate('string(wp:status)', $items[$this->uuid('private')]));
        $this->assertSame('draft', $xpath->evaluate('string(wp:status)', $items[$this->uuid('draft')]));
    }

    public function testAMembersExportHoldsOnlyTheirOwnPublicPosts(): void
    {
        $member = $this->user();
        $document = $this->export($member, true, true, $member)['document'];

        $this->assertItems(['memberPublic'], $document);
        $xpath = $this->xpathOf($document);
        $this->assertSame($member->getTitle(), $xpath->evaluate('string(//channel/title)'), 'the channel is the member\'s, not the site\'s');
        $this->assertSame('Test User', $xpath->evaluate('string((//channel/item/dc:creator)[1])'));
    }

    public function testAMembersExportWithPrivatePostsIncludedHoldsTheirPrivatePostsAndNoOneElses(): void
    {
        $member = $this->user();
        $document = $this->export($member, false, true, $member)['document'];

        $this->assertItems(['memberPublic', 'memberPrivate'], $document);
    }

    public function testTheChannelLinkOfAMembersExportIsTheirProfileUuid(): void
    {
        $member = $this->user();
        $document = $this->export($member, true, false, $member)['document'];

        $this->assertSame($member->getUUID(), $this->xpathOf($document)->evaluate('string(//channel/link)'));
    }

    // ------------------------------------------------------------------ what it leaves behind

    public function testTodayTheTemporaryDirectoryOfAnExportIsLeftBehind(): void
    {
        // getExportRSS() makes <temp>/<hash>/rss/ and returns a handle on a file in it; nothing removes
        // either, so every export (every click on "Download file") leaves the whole document on disk.
        // The next change must flip this to the test below.
        $run = $this->export(null, true, false);
        $directory = dirname(dirname($run['file']));

        $this->assertDirectoryExists($directory);
        $this->assertFileExists($run['file']);
        $this->assertSame($run['document'], file_get_contents($run['file']));
        $this->assertSame(['export.rss', 'items.rss.fragment', 'template.rss.fragment'], $this->namesIn($directory . '/rss'));
    }

    public function testAnExportRemovesItsTemporaryDirectoryOnceItIsRead(): void
    {
        $this->markTestIncomplete('Migration::getExportRSS() never removes <temp>/<hash>/rss/ or <temp>/<hash>/ (Migration.php:611-705); the pages read the handle and exit');

        $run = $this->export(null, true, false);

        $this->assertDirectoryDoesNotExist(dirname(dirname($run['file'])));
    }

    /**
     * @return list<string>
     */
    private function namesIn(string $directory): array
    {
        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($path . '/' . $name) ? self::removeDirectory($path . '/' . $name) : @unlink($path . '/' . $name);
        }
        @rmdir($path);
    }
}
