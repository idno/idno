<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Mongo;

use Idno\Upgrade\Mongo\MongoDocument;
use Idno\Upgrade\Mongo\MongoSource;

/**
 * A MongoSource held in arrays, for the importer's tests. Documents are given as they come out of
 * MongoDB (keys escaped, values plain); each is listed in `_id` order, with its JSON as the
 * Extended JSON. A file is given as its fs.files document (plain values) and its bytes, or a closure
 * that opens its stream; its metadata is what the site's GridFS filesystem made of it
 * (MongoDocument::fileMetadata()). With $failBeforeLastFile, files() throws instead of giving the last
 * file (a source failing at the very end).
 */
final class ArrayMongoSource implements MongoSource
{
    /** @var array<string, list<array{id: string, document: ?array, reason: ?string, json: string}>> */
    private array $collections = [];

    /** @var list<array{file: array, bytes: string|\Closure}> */
    private array $files = [];

    /** @var array<string, int> counts that differ from the documents (a source written to meanwhile) */
    private array $counts = [];

    public function __construct(private readonly bool $failBeforeLastFile = false)
    {
    }

    /**
     * @param array<array-key, mixed> $document
     */
    public function add(string $collection, array $document): self
    {
        $this->collections[$collection][] = [
            'id' => (string) ($document['_id'] ?? ''),
            'document' => $document,
            'reason' => null,
            'json' => (string) json_encode($document),
        ];

        return $this;
    }

    /**
     * A document whose values have no plain form (as ExtMongoSource gives it).
     */
    public function addUnreadable(string $collection, string $id, string $reason, string $json): self
    {
        $this->collections[$collection][] = ['id' => $id, 'document' => null, 'reason' => $reason, 'json' => $json];

        return $this;
    }

    public function addCollection(string $collection): self
    {
        $this->collections[$collection] ??= [];

        return $this;
    }

    /**
     * @param array<array-key, mixed> $file  the fs.files document (`_id`, `length`, `md5`, `filename`, `metadata`, ...)
     * @param string|\Closure         $bytes the bytes, or a closure returning a stream of them
     */
    public function addFile(array $file, string|\Closure $bytes): self
    {
        $this->files[] = ['file' => $file, 'bytes' => $bytes];

        return $this;
    }

    public function claimCount(string $collection, int $count): self
    {
        $this->counts[$collection] = $count;

        return $this;
    }

    public function collections(): array
    {
        $names = array_keys($this->collections);
        if ($this->files !== []) {
            $names[] = 'fs.files';
            $names[] = 'fs.chunks';
        }
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names, SORT_STRING);

        return $names;
    }

    public function count(string $collection): int
    {
        return $this->counts[$collection] ?? count($this->collections[$collection] ?? []);
    }

    public function documents(string $collection): iterable
    {
        $documents = $this->collections[$collection] ?? [];
        usort($documents, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        yield from $documents;
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function files(): iterable
    {
        $files = $this->files;
        usort($files, static fn (array $a, array $b): int => strcmp((string) $a['file']['_id'], (string) $b['file']['_id']));
        foreach ($files as $index => $entry) {
            if ($this->failBeforeLastFile && $index === count($files) - 1) {
                throw new \RuntimeException('The source failed before its last file.');
            }
            $file = $entry['file'];
            if ($entry['bytes'] instanceof \Closure) {
                $stream = ($entry['bytes'])();
            } else {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $entry['bytes']);
                rewind($stream);
            }
            $md5 = $file['md5'] ?? null;

            yield [
                'id' => (string) $file['_id'],
                'length' => (int) $file['length'],
                'md5' => is_string($md5) ? $md5 : null,
                'metadata' => MongoDocument::fileMetadata($file),
                'stream' => $stream,
            ];
        }
    }
}
