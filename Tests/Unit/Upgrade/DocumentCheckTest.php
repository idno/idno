<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Storage\RecordIndex;
use Idno\Upgrade\DocumentCheck;
use PHPUnit\Framework\TestCase;

/**
 * The checks the copy of a 1.x database makes on a row's contents, and the `created` it reads from
 * the top level of a document without decoding the whole of it: always what decoding the whole
 * document and applying RecordIndex::created() gives.
 */
final class DocumentCheckTest extends TestCase
{
    public function testTheProblemsOfContents(): void
    {
        self::assertNull(DocumentCheck::problem('{"a":1}'));
        self::assertNull(DocumentCheck::problem(" \n\t[1,2]"));
        self::assertNull(DocumentCheck::problem('{}'));
        self::assertSame('contents are not valid UTF-8', DocumentCheck::problem("\xff\xfe"));
        self::assertSame('contents are not valid JSON', DocumentCheck::problem('not json'));
        self::assertSame('contents are not valid JSON', DocumentCheck::problem(''));
        self::assertSame('contents are not valid JSON', DocumentCheck::problem('{"a":"\ud800"}'));
        self::assertSame('contents are not a JSON object or array', DocumentCheck::problem('"a string"'));
        self::assertSame('contents are not a JSON object or array', DocumentCheck::problem(' 12'));
        self::assertSame('contents are not a JSON object or array', DocumentCheck::problem('null'));
        self::assertSame('contents are nested deeper than 512 levels', DocumentCheck::problem(str_repeat('[', 513) . str_repeat(']', 513)));
        self::assertNull(DocumentCheck::problem(str_repeat('[', 500) . str_repeat(']', 500)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'an integer' => ['{"title":"x","created":1700000000}'];
        yield 'missing' => ['{"title":"x"}'];
        yield 'a list' => ['[{"created":5}]'];
        yield 'empty object' => ['{}'];
        yield 'nested created only' => ['{"a":{"created":5},"b":[{"created":6}],"c":"\"created\":7"}'];
        yield 'nested and top level' => ['{"a":{"created":5,"x":[1,{"y":"}"}]},"created":1600000000,"z":"]"}'];
        yield 'duplicate keys: the last' => ['{"created":1,"created":1700000001}'];
        yield 'escaped key' => ['{"created":1700000002}'];
        yield 'a key with escapes before it' => ['{"t\"x\\\\":"a\\\\","created":1700000003}'];
        yield 'numeric string' => ['{"created":"1600000000.5"}'];
        yield 'float' => ['{"created":1600000000.9}'];
        yield 'exponent' => ['{"created":1.6e9}'];
        yield 'too large' => ['{"created":1e400}'];
        yield 'beyond 64 bits' => ['{"created":123456789012345678901234567890}'];
        yield 'a word' => ['{"created":"yesterday"}'];
        yield 'zero' => ['{"created":0}'];
        yield 'empty string' => ['{"created":""}'];
        yield 'null' => ['{"created":null}'];
        yield 'true' => ['{"created":true}'];
        yield 'false' => ['{"created":false}'];
        yield 'an object' => ['{"created":{"t":1}}'];
        yield 'an array' => ['{"created":[1700000000]}'];
        yield 'negative' => ['{"created":-86400}'];
        yield 'whitespace everywhere' => [" {\n \"a\" : [ 1 , 2 ] ,\r\n \"created\" :\t1700000004 \n} "];
        yield 'unicode around' => ['{"t":"😀 ß {[","created":1700000005,"u":"é"}'];
    }

    /**
     * @dataProvider documents
     */
    public function testCreatedIsWhatDecodingTheWholeDocumentGives(string $json): void
    {
        self::assertNull(DocumentCheck::problem($json), 'a valid document');
        $document = json_decode($json, true);
        try {
            $expected = RecordIndex::created($document, 42);
        } catch (\InvalidArgumentException) {
            $expected = 42;
        }

        self::assertSame($expected, DocumentCheck::created($json, 42));
    }
}
