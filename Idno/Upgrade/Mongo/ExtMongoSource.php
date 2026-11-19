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
 *  - A file's metadata is what Idno\Files\MongoDBFileSystem::findOne() put in `$file->metadata`
 *    (MongoDocument::fileMetadata()), a UTCDateTime as its string (epoch milliseconds) at any depth,
 *    ObjectIds and 64-bit integers converted as in documents. A file the site
 *    could never have served stops the import with a \RuntimeException naming it: an `_id` that is not
 *    an ObjectId, a length that is not a whole number, a value of another type in its metadata.
 *  - A file's stream yields the `data` of its `fs.chunks` in order of `n`, fetched one at a time
 *    (batches of one chunk, so one chunk is in memory); a chunk missing from the sequence, or one
 *    whose data is not binary, throws a \RuntimeException from the read.
 *
 * Built with new (or connect()) by the import command: not a container service.
 */
final class ExtMongoSource implements MongoSource
{
    private const TO_ARRAYS = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(
        private readonly Manager $manager,
        private readonly string $database,
    )
    {
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

    public function count(string $collection): int
    {
        $result = $this->manager->executeCommand($this->database, new Command(['count' => $collection]))->toArray()[0] ?? null;

        return (int) ($result->n ?? 0);
    }

    public function documents(string $collection): iterable
    {
        $cursor = $this->manager->executeQuery($this->database . '.' . $collection, new Query([], ['sort' => ['_id' => 1]]));
        $cursor->setTypeMap(['root' => 'bson']);
        foreach ($cursor as $raw) {
            if (!$raw instanceof Document) {
                throw new \RuntimeException('The MongoDB driver did not return a BSON document.');
            }
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

    public function fileCount(): int
    {
        return $this->count('fs.files');
    }

    public function files(): iterable
    {
        $cursor = $this->manager->executeQuery($this->database . '.fs.files', new Query([], ['sort' => ['_id' => 1]]));
        $cursor->setTypeMap(['root' => 'bson']);
        foreach ($cursor as $raw) {
            if (!$raw instanceof Document) {
                throw new \RuntimeException('The MongoDB driver did not return a BSON document.');
            }
            $objectId = $raw->has('_id') ? $raw->get('_id') : null;
            if (!$objectId instanceof ObjectId) {
                throw new \RuntimeException(sprintf('The GridFS file %s has an _id that is not an ObjectId, so the site could never serve it: remove it from fs.files (and its chunks) and import again.', $raw->toCanonicalExtendedJSON()));
            }
            $id = (string) $objectId;
            $data = $raw->toPHP(self::TO_ARRAYS);
            $length = $data['length'] ?? null;
            $length = $length instanceof Int64 ? (int) (string) $length : $length;
            if (!is_int($length) && !(is_float($length) && floor($length) === $length)) {
                throw new \RuntimeException(sprintf('The GridFS file %s has no whole-number length.', $id));
            }
            $reason = null;
            $plain = self::plain($data, $reason, true);
            if ($reason !== null) {
                throw new \RuntimeException(sprintf('The GridFS file %s cannot be imported: %s in its metadata.', $id, $reason));
            }
            try {
                $metadata = MongoDocument::fileMetadata($plain);
            } catch (\UnexpectedValueException $e) {
                throw new \RuntimeException(sprintf('The GridFS file %s cannot be imported: %s in its metadata.', $id, $e->getMessage()), 0, $e);
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
                throw new \RuntimeException(sprintf('The GridFS file %s is missing its chunk %d.', $id, $expected));
            }
            $data = $chunk['data'] ?? null;
            if (!$data instanceof Binary) {
                throw new \RuntimeException(sprintf('The chunk %d of the GridFS file %s holds no binary data.', $expected, $id));
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
