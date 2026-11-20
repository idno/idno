<?php

declare(strict_types=1);

namespace Tests\Import\Contract;

use Doctrine\DBAL\Connection;
use Idno\Storage\Files\FileStore;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Storage\SettingsRecordStore;
use Idno\Upgrade\Mongo\MongoDocument;
use Idno\Upgrade\Mongo\MongoImporter;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Storage\PrivateDatabase;
use Tests\Unit\Upgrade\Mongo\ArrayMongoSource;

/**
 * The MongoDB importer's behaviour, whatever the target database: a binding gives it an empty target
 * (target()) and says where the database differs (equatedUuids(), tooDeep()). The source is an
 * ArrayMongoSource and the file store a FileStore on the in-memory Flysystem adapter.
 *
 * Bound to an SQLite file in a temporary directory in the unit tier (Tests/Unit/Upgrade/Mongo/
 * MongoImporterTest) and to a private MySQL or MariaDB database of the rig's server
 * (Tests/Import/MongoImporterMySqlTest).
 */
abstract class MongoImporterContract extends TestCase
{
    protected const HOST = 'example.com';

    protected ?Connection $connection = null;

    protected ?FileStore $files = null;

    /**
     * A connection to a new, empty database (no table at all), removed by dropTarget().
     */
    abstract protected function target(): Connection;

    abstract protected function dropTarget(Connection $connection): void;

    /**
     * Pairs of uuids that differ in their bytes and that the database's collation equates.
     *
     * @return list<array{0: string, 1: string}>
     */
    abstract protected function equatedUuids(): array;

    /**
     * A nesting depth (of objects and arrays, the document itself the first) that the target refuses
     * a document at (or that has no JSON form at all).
     */
    abstract protected function tooDeep(): int;

