<?php

declare(strict_types=1);

namespace Idno\Upgrade\Mongo;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

/**
 * The MongoSource of a real MongoDB database, on the mongodb extension alone (MongoDB\Driver\Manager;
 * never the mongodb/mongodb library, which a site moving off MongoDB need not have).
 *
 *  - A document's values: an ObjectId becomes its 24 hex digits, a UTCDateTime its epoch seconds (an
 *    int, rounded down), an Int64 an int; any other BSON type object (binary data, a regular
 *    expression, a decimal, a timestamp, ...) makes the document a reject, `reason` `a value of type
 *    <class> cannot be imported`. `json` is the document's canonical Extended JSON, exact whatever it
 *    holds.
 *  - Documents and files are read a page at a time (`$pageSize`, by `_id` range: `_id` greater than
 *    the last one read, sorted by `_id`, limited), never through one long-lived cursor, which would sit
 *    idle on the server while a page is imported and could pass its idle-cursor timeout. Documents
 *    whose `_id` is not an ObjectId (Idno never makes one) come first, in `_id` order, paged by skip;
 *    then the ObjectIds, in `_id` order.
 *  - A file's metadata is what Idno\Files\MongoDBFileSystem::findOne() put in `$file->metadata`
 *    (MongoDocument::fileMetadata()), a UTCDateTime as its string (epoch milliseconds) at any depth,
 *    ObjectIds and 64-bit integers converted as in documents. A file the site
 *    could never have served stops the import with a \RuntimeException naming it: an `_id` that is not
 *    an ObjectId, a length that is not a whole number, a value of another type in its metadata.
 *  - A file's stream yields the `data` of its `fs.chunks` in order of `n` (whatever order they are
 *    stored in), fetched one at a time (batches of one chunk, so one chunk is in memory); a chunk
 *    missing from the sequence, a chunk stored twice, or one whose data is not binary, throws a
 *    \RuntimeException from the read. (A missing LAST chunk only makes the bytes short: the importer
 *    checks the length.)
 *  - No message tells anyone to change MongoDB: it is the site's only copy (see problem()).
 *  - loadedConfigurationId() runs the query Idno\Data\Mongo::getAnyRecord('config') ran.
 *
 * Built with new (or connect()) by the import command: not a container service.
 */
final class ExtMongoSource implements MongoSource
{
    private const TO_ARRAYS = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    /** The documents (or files) read at a time. */
    public const PAGE_SIZE = 100;

    /** Every _id that is not an ObjectId. */
    private const NOT_OBJECT_IDS = ['_id' => ['$not' => ['$type' => 'objectId']]];

