<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Mongo;

use Idno\Upgrade\Mongo\MongoDocument;
use PHPUnit\Framework\TestCase;

/**
 * The field names the MongoDB backend escaped, restored; and a GridFS file's metadata as the site's
 * GridFS filesystem made it.
 */
final class MongoDocumentTest extends TestCase
{
    public function testKeysAreUnescapedAtEveryDepth(): void
    {
        $this->assertSame(
            [
                'a.b' => 1,
                'c$d' => ['list' => [['j.k' => 'x']], 'e.f' => ['g$h.i' => true]],
                'per%cent' => null,
            ],
            MongoDocument::unescapeKeys([
                'a%2Eb' => 1,
                'c%24d' => ['e%2Ef' => ['g%24h%2Ei' => true], 'list' => [['j%252Ek' => 'x']]],
                'per%25cent' => null,
            ]),
        );
    }

    public function testAKeyThatChangesMovesAfterTheOthersAsTheBackendLeftIt(): void
    {
        $this->assertSame(
            ['first' => 1, 'last' => 3, 'nested' => ['b' => 2, 'a.b' => 1], 'odd.name' => 2],
            MongoDocument::unescapeKeys(['first' => 1, 'odd%2Ename' => 2, 'last' => 3, 'nested' => ['a%2Eb' => 1, 'b' => 2]]),
        );
    }

    /**
     * Idno\Data\Mongo::unsanitizeFields() replaced `%25`, then `%24`, then `%2E`, one after the other:
     * a `%25` followed by `2E` or `24` reads as a dot or a dollar sign, as the site has always read it.
     */
    public function testReplacementsAreSequentialAsTheBackendMadeThem(): void
    {
        $this->assertSame(['a.b' => 'x'], MongoDocument::unescapeKeys(['a%252Eb' => 'x']));
        $this->assertSame(['$' => 'y'], MongoDocument::unescapeKeys(['%2524' => 'y']));
        // An ordinary round trip: the backend's escaping of `a.b$c%d` (`%` first) read back.
        $this->assertSame(['a.b$c%d' => 'z'], MongoDocument::unescapeKeys(['a%2Eb%24c%25d' => 'z']));
    }

    public function testValuesAreNotTouched(): void
    {
        $document = ['body' => 'a%2Eb and %24 and %25', 'list' => ['%2E', '%24'], 'n' => 3, 'f' => 1.5, 'no' => false];

        $this->assertSame($document, MongoDocument::unescapeKeys($document));
    }

    public function testTwoKeysThatBecomeOneAreRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MongoDocument::unescapeKeys(['nested' => ['a%2Eb' => 1, 'a.b' => 2]]);
    }

    public function testAFilesMetadataIsWhatTheGridFsFilesystemMadeOfIt(): void
    {
        $this->assertSame(
            ['filename' => 'pixel.png', 'mime_type' => 'image/png', 'odd.key' => 1, 'chunkSize' => 261120, 'length' => 98, 'uploadDate' => '1700000000123'],
            MongoDocument::fileMetadata([
                '_id' => '6aff2dc4a96cd4f90409f749',
                'chunkSize' => 261120,
                'filename' => '/tmp/uploads/pixel.png',
                'length' => 98,
                'uploadDate' => '1700000000123',
                'metadata' => ['_id' => 'ignored', 'filename' => 'pixel.png', 'mime_type' => 'image/png', 'odd%2Ekey' => 1],
            ]),
        );
        // The old driver's shape: no metadata sub-document.
        $this->assertSame(
            ['filename' => 'old.txt', 'mime_type' => 'text/plain', 'length' => 3],
            MongoDocument::fileMetadata(['_id' => 'x', 'filename' => 'a/b/old.txt', 'mime_type' => 'text/plain', 'length' => 3]),
        );
    }
}
