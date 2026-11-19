<?php

declare(strict_types=1);

namespace Idno\Upgrade\Mongo;

/**
 * A MongoDB database of an Idno site, as the import reads it (ExtMongoSource on the mongodb
 * extension; tests use an array-backed one).
 *
 *  - documents(): every document of a collection, in `_id` order. `id` is the `_id` as text (an
 *    ObjectId's 24 hex digits); `document` the document as a plain PHP array (an ObjectId as its hex
 *    digits, a date as epoch seconds, a 64-bit integer as an int), its keys still escaped as the
 *    MongoDB backend stored them (see MongoDocument), or null when a value has no such form, with the
 *    `reason`; `json` the document's canonical Extended JSON, kept as a reject's contents.
 *  - files(): every GridFS file (`fs.files`), in `_id` order: its id, length, md5 (null when GridFS
 *    stored none), the metadata the site's MongoDBFileSystem gave it, and a stream of its bytes (the
 *    chunks in order of `n`, one in memory at a time; the caller closes it).
 *
 * Every method throws on a failure of the source (a \RuntimeException or the driver's exception): a
 * failure is never an empty result.
 */
interface MongoSource
{
    /**
     * The names of the database's collections (not its `system.` ones).
     *
     * @return list<string>
     */
    public function collections(): array;

    /**
     * The number of documents in the collection (0 for one that is not there).
     */
    public function count(string $collection): int;

    /**
     * @return iterable<array{id: string, document: ?array, reason: ?string, json: string}> ordered by _id
     */
    public function documents(string $collection): iterable;

    /**
     * The number of GridFS files (`fs.files` documents).
     */
    public function fileCount(): int;

    /**
     * @return iterable<array{id: string, length: int, md5: ?string, metadata: array, stream: resource}> ordered by _id
     */
    public function files(): iterable;
}