    protected function setUp(): void
    {
        $this->connection = $this->target();
        $this->files = new FileStore(new Filesystem(new InMemoryFilesystemAdapter()), self::HOST);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->dropTarget($this->connection);
            $this->connection = null;
        }
    }

    // ------------------------------------------------------------------ the cases

    public function testEveryDocumentIsImportedOrARejectAndCountsAddUp(): void
    {
        $source = $this->site()
            ->add('entities', ['_id' => self::id(9), 'entity_subtype' => 'IdnoPlugins\\Status\\Status', 'body' => 'no uuid'])
            ->addUnreadable('entities', self::id(10), 'a value of type MongoDB\\BSON\\Binary cannot be imported', '{"_id":{"$oid":"' . self::id(10) . '"}}');

        $result = $this->import($source);

        $this->assertSame(['source' => 5, 'imported' => 3, 'rejected' => 2], $result['collections']['entities']);
        $this->assertSame(['source' => 1, 'imported' => 1, 'rejected' => 0], $result['collections']['reader']);
        $this->assertSame(['source' => 1, 'imported' => 1, 'rejected' => 0], $result['collections']['site']);
        $this->assertSame(['source' => 1, 'imported' => 1, 'rejected' => 0], $result['collections']['config']);
        foreach ($result['collections'] as $collection => $counts) {
            $this->assertSame($counts['source'], $counts['imported'] + $counts['rejected'], $collection);
        }
        $this->assertSame(3, $this->rows('idno_entities'));
        $this->assertSame(1, $this->rows('idno_reader'));
        $this->assertSame(1, $this->rows('idno_site'));
        $this->assertSame(2, $this->rejectCount('entities'));
        $this->assertSame(0, $this->rejectCount('config'));
    }

    public function testAnImportedRecordReadsBackAsTheSourceDocument(): void
    {
        $source = (new ArrayMongoSource())->add('entities', [
            '_id' => self::id(1),
            'uuid' => 'http://example.com/view/' . self::id(1),
            'owner' => 'http://example.com/view/' . self::id(2),
            'entity_subtype' => 'IdnoPlugins\\Status\\Status',
            'odd%2Efield%24name' => 'escaped',
            'annotations' => ['like' => ['http://example%2Ecom/1' => ['content' => 'liked', 'time' => 1700000000]]],
            'created' => 1700000000,
        ]);

        $this->import($source);

        $store = PrivateDatabase::recordStore($this->connection);
        $this->assertSame([
            '_id' => self::id(1),
            'uuid' => 'http://example.com/view/' . self::id(1),
            'owner' => 'http://example.com/view/' . self::id(2),
            'entity_subtype' => 'IdnoPlugins\\Status\\Status',
            'annotations' => ['like' => ['http://example.com/1' => ['content' => 'liked', 'time' => 1700000000]]],
            'created' => 1700000000,
            'odd.field$name' => 'escaped',
            'siteid' => null,
        ], $store->findById('entities', self::id(1)));
        $this->assertSame('status', $this->connection->fetchOne('select type from idno_entities where id = ?', [self::id(1)]), 'the type column holds the alias of entity_subtype');
    }

    public function testADocumentWithoutAUuidIsARejectWithItsJson(): void
    {
        $document = ['_id' => self::id(3), 'entity_subtype' => 'IdnoPlugins\\Status\\Status', 'body' => 'no uuid'];
        $this->import((new ArrayMongoSource())->add('entities', $document));

        $this->assertSame([[
            'collection' => 'entities',
            'legacy_uuid' => '',
            'legacy_id' => self::id(3),
            'reason' => 'the document has no uuid',
            'contents' => json_encode($document),
        ]], $this->rejects());
        $this->assertSame(0, $this->rows('idno_entities'));
    }

    public function testADocumentWithAnUnsupportedValueIsARejectWithItsReason(): void
    {
        $json = '{"_id":{"$oid":"' . self::id(4) . '"},"blob":{"$binary":{"base64":"AA==","subType":"00"}}}';
        $this->import((new ArrayMongoSource())->addUnreadable('entities', self::id(4), 'a value of type MongoDB\\BSON\\Binary cannot be imported', $json));

        $rejects = $this->rejects();
        $this->assertCount(1, $rejects);
        $this->assertSame('a value of type MongoDB\\BSON\\Binary cannot be imported', $rejects[0]['reason']);
        $this->assertSame($json, $rejects[0]['contents']);
        $this->assertSame(self::id(4), $rejects[0]['legacy_id']);
    }

    public function testASecondDocumentWithTheSameUuidIsTheReject(): void
    {
        $uuid = 'http://example.com/view/shared';
        $source = (new ArrayMongoSource())
            ->add('entities', ['_id' => self::id(6), 'uuid' => $uuid, 'body' => 'second'])
            ->add('entities', ['_id' => self::id(5), 'uuid' => $uuid, 'body' => 'first']);

        $result = $this->import($source);

        $this->assertSame(['source' => 2, 'imported' => 1, 'rejected' => 1], $result['collections']['entities']);
        $this->assertSame('first', PrivateDatabase::recordStore($this->connection)->findByUuid('entities', $uuid)['body'] ?? null, 'the one with the smaller _id is kept');
        $rejects = $this->rejects();
        $this->assertSame(self::id(6), $rejects[0]['legacy_id']);
        $this->assertSame($uuid, $rejects[0]['legacy_uuid']);
        $this->assertStringContainsString('same uuid', $rejects[0]['reason']);
    }

    public function testUuidsTheDatabaseEquatesAreOneRecord(): void
    {
        $source = new ArrayMongoSource();
        $n = 20;
        foreach ($this->equatedUuids() as [$first, $second]) {
            $source->add('entities', ['_id' => self::id($n++), 'uuid' => $first]);
            $source->add('entities', ['_id' => self::id($n++), 'uuid' => $second]);
        }

        $result = $this->import($source);

        $pairs = count($this->equatedUuids());
        $this->assertSame(['source' => 2 * $pairs, 'imported' => $pairs, 'rejected' => $pairs], $result['collections']['entities']);
        foreach ($this->rejects() as $reject) {
            $this->assertStringContainsString('same uuid', $reject['reason']);
        }
    }

    public function testAFourByteCharacterIsImported(): void
    {
        $document = ['_id' => self::id(7), 'uuid' => 'http://example.com/view/emoji', 'body' => 'Moving house 🏠 with 𝄞', 'title' => '😀'];
        $this->import((new ArrayMongoSource())->add('entities', $document));

        $this->assertSame('Moving house 🏠 with 𝄞', PrivateDatabase::recordStore($this->connection)->findById('entities', self::id(7))['body'] ?? null);
    }

    public function testADocumentNestedDeeperThanTheServerAcceptsIsARejectNotACrash(): void
    {
        $deep = 'bottom';
        for ($i = 1; $i < $this->tooDeep(); ++$i) {
            $deep = ['level' => $deep];
        }
        $source = (new ArrayMongoSource())
            ->add('entities', ['_id' => self::id(8), 'uuid' => 'http://example.com/view/deep', 'deep' => $deep])
            ->add('entities', ['_id' => self::id(9), 'uuid' => 'http://example.com/view/shallow', 'body' => 'fine']);

        $result = $this->import($source);

        $this->assertSame(['source' => 2, 'imported' => 1, 'rejected' => 1], $result['collections']['entities']);
        $this->assertSame(self::id(8), $this->rejects()[0]['legacy_id']);
    }

    public function testTheConfigurationBecomesTheSettings(): void
    {
        $config = ['_id' => self::id(11), 'title' => 'My site', 'plugins' => ['Status', 'Text'], 'odd%2Ekey' => true, 'site_secret' => 'secret'];
        $this->import((new ArrayMongoSource())->add('config', $config));

        $this->assertSame(
            ['_id' => self::id(11), 'title' => 'My site', 'plugins' => ['Status', 'Text'], 'site_secret' => 'secret', 'odd.key' => true],
            PrivateDatabase::recordStore($this->connection)->findLatest(SettingsRecordStore::COLLECTION),
        );
        $this->assertSame([], $this->rejects());
    }

    public function testAnOlderConfigurationDocumentIsARejectSuperseded(): void
    {
        $source = (new ArrayMongoSource())
            ->add('config', ['_id' => self::id(13), 'title' => 'Older', 'created' => 100])
            ->add('config', ['_id' => self::id(12), 'title' => 'Newer', 'created' => 200])
            ->add('config', ['_id' => self::id(14), 'title' => 'No date']);

        $result = $this->import($source);

        $this->assertSame(['source' => 3, 'imported' => 1, 'rejected' => 2], $result['collections']['config']);
        $this->assertSame('Newer', PrivateDatabase::recordStore($this->connection)->findLatest('config')['title'] ?? null);
        $rejects = $this->rejects();
        $this->assertEqualsCanonicalizing([self::id(13), self::id(14)], array_column($rejects, 'legacy_id'));
        $this->assertSame([MongoImporter::SUPERSEDED, MongoImporter::SUPERSEDED], array_column($rejects, 'reason'));
    }

    public function testTheConfigurationTheSourceNamesWinsWhateverItsId(): void
    {
        foreach ([self::id(15), self::id(16)] as $named) {
            $this->dropTarget($this->connection);
            $this->connection = $this->target();
            $source = (new ArrayMongoSource())
                ->add('config', ['_id' => self::id(15), 'title' => 'Fifteen', 'created' => 100])
                ->add('config', ['_id' => self::id(16), 'title' => 'Sixteen', 'created' => 200])
                ->loadConfiguration($named);

            $result = $this->import($source);

            $this->assertSame(['source' => 2, 'imported' => 1, 'rejected' => 1], $result['collections']['config']);
            $this->assertSame($named, PrivateDatabase::recordStore($this->connection)->findLatest('config')['_id'] ?? null);
            $this->assertSame([MongoImporter::SUPERSEDED], array_column($this->rejects(), 'reason'));
            $this->assertNotContains($named, array_column($this->rejects(), 'legacy_id'));
        }
    }

    /**
     * @return array<string, array{0: \Closure(ArrayMongoSource): ArrayMongoSource, 1: string}>
     */
    public static function unusableConfigurations(): array
    {
        return [
            'a key the settings table refuses' => [static fn (ArrayMongoSource $source): ArrayMongoSource => $source->add('config', ['_id' => self::id(17), 'title ' => 'ends in a space']), 'cannot end in a space'],
            'no plain form' => [static fn (ArrayMongoSource $source): ArrayMongoSource => $source->addUnreadable('config', self::id(17), 'a value of type MongoDB\\BSON\\Binary cannot be imported', '{}'), 'MongoDB\\BSON\\Binary'],
        ];
    }

    /**
     * @dataProvider unusableConfigurations
     *
     * @param \Closure(ArrayMongoSource): ArrayMongoSource $add
     */
    public function testAConfigurationTheSiteRanWithThatCannotBeTheSettingsStopsTheImport(\Closure $add, string $why): void
    {
        $source = $add((new ArrayMongoSource())->add('config', ['_id' => self::id(18), 'title' => 'A usable but superseded one']))->loadConfiguration(self::id(17));

        try {
            $this->import($source);
            $this->fail('Another configuration was taken in its place.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(self::id(17), $e->getMessage());
            $this->assertStringContainsString($why, $e->getMessage());
        }
        $this->assertSame([self::newestMigration()], (new SchemaMigrator($this->connection))->pending(), 'nothing is marked complete');
        $this->assertSame(0, $this->rows('idno_settings'));
    }

    public function testFilesAreStoredUnderTheirOwnIdsWithTheirMetadata(): void
    {
        $id = self::id(30);
        $source = (new ArrayMongoSource())->addFile(
            ['_id' => $id, 'length' => 11, 'chunkSize' => 261120, 'uploadDate' => '1700000000123', 'filename' => 'pixel.png', 'metadata' => ['filename' => 'pixel.png', 'mime_type' => 'image/png', 'attached_entity' => 'http://example.com/view/1']],
            'hello world',
        );

        $result = $this->import($source);

        $this->assertSame(['source' => 1, 'copied' => 1, 'skipped' => 0, 'bytes' => 11], $result['files']);
        $this->assertSame(self::HOST . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file', $this->files->path($id));
        $stored = $this->files->find($id);
        $this->assertNotNull($stored);
        $this->assertSame(
            ['filename' => 'pixel.png', 'mime_type' => 'image/png', 'attached_entity' => 'http://example.com/view/1', 'length' => 11, 'chunkSize' => 261120, 'uploadDate' => '1700000000123'],
            $stored->metadata,
        );
        $this->assertSame('hello world', stream_get_contents($this->files->readStream($id)));
    }

    public function testAFileWithTopLevelMetadataKeepsItsMimeType(): void
    {
        $id = self::id(31);
        $source = (new ArrayMongoSource())->addFile(['_id' => $id, 'length' => 3, 'filename' => '/var/uploads/old.txt', 'mime_type' => 'text/plain'], 'old');

        $this->import($source);

        $this->assertSame(['length' => 3, 'filename' => 'old.txt', 'mime_type' => 'text/plain'], $this->files->find($id)?->metadata);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function withAndWithoutMd5(): array
    {
        return ['GridFS recorded an md5' => [true], 'GridFS recorded none' => [false]];
    }

    /**
     * @dataProvider withAndWithoutMd5
     */
    public function testAFileAlreadyThereWithTheSameLengthIsSkipped(bool $md5): void
    {
        $id = self::id(32);
        $file = ['_id' => $id, 'length' => 5, 'filename' => 'a.txt', 'metadata' => ['mime_type' => 'text/plain']] + ($md5 ? ['md5' => md5('bytes')] : []);
        $this->files->storeAs($id, self::stream('bytes'), MongoDocument::fileMetadata($file));

        $result = $this->import((new ArrayMongoSource())->addFile($file, 'bytes'));

        $this->assertSame(['source' => 1, 'copied' => 0, 'skipped' => 1, 'bytes' => 0], $result['files']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function wrongFiles(): array
    {
        return [
            'a length GridFS does not hold' => [['length' => 10], 'bytes were read from GridFS'],
            'an md5 the bytes do not have' => [['length' => 5, 'md5' => md5('other')], 'md5'],
        ];
    }

    /**
     * @dataProvider wrongFiles
     *
     * @param array<string, mixed> $fields
     */
    public function testAWrongSizeOrMd5StopsTheImport(array $fields, string $message): void
    {
        $source = (new ArrayMongoSource())->addFile(['_id' => self::id(33), 'filename' => 'a.txt'] + $fields, 'bytes');

        try {
            $this->import($source);
            $this->fail('The import went on.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(self::id(33), $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertNull($this->files->find(self::id(33)), 'nothing of a file that did not copy correctly is left');
        $this->assertNotSame([], (new SchemaMigrator($this->connection))->pending(), 'a stopped import leaves the newest migration pending');
    }

    public function testATargetThatIsNotEmptyIsRefusedAndUntouched(): void
    {
        // A migrated database.
        (new SchemaMigrator($this->connection))->migrate();
        $this->connection->executeStatement("insert into idno_settings (name, value, position) values ('title', '\"Kept\"', 0)");
        $before = $this->snapshot();
        $this->assertRefused();
        $this->assertSame($before, $this->snapshot());

        // A database holding any other table.
        $this->dropTarget($this->connection);
        $this->connection = $this->target();
        $this->connection->executeStatement('create table other_application (x int)');
        $before = $this->snapshot();
        $this->assertRefused();
        $this->assertSame($before, $this->snapshot());
    }

    public function testTheNewestMigrationIsRecordedLast(): void
    {
        $newest = self::newestMigration();
        $failing = $this->site(failBeforeLastFile: true)
            ->addFile(['_id' => self::id(35), 'length' => 1, 'filename' => 'a'], 'a')
            ->addFile(['_id' => self::id(36), 'length' => 1, 'filename' => 'b'], 'b');
        try {
            $this->import($failing);
            $this->fail('The source did not fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The source failed before its last file.', $e->getMessage());
        }
        $this->assertSame([$newest], (new SchemaMigrator($this->connection))->pending(), 'everything else ran; the newest is left');

        $this->dropTarget($this->connection);
        $this->connection = $this->target();
        $this->import($this->site()->addFile(['_id' => self::id(35), 'length' => 1, 'filename' => 'a'], 'a'));
        $this->assertSame([], (new SchemaMigrator($this->connection))->pending());
    }

    public function testOtherCollectionsAreNamedAndNotImported(): void
    {
        $source = $this->site()
            ->add('sessions', ['_id' => self::id(40), 'data' => 'x'])
            ->add('activitypub_inbox', ['_id' => self::id(41), 'uuid' => 'http://example.com/inbox/1'])
            ->addFile(['_id' => self::id(42), 'length' => 1, 'filename' => 'a'], 'a');

        $result = $this->import($source);

        $this->assertSame(['activitypub_inbox', 'sessions'], $result['notImported']);
        $this->assertFalse((new SchemaCatalog($this->connection))->hasTable('idno_activitypub_inbox'));
        $this->assertSame(0, $this->rows('idno_sessions'));
        $this->assertSame(0, $this->rejectCount('sessions') + $this->rejectCount('activitypub_inbox'));
    }

    public function testASourceWrittenToDuringTheImportStopsIt(): void
    {
        $source = $this->site()->claimCount('entities', 7);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still being written to');
        $this->import($source);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A small site: three entities, a feed item, the site record and the configuration.
     */
    protected function site(bool $failBeforeLastFile = false): ArrayMongoSource
    {
        return (new ArrayMongoSource($failBeforeLastFile))
            ->add('entities', ['_id' => self::id(1), 'uuid' => 'http://example.com/view/' . self::id(1), 'entity_subtype' => 'Idno\\Entities\\User', 'handle' => 'alice', 'owner' => false, 'created' => 1700000000])
            ->add('entities', ['_id' => self::id(2), 'uuid' => 'http://example.com/view/' . self::id(2), 'entity_subtype' => 'IdnoPlugins\\Status\\Status', 'body' => 'Hello', 'owner' => 'http://example.com/view/' . self::id(1), 'created' => 1700000001])
            ->add('entities', ['_id' => self::id(3), 'uuid' => 'http://example.com/view/' . self::id(3), 'entity_subtype' => 'IdnoPlugins\\Text\\Entry', 'title' => 'An article', 'owner' => 'http://example.com/view/' . self::id(1), 'created' => 1700000002])
            ->add('reader', ['_id' => self::id(4), 'uuid' => 'http://example.com/reader/1', 'entity_subtype' => 'Idno\\Entities\\Reader\\FeedItem'])
            ->add('site', ['_id' => self::id(5), 'uuid' => 'http://example.com/view/' . self::id(5), 'entity_subtype' => 'Idno\\Core\\Site', 'domain' => 'example.com'])
            ->add('config', ['_id' => self::id(6), 'title' => 'Example']);
    }

    /**
     * @return array{collections: array<string, array{source: int, imported: int, rejected: int}>, files: array{source: int, copied: int, skipped: int, bytes: int}, notImported: list<string>}
     */
    protected function import(ArrayMongoSource $source): array
    {
        $this->assertNotNull($this->connection);
        $this->assertNotNull($this->files);

        return (new MongoImporter($source, $this->connection, $this->files, new NullLogger()))->run(static function (string $line): void {
        });
    }

    /**
     * A 24-hex-digit id ending in $n.
     */
    protected static function id(int $n): string
    {
        return sprintf('6aff2dc3a96cd4f9%08x', $n);
    }

    /**
     * @return resource
     */
    protected static function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    protected static function newestMigration(): string
    {
        $versions = array_map(
            static fn (string $file): string => substr(basename($file, '.php'), strlen('Version')),
            glob(dirname(__DIR__, 3) . '/Idno/Storage/Migrations/Version*.php') ?: [],
        );
        sort($versions);

        return (string) end($versions);
    }

    protected function rows(string $table): int
    {
        return (int) $this->connection?->fetchOne('select count(*) from ' . $table);
    }

    protected function rejectCount(string $collection): int
    {
        return (int) $this->connection?->fetchOne('select count(*) from idno_upgrade_rejects where collection = ?', [$collection]);
    }

    /**
     * @return list<array{collection: string, legacy_uuid: string, legacy_id: string, reason: string, contents: string}>
     */
    protected function rejects(): array
    {
        return array_map(
            static fn (array $row): array => array_map('strval', $row),
            $this->connection?->fetchAllAssociative('select collection, legacy_uuid, legacy_id, reason, contents from idno_upgrade_rejects order by n') ?? [],
        );
    }

    /**
     * Every table of the target with its rows.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ((new SchemaCatalog($this->connection))->tables() as $table) {
            $snapshot[$table] = $this->connection->fetchAllAssociative('select * from ' . $table);
        }

        return $snapshot;
    }

    private function assertRefused(): void
    {
        try {
            $this->import($this->site()->addFile(['_id' => self::id(34), 'length' => 1, 'filename' => 'a'], 'a'));
            $this->fail('A target that is not empty was written to.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('new, empty database', $e->getMessage());
        }
        $this->assertNull($this->files?->find(self::id(34)), 'no file was written');
    }
}
