<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Legacy\LegacySiteFacts;
use Idno\Portability\ArchiveExporter;
use Idno\Portability\BloggerImporter;
use Idno\Portability\PortabilityJobs;
use Idno\Portability\WordPressImporter;
use Idno\Storage\CollectionDump;
use Idno\Storage\EntityRepository;
use Idno\Storage\Files\FileStore;
use Idno\Storage\RecordStore;
use IdnoPlugins\Text\Entry;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/**
 * The jobs behind the import and export pages, run in this process as the administrator with their state and
 * their mail replaced by doubles: each ends with the mail, success or failure, whatever fails (an \Error too),
 * leaves no working file and clears what it set. (Through the pages: AdminPortabilityPagesTest on the rig's
 * synchronous queue, QueuedPortabilityTest with a worker.)
 */
final class PortabilityJobsTest extends PortabilityTestCase
{
    private InMemoryExportState $state;

    private RecordingNotifier $notifier;

    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logOn($this->admin());
        $this->state = new InMemoryExportState();
        $this->notifier = new RecordingNotifier();
    }

    protected function tearDown(): void
    {
        foreach ($this->postsImported() as $post) {
            $this->track($post);
        }
        parent::tearDown();
    }

    /** @var list<string> */
    private array $knownPosts = [];

    /**
     * @return list<Entity> the articles written since the first call
     */
    private function postsImported(): array
    {
        $posts = [];
        foreach (Entry::get([], [], 500) as $post) {
            if (!in_array($post->getUUID(), $this->knownPosts, true) && $this->knownPosts !== []) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    private function jobs(?FileStore $store = null, ?InMemoryExportState $state = null, ?\Closure $uid = null, ?FileStore $jobsStore = null, ?WordPressImporter $wordpress = null): PortabilityJobs
    {
        $container = Idno::container();
        $facts = new LegacySiteFacts(Idno::site()->config());
        $files = $container->get(FileStore::class);
        $exporter = new ArchiveExporter(
            $container->get(EntityRepository::class),
            $store ?? $files,
            $container->get(RecordStore::class),
            $container->get(CollectionDump::class),
            $facts,
            $container->get('idno.http_client'),
            static fn () => Idno::container()->get('idno.http_client.remote'),
            $container->get(\Idno\Config\BootConfig::class),
            Idno::site()->logging()
        );
        if ($this->knownPosts === []) {
            $this->knownPosts = array_map(static fn (Entity $post): string => $post->getUUID(), Entry::get([], [], 500)) ?: ['none'];
        }

        return new PortabilityJobs(
            $exporter,
            $wordpress ?? $container->get(WordPressImporter::class),
            $container->get(BloggerImporter::class),
            $jobsStore ?? $files,
            $state ?? $this->state,
            $this->notifier,
            $facts,
            Idno::site()->logging(),
            fn (): int => $this->now,
            $uid
        );
    }

    private function failingStore(): FileStore
    {
        $adapter = new class() extends InMemoryFilesystemAdapter {
            public function writeStream(string $path, $contents, \League\Flysystem\Config $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'the disk is full');
            }
        };

        return new FileStore(new Filesystem($adapter), 'uploads');
    }

    /**
     * @return list<string>
     */
    private function workingFiles(): array
    {
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');
        $found = glob($temporary . '/idno-*') ?: [];

        return array_values(array_filter($found, static fn (string $path): bool => preg_match('~/idno-(export|import)-[0-9a-f]{32}$~', $path) === 1));
    }

    private function stage(string $contents, string $name = 'export.xml'): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        $this->removeAfterwards($path);
        file_put_contents($path, $contents);
        $id = $this->jobs()->stageUpload($path, $name);
        $this->removeFileAfterwards($id);

        return $id;
    }

    // ------------------------------------------------------------------ export

    public function testAnExportStoresTheArchiveRecordsItAndMailsTheSuccess(): void
    {
        $jobs = $this->jobs();
        $this->assertTrue($jobs->beginExport());
        $this->assertTrue($this->state->inProgress);

        $this->assertTrue($jobs->runExport($this->admin()->getUUID()));

        $this->assertSame([[$this->admin()->getUUID(), true]], $this->notifier->exports);
        $this->assertFalse($this->state->inProgress, 'the flag is cleared');
        $this->assertNotNull($this->state->fileId);
        $this->removeFileAfterwards((string) $this->state->fileId);
        $stored = Idno::container()->get(FileStore::class)->find((string) $this->state->fileId);
        $this->assertSame('admin', $stored?->metadata['restricted'] ?? null);
        $this->assertSame([], $this->workingFiles());
    }

    public function testANewArchiveReplacesThePreviousOneInTheFileStore(): void
    {
        $jobs = $this->jobs();
        $jobs->runExport($this->admin()->getUUID());
        $first = (string) $this->state->fileId;
        $this->removeFileAfterwards($first);
        $files = Idno::container()->get(FileStore::class);
        $this->assertTrue($files->has($first));

        $jobs->runExport($this->admin()->getUUID());
        $second = (string) $this->state->fileId;
        $this->removeFileAfterwards($second);

        $this->assertNotSame($first, $second);
        $this->assertFalse($files->has($first), 'the old archive is removed');
        $this->assertTrue($files->has($second));
    }

    public function testAFailureRemovesTheWorkingFilesAndSendsTheFailureMail(): void
    {
        $before = $this->workingFiles();
        $this->state->inProgress = true;
        $this->state->fileId = 'keptarchive0000000000000000000000';

        $this->assertFalse($this->jobs($this->failingStore())->runExport($this->admin()->getUUID()));

        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->exports, 'one mail: the failure');
        $this->assertSame($before, $this->workingFiles(), 'no working directory is left');
        $this->assertFalse($this->state->inProgress, 'the flag is cleared on a failure too');
        $this->assertSame('keptarchive0000000000000000000000', $this->state->fileId, 'and the archive that was there stays');
        $this->assertSame(['fail'], array_values(array_diff($this->state->calls, ['swept'])));
    }

    public function testTheMailSaysHowManyEntriesCouldNotBeRendered(): void
    {
        $bad = new ExplodingEntry();
        $bad->title = 'Does not render';
        $bad->body = '<p>boom</p>';
        $this->store($bad, $this->admin());

        $this->assertTrue($this->jobs()->runExport($this->admin()->getUUID()));
        $this->removeFileAfterwards((string) $this->state->fileId);

        $this->assertSame([1], $this->notifier->unrendered);
        $drawn = clone Idno::site()->template();
        $drawn->setTemplateType('email-text');
        $this->assertStringContainsString('1 entries could not be rendered and are included as data only', (string) $drawn->__(['unrendered' => 1])->draw('admin/export'));
        $this->assertStringNotContainsString('could not be rendered', (string) $drawn->__(['unrendered' => 0])->draw('admin/export'));
    }

    public function testAnErrorIsAFailureToo(): void
    {
        $this->state->finishFails = new \TypeError('a bug in something that is not an exception');
        $files = Idno::container()->get(FileStore::class);

        $this->assertFalse($this->jobs()->runExport($this->admin()->getUUID()), 'an \\Error is caught like an \\Exception');

        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->exports);
        $this->assertFalse($this->state->inProgress);
        $calls = array_values(array_diff($this->state->calls, ['swept']));
        $this->assertCount(2, $calls, 'finish was tried, then the failure was recorded');
        $archiveId = substr($calls[0], strlen('finish:'));
        $this->assertFalse($files->has($archiveId), 'and the archive that could not be recorded is not left in the store');
    }

    public function testASomeoneWhoIsNotAnAdministratorGetsNoArchive(): void
    {
        $this->assertFalse($this->jobs()->runExport($this->user()->getUUID()));

        $this->assertNull($this->state->fileId);
        $this->assertSame([[$this->user()->getUUID(), false]], $this->notifier->exports);
    }

    public function testOnlyOneExportRunsAtATimeAndAFlagOfSixHoursIsIgnored(): void
    {
        $jobs = $this->jobs();

        $this->assertTrue($jobs->beginExport());
        $this->now += 3600;
        $this->assertFalse($jobs->beginExport(), 'one is running');
        $this->now += 6 * 3600 - 3600 - 1;
        $this->assertFalse($jobs->beginExport(), 'a second short of six hours');
        $this->now += 1;
        $this->assertTrue($jobs->beginExport(), 'six hours: a job that died');
        $this->assertSame($this->now, $this->state->requested);
    }

    public function testTheStateOfAnExportThatDiedIsNotInProgressForTheExportPage(): void
    {
        $jobs = $this->jobs();
        $this->assertTrue($jobs->beginExport());

        $this->assertTrue($jobs->exportState()['inProgress']);
        $this->now += PortabilityJobs::STALE_EXPORT_SECONDS - 1;
        $this->assertTrue($jobs->exportState()['inProgress'], 'a second short of six hours');
        $this->now += 1;
        $this->assertFalse($jobs->exportState()['inProgress'], 'six hours: the page offers the button again, as beginExport() would accept it');
        $this->assertTrue($jobs->beginExport());
    }

    public function testAnArchiveIsRemovedAWeekAfterItWasMadeAndNotBefore(): void
    {
        $jobs = $this->jobs();
        $jobs->runExport($this->admin()->getUUID());
        $id = (string) $this->state->fileId;
        $this->removeFileAfterwards($id);
        $this->state->requested = $this->now;
        $files = Idno::container()->get(FileStore::class);

        $this->now += 7 * 86400 - 1;
        $this->assertSame($id, $jobs->exportState()['fileId']);
        $this->assertTrue($files->has($id), 'one second short of a week');

        $this->now += 1;
        $this->assertNull($jobs->exportState()['fileId'], 'asked when the export page is loaded');
        $this->assertFalse($files->has($id), 'removed from the store, and forgotten');
    }

    public function testTheArchivesExpiryAndGeneratedDateAreItsOwnNotThoseOfALaterRequest(): void
    {
        $jobs = $this->jobs();
        $madeAt = $this->now;
        $jobs->runExport($this->admin()->getUUID());
        $id = (string) $this->state->fileId;
        $this->removeFileAfterwards($id);
        $this->assertSame($madeAt, $this->state->created, 'the time the archive was made is stored with its id');

        // Six days later an administrator asks for another export, which is still running: the request time moves.
        $this->now += 6 * 86400;
        $this->assertTrue($jobs->beginExport());
        $this->assertSame($this->now, $this->state->requested);
        $this->assertSame($madeAt, $jobs->exportState()['created'], 'the page says when the archive was generated, not when the last request was made');

        $this->now = $madeAt + 7 * 86400;
        $this->assertNull($jobs->exportState()['fileId'], 'a week after it was made, whatever was requested since');
        $this->assertFalse(Idno::container()->get(FileStore::class)->has($id));
    }

    public function testAnArchiveAnEarlierVersionLeftUnmarkedIsMarkedWhenTheExportPageLoadsAndRemovedWhenAWeekOld(): void
    {
        $files = Idno::container()->get(FileStore::class);
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'PK');
        rewind($stream);
        $id = $files->store($stream, ['filename' => 'old.zip', 'mime_type' => 'application/zip']);
        fclose($stream);
        $this->removeFileAfterwards($id);
        $this->state->fileId = $id;
        $this->state->filename = 'old.zip';
        $this->state->requested = $this->now - 86400;

        $this->jobs()->exportState();

        $this->assertSame('admin', $files->find($id)->metadata['restricted'] ?? null, 'marked for good');

        $this->now += 7 * 86400;
        $this->state->created = $this->now - 7 * 86400;
        $this->assertNull($this->jobs()->exportState()['fileId']);
        $this->assertFalse($files->has($id), 'and, being a week old, removed');
    }

    /**
     * A file store that fails the test when anything lists it.
     */
    private function storeThatCannotBeListed(): FileStore
    {
        $adapter = new class() extends InMemoryFilesystemAdapter {
            public function listContents(string $path, bool $deep): iterable
            {
                throw new \LogicException('The whole file store was listed.');
            }
        };

        return new FileStore(new Filesystem($adapter), 'uploads');
    }

    private function putFile(FileStore $files, array $metadata = []): string
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, 'x');
        rewind($stream);
        $id = $files->store($stream, ['filename' => 'x', 'restricted' => 'admin'] + $metadata);
        fclose($stream);

        return $id;
    }

    public function testOnlyTheWorkingDirectoriesThisInstallMadeAreSweptAndOnlyWhenOldAndOwnedByTheUser(): void
    {
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');
        $hex = static fn (): string => bin2hex(random_bytes(16));
        $ours = [];
        foreach (['export', 'import', 'archive'] as $kind) {
            $ours[$kind] = $temporary . '/idno-' . $kind . '-' . $hex();
            mkdir($ours[$kind] . '/inner', 0700, true);
            file_put_contents($ours[$kind] . '/inner/file', 'x');
            touch($ours[$kind], $this->now - 2 * 86400);
            $this->removeAfterwards($ours[$kind]);
        }
        $young = $temporary . '/idno-import-' . $hex();
        mkdir($young, 0700);
        touch($young, $this->now - 3600);
        $this->removeAfterwards($young);
        $notOurs = [
            'a name that is not 32 hex digits' => $temporary . '/idno-import-' . substr($hex(), 1),
            'another application\'s folder' => $temporary . '/idno-importer-' . $hex(),
            'a prefix of ours with more after it' => $temporary . '/idno-export-' . $hex() . '-old',
            'an unrelated import folder' => $temporary . '/import-' . $hex(),
        ];
        foreach ($notOurs as $path) {
            mkdir($path, 0700);
            touch($path, $this->now - 30 * 86400);
            $this->removeAfterwards($path);
        }
        $file = $temporary . '/import-notours.csv'; // a file of another application in the shared directory
        file_put_contents($file, 'x');
        touch($file, $this->now - 30 * 86400);
        $this->removeAfterwards($file);
        $asFile = $temporary . '/idno-import-' . $hex(); // the right name, but a file, not a directory
        file_put_contents($asFile, 'x');
        touch($asFile, $this->now - 30 * 86400);
        $this->removeAfterwards($asFile);

        $this->jobs()->sweep();

        foreach ($ours as $kind => $path) {
            $this->assertDirectoryDoesNotExist($path, 'an old idno-' . $kind . ' folder');
        }
        $this->assertDirectoryExists($young, 'younger than a day');
        foreach ($notOurs as $why => $path) {
            $this->assertDirectoryExists($path, $why);
        }
        $this->assertFileExists($file);
        $this->assertFileExists($asFile, 'a file with such a name is not a folder of ours');
    }

    public function testADirectoryOwnedBySomeoneElseIsNotSwept(): void
    {
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');
        $path = $temporary . '/idno-export-' . bin2hex(random_bytes(16));
        mkdir($path, 0700);
        touch($path, $this->now - 2 * 86400);
        $this->removeAfterwards($path);
        $owner = (int) fileowner($path);

        $this->jobs(null, null, fn (): int => $owner + 1)->sweep();
        $this->assertDirectoryExists($path, 'the sweep is not run as the folder\'s owner');

        $this->state->swept = 0;
        $this->jobs(null, null, fn (): int => $owner)->sweep();
        $this->assertDirectoryDoesNotExist($path);
    }

    public function testTheSweepLooksOnlyAtTheFilesTheJobsRecordedAndNeverListsTheStore(): void
    {
        $files = $this->storeThatCannotBeListed();
        $oldUpload = $this->putFile($files);
        $youngUpload = $this->putFile($files);
        $strayArchive = $this->putFile($files);
        $youngArchive = $this->putFile($files);
        $recorded = $this->putFile($files);
        $untracked = $this->putFile($files); // a file nobody recorded: not the sweep's to find
        $gone = bin2hex(random_bytes(16));
        $this->state->tracked = [
            ['id' => $oldUpload, 'kind' => 'upload', 'at' => $this->now - 2 * 86400],
            ['id' => $youngUpload, 'kind' => 'upload', 'at' => $this->now - 3600],
            ['id' => $strayArchive, 'kind' => 'archive', 'at' => $this->now - 8 * 86400],
            ['id' => $youngArchive, 'kind' => 'archive', 'at' => $this->now - 86400],
            ['id' => $recorded, 'kind' => 'archive', 'at' => $this->now - 8 * 86400],
            ['id' => $gone, 'kind' => 'upload', 'at' => $this->now],
        ];
        $this->state->fileId = $recorded;
        $this->state->created = $this->now;
        $jobs = $this->jobs($files, null, null, $files);

        $jobs->sweep();

        $this->assertFalse($files->has($oldUpload), 'a tracked upload older than 24 hours');
        $this->assertTrue($files->has($youngUpload));
        $this->assertFalse($files->has($strayArchive), 'a tracked archive that is not the recorded one, a week old');
        $this->assertTrue($files->has($youngArchive));
        $this->assertTrue($files->has($recorded), 'the recorded archive is never deleted by the sweep');
        $this->assertTrue($files->has($untracked));
        $left = array_column($this->state->tracked, 'id');
        sort($left);
        $expected = [$youngUpload, $youngArchive, $recorded];
        sort($expected);
        $this->assertSame($expected, $left, 'deleted and vanished files are dropped from the list');

        // At most once an hour.
        $again = $this->putFile($files);
        $this->state->tracked[] = ['id' => $again, 'kind' => 'upload', 'at' => $this->now - 3 * 86400];
        $this->now += PortabilityJobs::SWEEP_INTERVAL_SECONDS - 1;
        $jobs->sweep();
        $this->assertTrue($files->has($again), 'a second short of an hour: not swept again');
        $this->now += 1;
        $jobs->sweep();
        $this->assertFalse($files->has($again));
    }

    public function testAnUploadAndAnArchiveAreRecordedWhenTheyAreStoredAndUnrecordedWhenTheyAreRemoved(): void
    {
        $files = Idno::container()->get(FileStore::class);
        $jobs = $this->jobs();
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        file_put_contents($path, '<rss/>');
        $this->removeAfterwards($path);

        $upload = $jobs->stageUpload($path, 'export.xml');
        $this->removeFileAfterwards($upload);
        $this->assertSame([['id' => $upload, 'kind' => 'upload', 'at' => $this->now]], $this->state->tracked);
        $jobs->discardUpload($upload);
        $this->assertSame([], $this->state->tracked, 'removed, so no longer tracked');

        $jobs->runExport($this->admin()->getUUID());
        $archive = (string) $this->state->fileId;
        $this->removeFileAfterwards($archive);
        $this->assertContains(['id' => $archive, 'kind' => 'archive', 'at' => $this->now], $this->state->tracked);
        $this->assertTrue($files->has($archive));
    }

    public function testOnlyFiftyFilesAreTrackedAndTheOldestBeyondThatAreDeletedFirst(): void
    {
        $files = Idno::container()->get(FileStore::class);
        $jobs = $this->jobs();
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        file_put_contents($path, '<rss/>');
        $this->removeAfterwards($path);
        $ids = [];
        for ($i = 0; $i < PortabilityJobs::TRACKED_LIMIT + 2; $i++) {
            $this->now += 1;
            $ids[] = $id = $jobs->stageUpload($path, 'export.xml');
            $this->removeFileAfterwards($id);
        }

        $this->assertCount(PortabilityJobs::TRACKED_LIMIT, $this->state->tracked);
        $this->assertFalse($files->has($ids[0]), 'the oldest was deleted, not just forgotten');
        $this->assertFalse($files->has($ids[1]));
        $this->assertTrue($files->has($ids[2]));
        $this->assertSame($ids[2], $this->state->tracked[0]['id']);
    }

    public function testAnImportsWorkingFileIsInAPrivateDirectoryThatIsRemoved(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        file_put_contents($path, $this->wordpressPost('Seen working file'));
        $this->removeAfterwards($path);
        $jobs = $this->jobs();
        $id = $jobs->stageUpload($path, 'export.xml');
        $before = $this->workingFiles();

        $this->assertTrue($jobs->runImport($this->admin()->getUUID(), $id, 'wordpress'));

        $this->assertSame($before, $this->workingFiles(), 'the directory is gone');
    }

    /**
     * A WordPress importer whose post writer, on its first write, records whether the staged upload's metadata already
     * holds the started mark (read from the file store, not from what a job wrote at some time).
     *
     * @param list<bool|null> $seen filled with the mark's presence at each first write
     */
    private function importerThatReadsTheMark(string $uploadId, array &$seen): WordPressImporter
    {
        $container = Idno::container();
        $logger = Idno::site()->logging();
        $inner = new \Idno\Legacy\LegacyPostWriter(Idno::site()->plugins(), $logger);
        $spy = new class($inner, $container->get(FileStore::class), $uploadId, $seen) implements \Idno\Portability\PostWriter {
            private bool $first = true;

            public function __construct(private readonly \Idno\Legacy\LegacyPostWriter $inner, private readonly FileStore $files, private readonly string $uploadId, private array &$seen)
            {
            }

            public function available(): bool
            {
                return $this->inner->available();
            }

            public function write(\Idno\Portability\ImportedPost $post, string $ownerUuid): ?string
            {
                if ($this->first) {
                    $this->first = false;
                    $stored = $this->files->find($this->uploadId);
                    $this->seen[] = $stored === null ? null : isset($stored->metadata[PortabilityJobs::STARTED_AT]);
                }

                return $this->inner->write($post, $ownerUuid);
            }

            public function addComments(string $postUuid, array $comments): int
            {
                return $this->inner->addComments($postUuid, $comments);
            }
        };
        $facts = new LegacySiteFacts(Idno::site()->config());
        $media = new \Idno\Portability\ImportedMedia(static fn () => $container->get('idno.http_client.remote'), $container->get(FileStore::class), $facts, $logger);

        return new WordPressImporter($media, $spy, $container->get(\Idno\Portability\ImportMode::class), $facts, $logger);
    }

    public function testAnUploadIsMarkedAsStartedBeforeTheFirstPostIsWrittenAndTheJobTellsTheCounts(): void
    {
        $files = Idno::container()->get(FileStore::class);
        $id = $this->stage($this->wordpressPost('Marked upload'));
        $this->assertArrayNotHasKey(PortabilityJobs::STARTED_AT, $files->find($id)->metadata, 'a staged upload has no mark until its job begins');
        $seen = [];
        $jobs = $this->jobs(null, null, null, null, $this->importerThatReadsTheMark($id, $seen));

        $this->assertTrue($jobs->runImport($this->admin()->getUUID(), $id, 'wordpress'));

        $this->assertSame([true], $seen, 'when the first post was written, the upload already held the mark: a job killed from here on cannot be run again');
        $this->assertSame([['imported' => 1, 'skipped' => 0, 'startedBefore' => false, 'stopped' => false]], $this->notifier->importDetails);
    }

    public function testAnImportStoppedAfterRepeatedErrorsIsAFailureThatSaysHowManyPostsWereMade(): void
    {
        $items = '';
        for ($i = 1; $i <= 40; $i++) {
            $items .= '<item><title>Stopped ' . $i . '</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>B.</p>]]></content:encoded></item>';
        }
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>T</title>' . $items . '</channel></rss>';
        $container = Idno::container();
        $logger = Idno::site()->logging();
        $inner = new \Idno\Legacy\LegacyPostWriter(Idno::site()->plugins(), $logger);
        $writer = new class($inner) implements \Idno\Portability\PostWriter {
            private int $calls = 0;

            public function __construct(private readonly \Idno\Legacy\LegacyPostWriter $inner)
            {
            }

            public function available(): bool
            {
                return $this->inner->available();
            }

            public function write(\Idno\Portability\ImportedPost $post, string $ownerUuid): ?string
            {
                if (++$this->calls > 3) {
                    throw new \RuntimeException('the database is gone');
                }

                return $this->inner->write($post, $ownerUuid);
            }

            public function addComments(string $postUuid, array $comments): int
            {
                return $this->inner->addComments($postUuid, $comments);
            }
        };
        $facts = new LegacySiteFacts(Idno::site()->config());
        $media = new \Idno\Portability\ImportedMedia(static fn () => $container->get('idno.http_client.remote'), $container->get(FileStore::class), $facts, $logger);
        $wordpress = new WordPressImporter($media, $writer, $container->get(\Idno\Portability\ImportMode::class), $facts, $logger);
        $jobs = $this->jobs(null, null, null, null, $wordpress);
        $id = $this->stage($xml);

        $this->assertFalse($jobs->runImport($this->admin()->getUUID(), $id, 'wordpress'), 'a failure, though three posts were made');

        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->imports);
        $this->assertSame(['imported' => 3, 'skipped' => 25, 'startedBefore' => false, 'stopped' => true], $this->notifier->importDetails[0]);
        $drawn = static function (string $type, array $vars): string {
            $template = clone Idno::site()->template();
            $template->setTemplateType($type);

            return (string) $template->__($vars)->draw('admin/import_failure');
        };
        foreach (['email-text', 'email'] as $type) {
            $mail = $drawn($type, $this->notifier->importDetails[0]);
            $this->assertStringContainsString('stopped after repeated errors', $mail, $type);
            $this->assertStringContainsString('3 posts were imported before it stopped', $mail, $type);
            $this->assertStringContainsString("site's log", $mail, $type);
        }
    }

    public function testAnUploadAJobStartedBeforeIsRefusedAndNoPostIsImportedTwice(): void
    {
        $jobs = $this->jobs();
        $id = $this->stage($this->wordpressPost('Imported once only'));
        Idno::container()->get(FileStore::class)->updateMetadata($id, [PortabilityJobs::STARTED_AT => $this->now - 600]);
        $before = count($this->postsImported());

        $this->assertFalse($jobs->runImport($this->admin()->getUUID(), $id, 'wordpress'));

        $this->assertSame($before, count($this->postsImported()), 'nothing was imported');
        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->imports);
        $this->assertTrue($this->notifier->importDetails[0]['startedBefore'], 'the mail says it was started before');
        $this->assertFalse(Idno::container()->get(FileStore::class)->has($id), 'and the upload is removed');
    }

    public function testTheImportMailsSayWhatHappenedAndWhatToDoNext(): void
    {
        $draw = static function (string $type, string $template, array $vars): string {
            $drawn = clone Idno::site()->template();
            $drawn->setTemplateType($type);

            return (string) $drawn->__($vars)->draw($template);
        };
        foreach (['email-text', 'email'] as $type) {
            $failure = $draw($type, 'admin/import_failure', ['imported' => 0, 'skipped' => 0, 'startedBefore' => false]);
            $this->assertStringContainsString('may already have been imported', $failure, $type);
            $this->assertMatchesRegularExpression('/Check\s+your posts before\s+you\s+import\s+the\s+file\s+again/', strip_tags($failure), $type);
            $this->assertDoesNotMatchRegularExpression('/You could try the import again/', $failure, $type);

            $before = $draw($type, 'admin/import_failure', ['startedBefore' => true]);
            $this->assertStringContainsString('started before and did not finish', $before, $type);
            $this->assertStringContainsString('upload the file again', $before, $type);

            $done = $draw($type, 'admin/import', ['imported' => 4, 'skipped' => 2]);
            $this->assertStringContainsString('4 posts were imported', $done, $type);
            $this->assertStringContainsString('2 items', $done, $type);
        }
    }

    public function testAnImportReadsItsFileFromAPrivateDirectoryOfTheRightNameAndMode(): void
    {
        $seen = [];
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');
        $logger = Idno::site()->logging();
        $inner = new \Idno\Legacy\LegacyPostWriter(Idno::site()->plugins(), $logger);
        $spy = new class($inner, $temporary, $seen) implements \Idno\Portability\PostWriter {
            public function __construct(private readonly \Idno\Legacy\LegacyPostWriter $inner, private readonly string $temporary, private array &$seen)
            {
            }

            public function available(): bool
            {
                return $this->inner->available();
            }

            public function write(\Idno\Portability\ImportedPost $post, string $ownerUuid): ?string
            {
                foreach (glob($this->temporary . '/idno-import-*') ?: [] as $directory) {
                    if (preg_match('~/idno-import-[0-9a-f]{32}$~', $directory) === 1) {
                        $this->seen[] = [basename($directory), fileperms($directory) & 0777, is_file($directory . '/import.xml'), fileperms($directory . '/import.xml') & 0777];
                    }
                }

                return $this->inner->write($post, $ownerUuid);
            }

            public function addComments(string $postUuid, array $comments): int
            {
                return $this->inner->addComments($postUuid, $comments);
            }
        };
        $container = Idno::container();
        $facts = new LegacySiteFacts(Idno::site()->config());
        $media = new \Idno\Portability\ImportedMedia(static fn () => $container->get('idno.http_client.remote'), $container->get(FileStore::class), $facts, $logger);
        $wordpress = new WordPressImporter($media, $spy, $container->get(\Idno\Portability\ImportMode::class), $facts, $logger);
        $jobs = $this->jobs(null, null, null, null, $wordpress);
        $id = $this->stage($this->wordpressPost('Seen from the spy'));

        $this->assertTrue($jobs->runImport($this->admin()->getUUID(), $id, 'wordpress'));

        $this->assertCount(1, $seen, 'one working directory existed while the post was written');
        $this->assertMatchesRegularExpression('/^idno-import-[0-9a-f]{32}$/', $seen[0][0]);
        $this->assertSame(0700, $seen[0][1], 'the directory is private');
        $this->assertTrue($seen[0][2], 'the file is import.xml inside it');
        $this->assertSame(0600, $seen[0][3]);
    }

    public function testStartingAJobSweepsToo(): void
    {
        $files = Idno::container()->get(FileStore::class);
        $id = $this->putFile($files);
        $this->removeFileAfterwards($id);
        $this->state->tracked = [['id' => $id, 'kind' => 'upload', 'at' => $this->now - 2 * 86400]];

        $this->jobs()->beginExport();

        $this->assertFalse($files->has($id));
    }

    public function testRequestingAnExportRemovesAnExpiredArchiveToo(): void
    {
        $jobs = $this->jobs();
        $jobs->runExport($this->admin()->getUUID());
        $id = (string) $this->state->fileId;
        $this->removeFileAfterwards($id);
        $this->state->requested = $this->now - 8 * 86400;
        $this->state->created = $this->now - 8 * 86400;

        $this->assertTrue($jobs->beginExport());

        $this->assertFalse(Idno::container()->get(FileStore::class)->has($id));
        $this->assertNull($this->state->fileId);
    }

    // ------------------------------------------------------------------ import

    private function wordpressPost(string $title = 'A job test post'): string
    {
        return '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Inline</title><item><title>' . $title . '</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Body.</p>]]></content:encoded></item></channel></rss>';
    }

    public function testAnImportCreatesThePostsRemovesTheUploadAndMailsTheSuccess(): void
    {
        $upload = $this->stage($this->wordpressPost());
        $before = $this->workingFiles();
        $jobs = $this->jobs();

        $this->assertTrue($jobs->runImport($this->admin()->getUUID(), $upload, 'wordpress'));

        $this->assertSame([[$this->admin()->getUUID(), true]], $this->notifier->imports);
        $this->assertFalse(Idno::container()->get(FileStore::class)->has($upload), 'the upload is removed');
        $this->assertSame($before, $this->workingFiles());
        $titles = array_map(static fn (Entity $post): string => (string) $post->title, $this->postsImported());
        $this->assertSame(['A job test post'], $titles);
    }

    public function testABloggerImportRunsTheBloggerImporter(): void
    {
        $upload = $this->stage((string) file_get_contents(__DIR__ . '/fixtures/blogger-export.xml'));

        $this->assertTrue($this->jobs()->runImport($this->admin()->getUUID(), $upload, 'blogger'));

        $titles = array_map(static fn (Entity $post): string => (string) $post->title, $this->postsImported());
        $this->assertContains('The heron on the weir', $titles);
    }

    /**
     * @return array<string, array{string, string}> what an import with nothing to import is given
     */
    public static function importsThatImportNothing(): array
    {
        return [
            'an empty file' => ['', 'wordpress'],
            'text that is not XML' => ['These are my notes, not an export.', 'wordpress'],
            'XML with no post' => ['<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>', 'blogger'],
            'a file with a document type' => ['<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "b">]><rss><channel><item><title>&a;</title></item></channel></rss>', 'wordpress'],
            'an unknown source' => ['<rss/>', 'known'],
            'no source' => ['<rss/>', ''],
        ];
    }

    /**
     * @dataProvider importsThatImportNothing
     */
    public function testAnImportThatImportsNothingSendsTheFailureMailAndCreatesNothing(string $contents, string $type): void
    {
        $upload = $this->stage($contents);
        $before = $this->workingFiles();

        $this->assertFalse($this->jobs()->runImport($this->admin()->getUUID(), $upload, $type));

        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->imports);
        $this->assertFalse(Idno::container()->get(FileStore::class)->has($upload), 'the upload is removed');
        $this->assertSame($before, $this->workingFiles());
        $this->assertSame([], $this->postsImported());
    }

    public function testAnImportWhoseUploadIsNotThereSendsTheFailureMail(): void
    {
        $this->assertFalse($this->jobs()->runImport($this->admin()->getUUID(), str_repeat('0', 32), 'wordpress'));
        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->imports);
    }

    public function testTheEventCannotBeUsedToReadOrDeleteAnyOtherFileOfTheStore(): void
    {
        // A file that is not an import upload (a member's photo, say) named by the event: not read, not removed.
        $files = Idno::container()->get(FileStore::class);
        $photo = $this->storeFile((string) base64_decode(self::PNG_BASE64, true), 'photo.png', 'image/png');

        $this->assertFalse($this->jobs()->runImport($this->admin()->getUUID(), $photo, 'wordpress'));

        $this->assertTrue($files->has($photo), 'it is still there');
        $this->assertSame([[$this->admin()->getUUID(), false]], $this->notifier->imports);
    }

    public function testAnImportAskedForBySomeoneWhoIsNotAnAdministratorDoesNothing(): void
    {
        $upload = $this->stage($this->wordpressPost('Not by a member'));

        $this->assertFalse($this->jobs()->runImport($this->user()->getUUID(), $upload, 'wordpress'));

        $this->assertSame([], $this->postsImported());
        $this->assertFalse(Idno::container()->get(FileStore::class)->has($upload), 'the staged upload is removed');
    }

    public function testAStagedUploadIsKeptForAdministratorsOnly(): void
    {
        $upload = $this->stage('<rss/>', '../../evil name.xml');

        $stored = Idno::container()->get(FileStore::class)->find($upload);

        $this->assertSame('admin', $stored?->metadata['restricted'] ?? null);
        $this->assertSame('import', $stored?->metadata['purpose'] ?? null);
        $this->assertSame('evil name.xml', $stored?->metadata['filename'], 'only the file name: no path');
    }
}