    public function __construct(
        private readonly Manager $manager,
        private readonly string $database,
        private readonly int $pageSize = self::PAGE_SIZE,
    )
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('A page holds at least one document.');
        }
    }

    /**
     * Why a GridFS file stops the import, said so that nobody deletes their only copy in a hurry.
     */
    public static function problem(string $id, string $what): string
    {
        return sprintf(
            'The GridFS file %s %s. Nothing in MongoDB was changed. Back the MongoDB database up (mongodump) before you remove or repair that file, then run the import again on a new, empty database.',
            $id,
            $what,
        );
    }

    /**
     * The database as Idno\Data\Mongo::init() opened it: the URI, and the user, password and
     * authentication database when they are set.
     */
    public static function connect(string $uri, string $database, ?string $user = null, ?string $password = null, ?string $authSource = null): self
    {
        $options = array_filter(['authSource' => $authSource, 'username' => $user, 'password' => $password], static fn (?string $value): bool => $value !== null && $value !== '');

        return new self(new Manager($uri, $options), $database);
    }

    public function collections(): array
    {
        $names = [];
        foreach ($this->manager->executeCommand($this->database, new Command(['listCollections' => 1, 'nameOnly' => true])) as $collection) {
            $name = (string) $collection->name;
            if (!str_starts_with($name, 'system.')) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The number of documents in a collection, exact. The `count` command without a query answers from
     * the collection's stored metadata, which can be wrong after an unclean shutdown of the server (or
     * on a sharded cluster with orphaned documents); the importer compares this number with what it
     * reads, and a wrong one would stop every run with a claim that the database is still being written
     * to. An aggregation with `$count` reads the documents themselves, so it counts the ones a read
     * returns. (It costs a scan of the collection, once per collection and run.)
     */
    public function count(string $collection): int
    {
        $cursor = $this->manager->executeCommand($this->database, new Command([
            'aggregate' => $collection,
            'pipeline' => [['$count' => 'n']],
            'cursor' => new \stdClass(),
        ]));
        foreach ($cursor as $result) {
            return (int) (string) ($result->n ?? 0);
        }

        return 0;
    }

    public function documents(string $collection): iterable
    {
        foreach ($this->pages($collection) as $raw) {
            $reason = null;
            $document = self::plain($raw->toPHP(self::TO_ARRAYS), $reason, false);
            yield [
                'id' => self::idText($raw->has('_id') ? $raw->get('_id') : null),
                'document' => $reason === null ? $document : null,
                'reason' => $reason,
                'json' => $raw->toCanonicalExtendedJSON(),
            ];
        }
    }

    /**
     * The `_id` of the configuration the MongoDB backend loaded: Idno\Data\Mongo::getAnyRecord('config')
     * was findOne([], ['sort' => ['created' => -1]]) on `config`, the same query as this one (no filter,
     * that sort, one document), so MongoDB itself chooses, ties included, as it chose for the site.
     */
    public function loadedConfigurationId(): ?string
    {
        $cursor = $this->manager->executeQuery($this->database . '.config', new Query([], ['sort' => ['created' => -1], 'limit' => 1]));
        $cursor->setTypeMap(['root' => 'bson']);
        foreach ($cursor as $raw) {
            if ($raw instanceof Document) {
                return self::idText($raw->has('_id') ? $raw->get('_id') : null);
            }
        }

        return null;
    }

    public function fileCount(): int
    {
        return $this->count('fs.files');
    }

    public function files(): iterable
    {
        foreach ($this->pages('fs.files') as $raw) {
            $objectId = $raw->has('_id') ? $raw->get('_id') : null;
            if (!$objectId instanceof ObjectId) {
                throw new \RuntimeException(self::problem($raw->toCanonicalExtendedJSON(), 'has an _id that is not an ObjectId, so the site could never serve it'));
            }
            $id = (string) $objectId;
            $data = $raw->toPHP(self::TO_ARRAYS);
            $length = $data['length'] ?? null;
            $length = $length instanceof Int64 ? (int) (string) $length : $length;
            if (!is_int($length) && !(is_float($length) && floor($length) === $length)) {
                throw new \RuntimeException(self::problem($id, 'has no whole-number length'));
            }
            $reason = null;
            $plain = self::plain($data, $reason, true);
            if ($reason !== null) {
                throw new \RuntimeException(self::problem($id, 'cannot be imported: ' . $reason . ' in its metadata'));
            }
            try {
                $metadata = MongoDocument::fileMetadata($plain);
            } catch (\UnexpectedValueException $e) {
                throw new \RuntimeException(self::problem($id, 'cannot be imported: ' . $e->getMessage() . ' in its metadata'), 0, $e);
            }
            $md5 = $data['md5'] ?? null;

            yield [
                'id' => $id,
                'length' => (int) $length,
                'md5' => is_string($md5) && $md5 !== '' ? strtolower($md5) : null,
                'metadata' => $metadata,
                'stream' => ChunkStream::open($this->chunks($objectId, $id)),
            ];
        }
    }

    /**
     * Every document of a collection, in `_id` order, a page at a time (see the class comment).
     *
     * @return \Generator<int, Document>
     */
    private function pages(string $collection): \Generator
    {
        $skip = 0;
        do {
            $page = $this->page($collection, self::NOT_OBJECT_IDS, $skip);
            yield from $page;
            $skip += count($page);
        } while (count($page) === $this->pageSize);

        $filter = ['_id' => ['$gte' => new ObjectId('000000000000000000000000')]];
        do {
            $page = $this->page($collection, $filter, 0);
            yield from $page;
            $last = end($page);
            if ($last instanceof Document) {
                $filter = ['_id' => ['$gt' => $last->get('_id')]];
            }
        } while (count($page) === $this->pageSize);
    }

    /**
     * One page: at most $pageSize documents, read whole, so no cursor stays open on the server.
     *
     * @param array<string, mixed> $filter
     *
     * @return list<Document>
     */
    private function page(string $collection, array $filter, int $skip): array
    {
        $options = ['sort' => ['_id' => 1], 'limit' => $this->pageSize] + ($skip > 0 ? ['skip' => $skip] : []);
        $cursor = $this->manager->executeQuery($this->database . '.' . $collection, new Query($filter, $options));
        $cursor->setTypeMap(['root' => 'bson']);
        $page = [];
        foreach ($cursor as $raw) {
            if (!$raw instanceof Document) {
                throw new \RuntimeException('The MongoDB driver did not return a BSON document.');
            }
            $page[] = $raw;
        }

        return $page;
    }

    /**
     * The chunks of a file, in order of `n`, one fetched at a time.
     *
     * @return \Generator<int, string>
     */
    private function chunks(ObjectId $objectId, string $id): \Generator
    {
        $cursor = $this->manager->executeQuery(
            $this->database . '.fs.chunks',
            new Query(['files_id' => $objectId], ['sort' => ['n' => 1], 'batchSize' => 1]),
        );
        $cursor->setTypeMap(self::TO_ARRAYS);
        $expected = 0;
        foreach ($cursor as $chunk) {
            $n = $chunk['n'] ?? null;
            $n = $n instanceof Int64 ? (int) (string) $n : $n;
            if ($n !== $expected) {
                throw new \RuntimeException(self::problem($id, sprintf(
                    'has the chunk %s where the chunk %d belongs (a chunk is missing or stored twice)',
                    is_int($n) ? (string) $n : get_debug_type($n),
                    $expected,
                )));
            }
            $data = $chunk['data'] ?? null;
            if (!$data instanceof Binary) {
                throw new \RuntimeException(self::problem($id, sprintf('has a chunk %d that holds no binary data', $expected)));
            }
            ++$expected;
            yield $data->getData();
        }
    }

    /**
     * A BSON value as a plain PHP value. $reason is set (and the rest still converted) when a value
     * has no plain form.
     */
    private static function plain(mixed $value, ?string &$reason, bool $dateAsString): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::plain($item, $reason, $dateAsString);
            }

            return $value;
        }
        if (!is_object($value)) {
            return $value;
        }
        if ($value instanceof ObjectId) {
            return (string) $value;
        }
        if ($value instanceof Int64) {
            return (int) (string) $value;
        }
        if ($value instanceof UTCDateTime) {
            $milliseconds = (int) (string) $value;

            return $dateAsString ? (string) $value : intdiv($milliseconds, 1000) - ($milliseconds % 1000 < 0 ? 1 : 0);
        }
        $reason ??= sprintf('a value of type %s cannot be imported', $value::class);

        return null;
    }

    /**
     * An `_id` as text: an ObjectId's hex digits, a string as it is, an integer's digits; '' for any other type.
     */
    private static function idText(mixed $id): string
    {
        return match (true) {
            $id instanceof ObjectId => (string) $id,
            is_string($id) => $id,
            is_int($id) => (string) $id,
            $id instanceof Int64 => (string) $id,
            default => '',
        };
    }
}
