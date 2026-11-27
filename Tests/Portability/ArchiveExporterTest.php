<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Legacy\LegacySiteFacts;
use Idno\Portability\ArchiveExporter;
use Idno\Portability\ExportReport;
use Idno\Storage\CollectionDump;
use Idno\Storage\EntityRepository;
use Idno\Storage\Files\FileStore;
use Idno\Storage\RecordStore;
use IdnoPlugins\Text\Entry;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ArchiveExporter::export(): the archive is made as a stream of files, stored for administrators only, and its
 * working files do not outlive the export, whether it worked or not. (What the folder and the archive hold is
 * ExportArchiveTest's; the pages are AdminPortabilityPagesTest's.)
 */
final class ArchiveExporterTest extends PortabilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->logOn($this->admin());
    }

    private function exporter(?FileStore $store = null, ?RecordStore $records = null, ?HttpClientInterface $client = null, ?HttpClientInterface $remote = null, ?LoggerInterface $logger = null, ?BootConfig $boot = null): ArchiveExporter
    {
        $container = Idno::container();

        return new ArchiveExporter(
            $container->get(EntityRepository::class),
            $store ?? $container->get(FileStore::class),
            $records ?? $container->get(RecordStore::class),
            $container->get(CollectionDump::class),
            new LegacySiteFacts(Idno::site()->config()),
            $client ?? $container->get('idno.http_client'),
            $remote !== null ? static fn () => $remote : static fn () => Idno::container()->get('idno.http_client.remote'),
            $boot ?? $container->get(BootConfig::class),
            $logger ?? Idno::site()->logging()
        );
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>} the folder an export made, and the entities.json it holds
     */
    private function exportAndRead(ArchiveExporter $exporter, ?ExportReport $report = null): array
    {
        $parent = sys_get_temp_dir() . '/idno-export-test-' . bin2hex(random_bytes(6));
        mkdir($parent, 0700);
        $this->removeAfterwards($parent);
        $folder = $exporter->exportToFolder($parent, $report);
        $this->assertIsString($folder);

        return [$folder, array_values(array_filter(json_decode((string) file_get_contents($folder . '/entities.json'), true), static fn (array $e): bool => $e !== []))];
    }

    private function recordingLogger(): object
    {
        return new class() extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [$level, (string) $message];
            }
        };
    }

    /**
     * @return list<string> the working directories the export makes in the temporary directory
     */
    private function workingDirectories(): array
    {
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');

        return array_values(array_filter(glob($temporary . '/idno-export-*') ?: [], static fn (string $path): bool => preg_match('~/idno-export-[0-9a-f]{32}$~', $path) === 1));
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

    public function testTheArchiveIsStoredForAdministratorsOnlyAndItsWorkingFilesAreRemoved(): void
    {
        $before = $this->workingDirectories();
        $article = new Entry();
        $article->title = 'Archived for the test';
        $article->body = '<p>Body</p>';
        $this->store($article, $this->admin());

        $id = $this->exporter()->export($this->admin()->getUUID());
        $this->removeFileAfterwards($id);

        $stored = Idno::container()->get(FileStore::class)->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('admin', $stored->metadata['restricted'], 'the file route serves it to administrators only');
        $this->assertSame('export', $stored->metadata['purpose']);
        $this->assertSame('application/zip', $stored->metadata['mime_type']);
        $this->assertSame(str_replace('.', '_', (string) Idno::site()->config()->host) . '.zip', $stored->metadata['filename']);
        $this->assertSame($before, $this->workingDirectories(), 'no working directory is left');

        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        $this->removeAfterwards($path);
        file_put_contents($path, stream_get_contents(Idno::container()->get(FileStore::class)->readStream($id)));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('known.json'));
        $this->assertNotFalse($zip->locateName('json/' . $article->getID() . '.json'));
        $zip->close();
    }

    public function testOnlyAnAdministratorCanExport(): void
    {
        $before = $this->workingDirectories();

        try {
            $this->exporter()->export($this->user()->getUUID());
            $this->fail('A member must not be able to export the site.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Only an administrator can export the site.', $e->getMessage());
        }
        $this->assertSame($before, $this->workingDirectories(), 'nothing was made');
    }

    public function testAFailureToStoreTheArchiveRemovesTheWorkingFiles(): void
    {
        $before = $this->workingDirectories();

        try {
            $this->exporter($this->failingStore())->export($this->admin()->getUUID());
            $this->fail('The export must fail when the archive cannot be stored.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('could not be stored', $e->getMessage());
        }

        $this->assertSame($before, $this->workingDirectories(), 'the working directory, with the folder and the zip in it, is gone');
    }

    public function testAFailureInTheMiddleOfTheFolderRemovesWhatWasWritten(): void
    {
        $parent = sys_get_temp_dir() . '/idno-archive-fail-' . bin2hex(random_bytes(6));
        mkdir($parent, 0700);
        $this->removeAfterwards($parent);
        $exporter = $this->exporter();
        // The settings cannot be read: the exception comes after known.json is written, before the end.
        $broken = new ArchiveExporter(
            Idno::container()->get(EntityRepository::class),
            Idno::container()->get(FileStore::class),
            new class() implements RecordStore {
                public function save(string $collection, array $record): string|false
                {
                    throw new \LogicException();
                }

                public function findById(string $collection, string $id): ?array
                {
                    throw new \LogicException();
                }

                public function findByUuid(string $collection, string $uuid): ?array
                {
                    throw new \LogicException();
                }

                public function findLatest(string $collection): ?array
                {
                    throw new \RuntimeException('the database went away');
                }

                public function find(string $collection, \Idno\Storage\Criteria $criteria, int $limit, int $offset): array
                {
                    throw new \LogicException();
                }

                public function count(string $collection, \Idno\Storage\Criteria $criteria): int
                {
                    throw new \LogicException();
                }

                public function delete(string $collection, string $id): bool
                {
                    throw new \LogicException();
                }

                public function claim(string $collection, string $id): bool
                {
                    throw new \LogicException();
                }

                public function deleteAll(string $collection): bool
                {
                    throw new \LogicException();
                }
            },
            Idno::container()->get(CollectionDump::class),
            new LegacySiteFacts(Idno::site()->config()),
            Idno::container()->get('idno.http_client'),
            static fn () => Idno::container()->get('idno.http_client.remote'),
            Idno::container()->get(\Idno\Config\BootConfig::class),
            Idno::site()->logging()
        );

        $this->assertFalse($broken->exportToFolder($parent));

        $this->assertSame([], glob($parent . '/*'), 'nothing is left in the directory asked for');
        $this->assertNotFalse($exporter->exportToFolder($parent), 'and the same call works when nothing is broken');
    }

    public function testEntitiesAreReadByKeysetSoAnEntityAddedOrRemovedBetweenPagesNeitherRepeatsNorSkipsOne(): void
    {
        $posts = [];
        for ($i = 0; $i < 25; $i++) { // pages of ten, and most of them made in the same second: ties on `created`
            $post = new Entry();
            $post->title = 'Keyset ' . $i;
            $post->body = '<p>Body</p>';
            $post->created = 1_700_000_000; // all the same second: pages end between entities of an equal `created`
            $posts[] = $this->store($post, $this->admin())->getUUID();
        }
        $records = Idno::container()->get(RecordStore::class);
        $added = [];
        $removed = null;
        $hooked = new HookedRecordStore($records, 'entities', function (int $find, array $page) use ($records, &$added, &$removed, &$posts): void {
            if ($removed !== null) {
                return;
            }
            // After the first page that holds one of this test's posts (the rest of the site's entities are newer):
            // one entity already read goes, and a new one appears at the front.
            foreach ($page as $record) {
                if (in_array($record['uuid'] ?? '', $posts, true)) { // one of this test's posts, never a user or anything else
                    $removed = $record['uuid'];
                    $records->delete('entities', (string) $record['_id']);
                    break;
                }
            }
            if ($removed === null) {
                return;
            }
            $new = new Entry();
            $new->title = 'Added between pages';
            $new->body = '<p>New</p>';
            $added[] = $this->store($new, $this->admin())->getUUID();
        });

        [, $entities] = $this->exportAndRead($this->exporter(null, $hooked));

        $uuids = array_map(static fn (array $e): string => (string) ($e['uuid'] ?? ''), $entities);
        $this->assertSame(array_values(array_unique($uuids)), $uuids, 'no entity is written twice');
        foreach ($posts as $uuid) {
            $this->assertContains($uuid, $uuids, 'no entity that was there all along is skipped (an offset would skip one after a delete)');
        }
        $this->assertNotNull($removed, 'an entity was removed between the pages');
        $this->assertGreaterThan(2, $hooked->finds, 'it took several pages');
        $created = array_unique(array_map(static fn (array $e): mixed => $e['created'] ?? null, array_filter($entities, static fn (array $e): bool => in_array($e['uuid'] ?? '', $posts, true))));
        $this->assertCount(1, $created, 'the posts of the test share one `created`, so a page boundary fell between equals');
    }

    public function testAnEntityThatCannotBeRenderedIsCountedLoggedAndLeftToTheRecords(): void
    {
        $good = new Entry();
        $good->title = 'Renders well';
        $good->body = '<p>fine</p>';
        $this->store($good, $this->admin());
        $bad = new ExplodingEntry();
        $bad->title = 'Does not render';
        $bad->body = '<p>boom</p>';
        $this->store($bad, $this->admin());
        $worse = new ExplodingEntry();
        $worse->title = 'Does not render either';
        $worse->body = 'error';
        $this->store($worse, $this->admin());
        $template = new ExplodingEntry();
        $template->title = 'Only its template fails';
        $template->body = 'draw';
        $this->store($template, $this->admin());
        $logger = $this->recordingLogger();
        $report = new ExportReport();

        [$folder, $entities] = $this->exportAndRead($this->exporter(null, null, null, null, $logger), $report);

        $this->assertSame(3, $report->unrendered, 'an exception, an \Error and a template that fails are each one entity that could not be rendered');
        $uuids = array_map(static fn (array $e): string => (string) ($e['uuid'] ?? ''), $entities);
        $this->assertContains($good->getUUID(), $uuids, 'the others are exported');
        $this->assertContains($template->getUUID(), $uuids, 'an entity whose template fails is in the archive as data');
        $this->assertFileDoesNotExist($folder . '/html/' . $template->getID() . '.html', 'with no page');
        $joined = implode("\n", array_map(static fn (array $line): string => $line[1], $logger->lines));
        $this->assertStringContainsString('RuntimeException', $joined);
        $this->assertStringContainsString('TypeError', $joined);
        $this->assertStringContainsString('LogicException', $joined, 'the template path is logged too');
        $this->assertStringContainsString($template->getUUID(), $joined);
        $this->assertStringContainsString($bad->getUUID(), $joined);
        $this->assertStringContainsString($worse->getUUID(), $joined);
        $this->assertStringNotContainsString('Does not render', $joined, 'a title is not a log line');
        $sql = (string) file_get_contents($folder . '/exported_data.sql');
        $this->assertStringContainsString($bad->getUUID(), $sql, 'it is still in the records');
    }

    public function testAnAttachmentOnAnotherOriginIsFetchedByTheRemoteClientWithTheSizeLimit(): void
    {
        $id = bin2hex(random_bytes(12)); // a file the store does not have
        $limit = 1000;
        $siteCalls = [];
        $remoteCalls = [];
        $site = new MockHttpClient(function (string $method, string $url) use (&$siteCalls): MockResponse {
            $siteCalls[] = $url;

            return new MockResponse('own bytes');
        });
        $remote = new MockHttpClient(function (string $method, string $url) use (&$remoteCalls, $limit): MockResponse {
            $remoteCalls[] = $url;

            return new MockResponse(str_contains($url, 'big') ? str_repeat('x', $limit + 1) : 'remote bytes');
        });
        $config = Idno::site()->config();
        $before = $config->webservice_max_download;
        $config->webservice_max_download = $limit;
        try {
            foreach (['http://elsewhere.example/a.png', 'http://elsewhere.example/big.png', Idno::site()->config()->getURL() . 'file/' . $id . '/own.png'] as $n => $url) {
                $post = new Entry();
                $post->title = 'Attachment ' . $n;
                $post->body = '<p>x</p>';
                $post->attachments = [['url' => $url, '_id' => substr($id, 0, 22) . '0' . $n, 'mime-type' => 'image/png']];
                $this->store($post, $this->admin());
            }
            $logger = $this->recordingLogger();

            [$folder] = $this->exportAndRead($this->exporter(null, null, $site, $remote, $logger));
        } finally {
            $config->webservice_max_download = $before;
        }

        $this->assertContains('http://elsewhere.example/a.png', $remoteCalls, 'another origin goes through the remote client');
        $this->assertNotContains('http://elsewhere.example/a.png', $siteCalls, 'and never the site client');
        $this->assertSame([Idno::site()->config()->getURL() . 'file/' . $id . '/own.png'], array_values(array_filter($siteCalls, static fn (string $u): bool => str_contains($u, '/own.png'))), 'the site\'s own origin uses the site client');
        $this->assertSame([], array_values(array_filter($remoteCalls, static fn (string $u): bool => str_contains($u, '/own.png'))));
        $files = glob($folder . '/files/readable/' . substr($id, 0, 22) . '*') ?: [];
        $names = array_map('basename', $files);
        $this->assertCount(2, $names, 'the remote file and the own one are in the archive; the file over the limit is not: ' . implode(',', $names));
        $this->assertNotEmpty(array_filter($files, static fn (string $f): bool => file_get_contents($f) === 'remote bytes'));
    }

    public function testASettingTheBootConfigurationDefinesIsNotWrittenToConfigJson(): void
    {
        $records = Idno::container()->get(RecordStore::class);
        $stored = $records->findLatest('config') ?? [];
        $boot = new BootConfig(['title' => 'from the boot configuration', 'url' => 'http://boot.example/', 'boot_only' => 'x'], false);
        // Config::save() copies what the boot configuration defines into the stored settings: it is the boot one's, not the stored one's.
        $this->assertArrayHasKey('title', $stored);

        [$folder] = $this->exportAndRead($this->exporter(null, null, null, null, null, $boot));

        $archived = json_decode((string) file_get_contents($folder . '/config.json'), true)['config'];
        foreach (array_keys($boot->all()) as $key) {
            $this->assertArrayNotHasKey($key, $archived, 'every key the boot configuration holds is left out');
        }
        $this->assertNotEmpty(array_diff_key($archived, $boot->all()), 'the rest of the stored settings stay');
    }

    public function testExportsWithoutReadingAFileWhole(): void
    {
        // A 64 MiB file under a memory limit of 32 MiB: it is streamed into the working folder, into the zip and
        // into the file store, never read whole. (The legacy application's own boot takes about 15 MiB.)
        $run = $this->probe('export_large', ['mib' => 64, 'login' => $this->admin()->getUUID()], [], ['memory_limit' => '32M']);

        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertIsArray($run['result'], $run['output']);
        $this->assertSame(64 * 1048576, $run['result']['file_size']);
        $this->assertGreaterThan(0, $run['result']['archive_size'], 'the archive was made');
        $this->assertTrue($run['result']['has_file'], 'and holds the file');
        $this->assertLessThan(32 * 1048576, $run['result']['peak']);
        // What the export added to the memory in use is nothing like the file's size (about 2 MiB were measured).
        $this->assertLessThan(8 * 1048576, $run['result']['peak'] - $run['result']['boot_peak']);
    }
}
