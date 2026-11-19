<?php

declare(strict_types=1);

namespace Idno\Upgrade\Mongo;

use Doctrine\DBAL\Connection;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\Files\FileStore;
use Idno\Storage\IndexedFields;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Storage\SettingsRecordStore;
use Idno\Upgrade\RowWriter;
use Idno\Upgrade\ServerVersion;
use Psr\Log\LoggerInterface;

/**
 * Moves an Idno site's data out of its MongoDB database (a MongoSource) into a NEW, EMPTY SQL
 * database (MySQL, MariaDB or SQLite), and its GridFS files into a FileStore. In order:
 *
 *  1. the target must hold no table at all, else nothing is done (a \RuntimeException);
 *  2. every schema migration but the newest, then the indexes the core entity classes (Idno/) declare;
 *  3. `entities`, `reader` and `site`, each in `_id` order: a document needs an `_id` and a non-empty
 *     string `uuid`, its keys are unescaped (MongoDocument), and it is saved through a JsonRecordStore
 *     whose RecordDefaults fill in nothing (ImportRecordDefaults), with the entities' type aliases
 *     (AttributeTypeMap) and the site's search text (the quotes normalised as the site normalises them),
 *     then read back and compared with what the store writes (the document, with `siteid` null and
 *     `owner` '' when they are empty, as every save of the site's stores does). A document without an
 *     `_id` or a uuid, one with a value that has no JSON form, one whose `_id` (under the database's
 *     collation) or uuid another imported record holds, one the store refuses: a row of
 *     `idno_upgrade_rejects` with its Extended JSON and why. Of two records with one uuid, the one
 *     with the smaller `_id` is kept;
 *  4. `config`: the document the MongoDB backend loaded (Idno\Data\Mongo::getAnyRecord(): the greatest
 *     `created`, in MongoDB's order of types, a missing `created` lowest; among equals, which MongoDB
 *     leaves undefined, the greatest `_id`) whose keys the settings table can hold becomes the settings
 *     (`idno_settings`, written as SettingsRecordStore::rowsOf() lays it out, no defaults, read back
 *     through a SettingsRecordStore); every other `config` document is a reject, `superseded
 *     configuration`, or why it could not be the settings;
 *  5. the GridFS files, in `_id` order: each stored under its own id (FileStore::storeAs()) with its
 *     metadata, streamed, and verified: its size, the SHA-256 of the stored bytes against the bytes
 *     read, and the md5 GridFS recorded, when it did. A file already in the store with the same
 *     length, the same metadata and the same bytes is skipped (not written again);
 *  6. the counts: every collection's rows and rejects in the target must be what was imported and
 *     rejected, and what was read must be what the source counted when the import began (a site still
 *     writing to MongoDB makes them differ);
 *  7. LAST, the newest migration, so that a site pointed at the target stays closed (a migration is
 *     pending) until everything is in.
 *
 * Every other collection is named in the result (`notImported`) and not imported. A \RuntimeException
 * (its message is for the console) stops the import at the first problem it cannot count: a target
 * that is not empty, a read-back that differs, a file whose size or bytes differ, counts that do not
 * add up; any other failure (the database, the source, the filesystem) is thrown as it is. A stopped
 * import leaves the target unusable (its newest migration is not recorded): import again into a new,
 * empty database. Each reject is logged (a warning on the logger) and reported.
 *
 * It runs while the legacy application is NOT booted: it uses nothing of it. Built with new by the
 * import command: not a container service.
 */
final class MongoImporter
{
    /** The collections imported record by record, in this order. */
    public const COLLECTIONS = ['entities', 'reader', 'site'];

    public const CONFIG = 'config';

    /** GridFS's collections: imported as files. */
    public const GRIDFS = ['fs.files', 'fs.chunks'];

    public const SUPERSEDED = 'superseded configuration';

    /** A site keeps its configuration in one document; more than this many stops the import. */
    public const MAX_CONFIGS = 1000;

    /** Records saved per transaction. */
    private const BATCH = 200;

    /** How often (in documents) a collection's progress is reported. */
    private const REPORT_EVERY = 1000;

    /** The bytes read from a source stream at a time. */
    private const PIECE = 1 << 20;

    private ?array $settings = null;

    private ?Dialect $dialect = null;

    public function __construct(
        private readonly MongoSource $source,
        private readonly Connection $target,
        private readonly FileStore $files,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * @param \Closure(string): void $report receives one line at a time (progress, rejects)
     *
     * @return array{collections: array<string, array{source: int, imported: int, rejected: int}>, files: array{source: int, copied: int, skipped: int, bytes: int}, notImported: list<string>}
     *
     * @throws \RuntimeException when the target is not empty, a read-back differs, a file's size or bytes differ, or the counts do not add up
     */
    public function run(\Closure $report): array
    {
        $dialect = $this->dialect = $this->targetDialect();
        $catalog = new SchemaCatalog($this->target);
        $tables = $catalog->tables();
        if ($tables !== []) {
            throw new \RuntimeException(sprintf(
                'The target database is not empty (it holds %s): the import needs a new, empty database. Nothing was written.',
                implode(', ', $tables),
            ));
        }

        // The source is asked first, so that a MongoDB server that cannot be reached (or refuses the
        // credentials) stops the import before anything is written to the target.
        $all = $this->source->collections();

        $migrator = new SchemaMigrator($this->target);
        $migrator->migrate(exceptLast: true);
        $catalog->forget();
        $indexes = new IndexedFields($this->target, $dialect, $catalog);
        $indexes->reconcileDirectory(dirname(__DIR__, 2));
        if ($indexes->conflicts() !== []) {
            throw new \RuntimeException('The new database\'s field indexes differ from what the entity classes declare: ' . implode('; ', $indexes->conflicts()));
        }
        $report('Created the schema and the field indexes.');

        $store = new JsonRecordStore(
            $this->target,
            $dialect,
            new ImportRecordDefaults(),
            new AttributeTypeMap(),
            $catalog,
            self::uncurlQuotes(),
            true,
            false,
        );

        $collections = [];
        foreach (self::COLLECTIONS as $collection) {
            $collections[$collection] = $this->importCollection($collection, $store, $report);
        }
        $collections[self::CONFIG] = $this->importConfig($dialect, $store, $report);
        $files = $this->importFiles($report);

        $this->verifyCounts($collections, $dialect);

        $migrator->migrate();
        if ($migrator->pending() !== []) {
            throw new \RuntimeException('The newest schema migration could not be recorded.');
        }

        return [
            'collections' => $collections,
            'files' => $files,
            'notImported' => array_values(array_diff($all, self::COLLECTIONS, [self::CONFIG], self::GRIDFS)),
        ];
    }

    /**
     * The configuration document that became the settings, once the import has reached the files
     * (null before, or when there was none).
     *
     * @return array<array-key, mixed>|null
     */
    public function settings(): ?array
    {
        return $this->settings;
    }

    /**
     * @param \Closure(string): void $report
     *
     * @return array{source: int, imported: int, rejected: int}
     */
    private function importCollection(string $collection, JsonRecordStore $store, \Closure $report): array
    {
        $counted = $this->source->count($collection);
        $read = $imported = $rejected = 0;
        $this->target->beginTransaction();
        try {
            foreach ($this->source->documents($collection) as $item) {
                ++$read;
                $reason = $this->importDocument($collection, $item, $store);
                if ($reason === null) {
                    ++$imported;
                } else {
                    ++$rejected;
                    $this->reject($collection, $item, $reason, $report);
                }
                if ($read % self::BATCH === 0) {
                    $this->target->commit();
                    $this->target->beginTransaction();
                }
                if ($read % self::REPORT_EVERY === 0) {
                    $report(sprintf('%s: %d of %d read', $collection, $read, $counted));
                }
            }
            $this->target->commit();
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
        if ($read !== $counted) {
            throw new \RuntimeException(sprintf(
                'The collection %s held %d documents when the import began and %d were read: the MongoDB database is still being written to. Stop every process of the site and import again into a new, empty database.',
                $collection,
                $counted,
                $read,
            ));
        }
        $report(sprintf('%s: %d imported, %d rejected (of %d).', $collection, $imported, $rejected, $read));

        return ['source' => $read, 'imported' => $imported, 'rejected' => $rejected];
    }

    /**
     * Saves one document: null when it is imported, else why it is a reject.
     *
     * @param array{id: string, document: ?array, reason: ?string, json: string} $item
     */
    private function importDocument(string $collection, array $item, JsonRecordStore $store): ?string
    {
        if ($item['document'] === null) {
            return $item['reason'] ?? 'the document cannot be read';
        }
        try {
            $document = MongoDocument::unescapeKeys($item['document']);
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }
        $id = $document['_id'] ?? null;
        if (!is_string($id) && !is_int($id) || empty($id)) {
            return 'the document has no _id the database can keep';
        }
        $id = (string) $id;
        $uuid = $document['uuid'] ?? null;
        if (!is_string($uuid) || empty($uuid)) {
            return 'the document has no uuid';
        }
        if (json_encode($document) === false) {
            return 'the document cannot be encoded as JSON: ' . json_last_error_msg();
        }
        // The store saves by id: an id another record holds (under the database's collation) would
        // replace that record.
        $holder = $store->findById($collection, $id);
        if ($holder !== null) {
            return sprintf('another record has the same _id under the database\'s collation (%s)', self::text($holder['_id'] ?? ''));
        }

        try {
            $saved = $store->save($collection, $document);
        } catch (\InvalidArgumentException $e) {
            return 'the record cannot be indexed: ' . $e->getMessage();
        }
        if ($saved === false) {
            $other = $store->findByUuid($collection, $uuid);
            if ($other !== null && (string) ($other['_id'] ?? '') !== $id) {
                return sprintf('another record has the same uuid under the database\'s collation (%s)', self::text($other['_id'] ?? ''));
            }

            return 'the database refused the record';
        }

        // What the store wrote: the document, with what every save of the site's stores fills in.
        $written = $document;
        if (empty($written['siteid'])) {
            $written['siteid'] = null;
        }
        if (empty($written['owner'])) {
            $written['owner'] = '';
        }
        $expected = json_decode((string) json_encode($written), true);
        $back = $store->findById($collection, $saved);
        if ($saved !== $id || $back !== $expected) {
            throw new \RuntimeException(sprintf(
                'The %s record %s did not read back from the new database as it was written: the import stopped. Import again into a new, empty database.',
                $collection,
                $id,
            ));
        }

        return null;
    }

    /**
     * @param \Closure(string): void $report
     *
     * @return array{source: int, imported: int, rejected: int}
     */
    private function importConfig(Dialect $dialect, JsonRecordStore $store, \Closure $report): array
    {
        $counted = $this->source->count(self::CONFIG);
        // Every document read first (a site has one): the choice needs them all.
        $candidates = [];
        $rejects = [];
        $read = 0;
        foreach ($this->source->documents(self::CONFIG) as $item) {
            if (++$read > self::MAX_CONFIGS) {
                throw new \RuntimeException(sprintf('The config collection holds more than %d documents: a site keeps its configuration in one. Remove the stale ones and import again into a new, empty database.', self::MAX_CONFIGS));
            }
            if ($item['document'] === null) {
                $rejects[] = [$item, $item['reason'] ?? 'the document cannot be read'];
                continue;
            }
            try {
                $document = MongoDocument::unescapeKeys($item['document']);
                SettingsRecordStore::rowsOf($document);
            } catch (\UnexpectedValueException | \InvalidArgumentException $e) {
                $rejects[] = [$item, 'cannot be the settings: ' . $e->getMessage()];
                continue;
            }
            $candidates[] = [$item, $document];
        }
        if ($read !== $counted) {
            throw new \RuntimeException(sprintf('The config collection held %d documents when the import began and %d were read: stop every process of the site and import again into a new, empty database.', $counted, $read));
        }
        usort($candidates, static fn (array $a, array $b): int => self::loadOrder($b[1], $a[1]));

        $chosen = array_shift($candidates);
        foreach ($candidates as [$item]) {
            $rejects[] = [$item, self::SUPERSEDED];
        }

        $this->target->beginTransaction();
        try {
            if ($chosen !== null) {
                $this->writeSettings($chosen[1], $dialect);
            }
            foreach ($rejects as [$item, $reason]) {
                $this->reject(self::CONFIG, $item, $reason, $report);
            }
            $this->target->commit();
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        if ($chosen !== null) {
            $settings = new SettingsRecordStore($store, $this->target, $dialect, new ImportRecordDefaults());
            $expected = json_decode((string) json_encode($chosen[1]), true);
            if ($settings->findLatest(self::CONFIG) !== $expected) {
                throw new \RuntimeException('The configuration did not read back from the new database as it was written: the import stopped. Import again into a new, empty database.');
            }
            $this->settings = $chosen[1];
        }
        $imported = $chosen === null ? 0 : 1;
        $report(sprintf('config: %d imported as the settings, %d rejected (of %d).', $imported, count($rejects), $read));

        return ['source' => $read, 'imported' => $imported, 'rejected' => count($rejects)];
    }

    /**
     * The settings rows, laid out as SettingsRecordStore lays them out, with no defaults.
     *
     * @param array<array-key, mixed> $document
     */
    private function writeSettings(array $document, Dialect $dialect): void
    {
        $table = $dialect->quoteIdentifier(SettingsRecordStore::TABLE);
        $insert = 'insert into ' . $table . ' (' . implode(', ', array_map($dialect->quoteIdentifier(...), SettingsRecordStore::COLUMNS)) . ') values (?, ?, ?)';
        $this->target->executeStatement('delete from ' . $table);
        $position = 0;
        foreach (SettingsRecordStore::rowsOf($document) as $name => $json) {
            $this->target->executeStatement($insert, [(string) $name, $json, (string) $position++]);
        }
    }

    /**
     * @param \Closure(string): void $report
     *
     * @return array{source: int, copied: int, skipped: int, bytes: int}
     */
    private function importFiles(\Closure $report): array
    {
        $counted = $this->source->fileCount();
        $read = $copied = $skipped = $bytes = 0;
        foreach ($this->source->files() as $file) {
            ++$read;
            try {
                if ($this->importFile($file)) {
                    ++$copied;
                    $bytes += $file['length'];
                } else {
                    ++$skipped;
                }
            } finally {
                if (is_resource($file['stream'])) {
                    fclose($file['stream']);
                }
            }
            if ($read % self::REPORT_EVERY === 0) {
                $report(sprintf('files: %d of %d', $read, $counted));
            }
        }
        if ($read !== $counted) {
            throw new \RuntimeException(sprintf('GridFS held %d files when the import began and %d were read: stop every process of the site and import again into a new, empty database.', $counted, $read));
        }
        $report(sprintf('files: %d copied, %d already there (of %d), %d bytes.', $copied, $skipped, $read, $bytes));

        return ['source' => $read, 'copied' => $copied, 'skipped' => $skipped, 'bytes' => $bytes];
    }

    /**
     * Copies and verifies one file: true when it was written, false when it was there already.
     *
     * @param array{id: string, length: int, md5: ?string, metadata: array, stream: resource} $file
     */
    private function importFile(array $file): bool
    {
        $id = $file['id'];
        if (preg_match('/^[a-f0-9]{24}(?:[a-f0-9]{8})?\z/i', $id) !== 1) {
            throw new \RuntimeException(sprintf('The GridFS file %s has an id that is no file id, so the site could never serve it: remove it from GridFS and import again into a new, empty database.', self::text($id)));
        }
        $existing = $this->files->find($id);
        if ($existing !== null && $existing->size === $file['length']) {
            $same = $file['md5'] !== null
                ? $this->storedHash($id, 'md5') === $file['md5']
                : $this->storedHash($id, 'sha256') === $this->sourceHash($file['stream']);
            if ($same && $existing->metadata === $file['metadata']) {
                return false;
            }
            if ($file['md5'] === null) {
                // The source stream has been read to compare it: it cannot be copied in this run.
                throw new \RuntimeException(sprintf('The file %s is in the upload directory already, with the same length but other bytes or metadata: remove it (%s and its .data file) and import again into a new, empty database.', $id, $this->files->path($id)));
            }
        }

        $sha256 = hash_init('sha256');
        $md5 = hash_init('md5');
        $length = 0;
        $source = $file['stream'];
        $pieces = (static function () use ($source, $sha256, $md5, &$length): \Generator {
            while (!feof($source)) {
                $piece = fread($source, self::PIECE);
                if ($piece === false) {
                    throw new \RuntimeException('The file could not be read from MongoDB.');
                }
                if ($piece === '') {
                    continue;
                }
                hash_update($sha256, $piece);
                hash_update($md5, $piece);
                $length += strlen($piece);
                yield $piece;
            }
        })();
        $stream = ChunkStream::open($pieces);
        try {
            $this->files->storeAs($id, $stream, $file['metadata']);
        } catch (\Throwable $e) {
            try {
                $this->files->delete($id);
            } catch (\Throwable) {
            }

            throw new \RuntimeException(sprintf('The file %s could not be copied: %s', $id, $e->getMessage()), 0, $e);
        } finally {
            fclose($stream);
        }

        $stored = $this->files->find($id);
        $problem = match (true) {
            $length !== $file['length'] => sprintf('%d bytes were read from GridFS, which records %d', $length, $file['length']),
            $stored === null => 'it is not in the upload directory after it was written',
            $stored->size !== $file['length'] => sprintf('the upload directory holds %d bytes of its %d', $stored->size, $file['length']),
            $file['md5'] !== null && hash_final($md5) !== $file['md5'] => 'its bytes do not have the md5 GridFS recorded',
            $this->storedHash($id, 'sha256') !== hash_final($sha256) => 'the bytes in the upload directory are not the bytes read from GridFS',
            $stored->metadata !== $file['metadata'] => 'its metadata did not read back as it was written',
            default => null,
        };
        if ($problem !== null) {
            throw new \RuntimeException(sprintf('The file %s did not copy correctly (%s): the import stopped.', $id, $problem));
        }

        return true;
    }

    /**
     * @param resource $stream
     */
    private function sourceHash($stream): string
    {
        $context = hash_init('sha256');
        while (!feof($stream)) {
            $piece = fread($stream, self::PIECE);
            if ($piece === false) {
                throw new \RuntimeException('A file could not be read from MongoDB.');
            }
            hash_update($context, $piece);
        }

        return hash_final($context);
    }

    private function storedHash(string $id, string $algorithm): string
    {
        $stream = $this->files->readStream($id);
        try {
            $context = hash_init($algorithm);
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * The target's rows and rejects must be what this run imported and rejected.
     *
     * @param array<string, array{source: int, imported: int, rejected: int}> $collections
     */
    private function verifyCounts(array $collections, Dialect $dialect): void
    {
        foreach ($collections as $collection => $counts) {
            if ($counts['imported'] + $counts['rejected'] !== $counts['source']) {
                throw new \RuntimeException(sprintf('The %s counts do not add up: the import stopped.', $collection));
            }
            $table = $collection === self::CONFIG ? null : JsonRecordStore::tableName($collection);
            $rows = $table === null
                ? ((int) $this->target->fetchOne('select count(*) from ' . $dialect->quoteIdentifier(SettingsRecordStore::TABLE)) > 0 ? 1 : 0)
                : (int) $this->target->fetchOne('select count(*) from ' . $dialect->quoteIdentifier($table));
            $rejects = (int) $this->target->fetchOne(
                'select count(*) from ' . $dialect->quoteIdentifier(RowWriter::REJECTS) . ' where ' . $dialect->quoteIdentifier('collection') . ' = ?',
                [$collection],
            );
            if ($rows !== $counts['imported'] || $rejects !== $counts['rejected']) {
                throw new \RuntimeException(sprintf(
                    'The new database holds %d %s records and %d rejects where %d were imported and %d rejected: the import stopped.',
                    $rows,
                    $collection,
                    $rejects,
                    $counts['imported'],
                    $counts['rejected'],
                ));
            }
        }
    }

    /**
     * Writes a reject (inside the caller's transaction), logs it and reports it.
     *
     * @param array{id: string, document: ?array, reason: ?string, json: string} $item
     * @param \Closure(string): void $report
     */
    private function reject(string $collection, array $item, string $reason, \Closure $report): void
    {
        $uuid = is_array($item['document']) && is_string($item['document']['uuid'] ?? null) ? $item['document']['uuid'] : '';
        $reason = RowWriter::reason($reason);
        $dialect = $this->dialect ?? throw new \LogicException('The import has not started.');
        $this->target->executeStatement(
            'insert into ' . $dialect->quoteIdentifier(RowWriter::REJECTS)
            . ' (' . implode(', ', array_map($dialect->quoteIdentifier(...), ['collection', 'legacy_uuid', 'legacy_id', 'reason', 'contents'])) . ') values (?, ?, ?, ?, ?)',
            [$collection, mb_substr($uuid, 0, 255), mb_substr($item['id'], 0, 36), $reason, $item['json']],
        );
        $line = sprintf('Rejected the %s document %s: %s', $collection, self::text($item['id']), $reason);
        $this->logger->warning($line);
        $report($line);
    }

    /**
     * Which of two configuration documents MongoDB's getAnyRecord() (sort by `created`, descending)
     * puts first: > 0 when $a comes after $b in ascending order.
     *
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     */
    private static function loadOrder(array $a, array $b): int
    {
        $first = $a['created'] ?? null;
        $second = $b['created'] ?? null;
        $order = self::typeRank($first) <=> self::typeRank($second);
        if ($order === 0) {
            $order = is_string($first) && is_string($second) ? strcmp($first, $second) : $first <=> $second;
        }

        return $order !== 0 ? $order : strcmp((string) ($a['_id'] ?? ''), (string) ($b['_id'] ?? ''));
    }

    /**
     * MongoDB's order of BSON types, for the values a converted document holds (a date is an int by then).
     */
    private static function typeRank(mixed $value): int
    {
        return match (true) {
            $value === null => 1,
            is_int($value), is_float($value) => 2,
            is_string($value) => 3,
            is_array($value) && array_is_list($value) => 5,
            is_array($value) => 4,
            is_bool($value) => 6,
            default => 0,
        };
    }

    /**
     * The dialect of the target, whose server must be one the schema supports.
     */
    private function targetDialect(): Dialect
    {
        $driver = $this->target->getParams()['driver'] ?? null;
        if ($driver === 'pdo_sqlite') {
            return new SqliteDialect();
        }
        if ($driver !== 'pdo_mysql') {
            throw new \LogicException('The import writes to MySQL, MariaDB or SQLite only.');
        }
        $refusal = ServerVersion::refusal($this->target->getServerVersion());
        if ($refusal !== null) {
            throw new \RuntimeException($refusal->text);
        }

        return new MySqlDialect();
    }

    /**
     * The site's search-text normaliser (Idno\Core\Language::uncurlQuotes(), which the record store of
     * the site applies when it saves), called on an instance made without the constructor: the method
     * uses nothing of the instance, and the constructor needs the legacy application.
     *
     * @return \Closure(string): string
     */
    private static function uncurlQuotes(): \Closure
    {
        $language = (new \ReflectionClass(\Idno\Core\Language::class))->newInstanceWithoutConstructor();

        return static fn (string $text): string => (string) $language->uncurlQuotes($text);
    }

    private function rollBack(): void
    {
        try {
            if ($this->target->isTransactionActive()) {
                $this->target->rollBack();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * A value from the source for a console line: control characters replaced, at most 200 bytes.
     */
    private static function text(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : get_debug_type($value);

        return substr((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $text), 0, 200);
    }
}
