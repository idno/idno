<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\RecordIndex;
use PHPUnit\Framework\TestCase;

/**
 * RecordIndex derives what the legacy backend writes next to a record. The expected values for
 * the three records at the top were READ FROM THE RIG'S DATABASE (the `entities`,
 * `entities_metadata` and `entities_search` rows Idno\Data\MySQL::saveRecord() wrote) and pasted
 * here; the document is the `contents` column. The rest pins single rules with small records.
 */
final class RecordIndexTest extends TestCase
{
    private string $timezone = '';

    protected function setUp(): void
    {
        // The created column is formatted in the PHP default timezone; the rig's was UTC.
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /**
     * The quote normaliser of the legacy application, Idno\Core\Language::uncurlQuotes(), copied
     * (it uses no application state): HTML entities decoded, then typographic quotes replaced.
     */
    private static function uncurl(string $string): string
    {
        $map = [
            "\xC2\x82" => "'", "\xC2\x84" => '"', "\xC2\x8B" => "'", "\xC2\x91" => "'", "\xC2\x92" => "'",
            "\xC2\x93" => '"', "\xC2\x94" => '"', "\xC2\x9B" => "'", "\xC2\xAB" => '"', "\xC2\xBB" => '"',
            "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'", "\xE2\x80\x9A" => "'", "\xE2\x80\x9B" => "'",
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"', "\xE2\x80\x9E" => '"', "\xE2\x80\x9F" => '"',
            "\xE2\x80\xB9" => "'", "\xE2\x80\xBA" => "'",
        ];

        return str_replace(array_keys($map), array_values($map), html_entity_decode($string, ENT_QUOTES, 'UTF-8'));
    }

    private static function identity(string $text): string
    {
        return $text;
    }

    /**
     * @param list<array{string, string}> $pairs
     *
     * @return list<array{name: string, value: string}>
     */
    private static function rows(array $pairs): array
    {
        return array_map(static fn (array $pair): array => ['name' => $pair[0], 'value' => $pair[1]], $pairs);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(string $json): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    // ------------------------------------------------- rows written by the legacy backend

    public function testAStatusEntityAsSavedByTheApplication(): void
    {
        $document = self::document('{"access":"PUBLIC","owner":"http:\/\/127.0.0.1:8089\/view\/8648c058-0f90-47d2-9c96-cafa0a3f91d9","body":"Making a test post via the API","tags":null,"publish_status":"published","slug":"making-a-test-post-via-the-api","created":1794698912,"updated":1794698912,"_id":"0c51fce2-a83e-43bc-a75f-a8e9d7b609fa","uuid":"http:\/\/127.0.0.1:8089\/view\/0c51fce2-a83e-43bc-a75f-a8e9d7b609fa","entity_subtype":"IdnoPlugins\\\\Status\\\\Status","siteid":null}');

        $this->assertSame(self::rows([
            ['access', 'PUBLIC'],
            ['owner', 'http://127.0.0.1:8089/view/8648c058-0f90-47d2-9c96-cafa0a3f91d9'],
            ['body', 'Making a test post via the API'],
            ['tags', '0'],
            ['publish_status', 'published'],
            ['slug', 'making-a-test-post-via-the-api'],
            ['created', '2026-11-14 23:28:32'],
            ['updated', '1794698912'],
            ['_id', '0c51fce2-a83e-43bc-a75f-a8e9d7b609fa'],
            ['uuid', 'http://127.0.0.1:8089/view/0c51fce2-a83e-43bc-a75f-a8e9d7b609fa'],
            ['entity_subtype', 'IdnoPlugins\\Status\\Status'],
            ['siteid', '0'],
        ]), RecordIndex::metadataRows($document));
        $this->assertSame([
            'uuid' => 'http://127.0.0.1:8089/view/0c51fce2-a83e-43bc-a75f-a8e9d7b609fa',
            '_id' => '0c51fce2-a83e-43bc-a75f-a8e9d7b609fa',
            'siteid' => null,
            'entity_subtype' => 'IdnoPlugins\\Status\\Status',
            'owner' => 'http://127.0.0.1:8089/view/8648c058-0f90-47d2-9c96-cafa0a3f91d9',
            'publish_status' => 'published',
            'created' => '2026-11-14 23:28:32',
        ], RecordIndex::columns($document));
        $this->assertSame('making a test post via the api', RecordIndex::searchText($document, self::identity(...)));
    }

    public function testARecordWithListsNestedValuesEmptyValuesAndLongValues(): void
    {
        $document = self::document(
            '{"_id":"rec-rich-000dc0","uuid":"http:\/\/rig.test\/view\/rich-25b95c","siteid":"site-1","owner":"http:\/\/rig.test\/profile\/ben","entity_subtype":"Tests\\\\Rich","publish_status":"draft","created":1686700000,'
            . '"title":"Café “quoted” it’s","tags":"#indieweb #php","description":"A <b>bold<\/b> claim",'
            . '"body":"<p>Line one<\/p>\r\n<p>Line two #hash<\/p>\n<a href=\"http:\/\/x.test\/\">link<\/a>","handle":"ben",'
            . '"profile":{"name":"Ben <i>W<\/i>","url":"http:\/\/rig.test\/","nested":["ignored"],"age":40},'
            . '"labels":["red","blue",""],"meta":[{"k":"v"},{"n":[1,2]}],"pair":{"a":"alpha","b":"beta"},'
            . '"count":3,"ratio":1.5,"yes":true,"no":false,"nothing":null,"zero":0,"zerostr":"0","emptystr":"","emptylist":[],'
            . '"long":"' . str_repeat('0123456789', 30) . '","wide":"' . str_repeat('é', 127) . 'abcd"}'
        );

        $this->assertSame(self::rows([
            ['_id', 'rec-rich-000dc0'],
            ['uuid', 'http://rig.test/view/rich-25b95c'],
            ['siteid', 'site-1'],
            ['owner', 'http://rig.test/profile/ben'],
            ['entity_subtype', 'Tests\\Rich'],
            ['publish_status', 'draft'],
            ['created', '2023-06-13 23:46:40'],
            ['title', 'Café “quoted” it’s'],
            ['tags', '#indieweb #php'],
            ['description', 'A <b>bold</b> claim'],
            ['body', "<p>Line one</p>\r\n<p>Line two #hash</p>\n<a href=\"http://x.test/\">link</a>"],
            ['handle', 'ben'],
            // The profile is an associative array: its values are the rows, the keys are not.
            ['profile', 'Ben <i>W</i>'],
            ['profile', 'http://rig.test/'],
            ['profile', '["ignored"]'],
            ['profile', '40'],
            // A list: one row per element, the empty one stored as 0.
            ['labels', 'red'],
            ['labels', 'blue'],
            ['labels', '0'],
            // Elements that are arrays are JSON.
            ['meta', '{"k":"v"}'],
            ['meta', '{"n":[1,2]}'],
            ['pair', 'alpha'],
            ['pair', 'beta'],
            ['count', '3'],
            ['ratio', '1.5'],
            ['yes', '1'],
            ['no', '0'],
            ['nothing', '0'],
            ['zero', '0'],
            ['zerostr', '0'],
            ['emptystr', '0'],
            // (`emptylist` has no row.) Values are cut to 255 bytes: 255 digits...
            ['long', str_repeat('0123456789', 25) . '01234'],
            // ...and 127 two-byte characters plus one byte.
            ['wide', str_repeat('é', 127) . 'a'],
        ]), RecordIndex::metadataRows($document));
        $this->assertSame([
            'uuid' => 'http://rig.test/view/rich-25b95c',
            '_id' => 'rec-rich-000dc0',
            'siteid' => 'site-1',
            'entity_subtype' => 'Tests\\Rich',
            'owner' => 'http://rig.test/profile/ben',
            'publish_status' => 'draft',
            'created' => '2023-06-13 23:46:40',
        ], RecordIndex::columns($document));
        // The title, the tags and the description each end in a space; the body (without its
        // markup, newlines padded, carriage returns dropped, '#' preceded by a space) does not,
        // so the handle follows it directly; the profile's strings follow, nested arrays do not.
        $this->assertSame(
            "café \"quoted\" it's  #indieweb  #php a <b>bold</b> claim line one \n line two  #hash \n linkben ben w http://rig.test/ 40 ",
            RecordIndex::searchText($document, self::uncurl(...))
        );
    }

    public function testARecordSavedWithOnlyWhatTheStoreFillsIn(): void
    {
        $document = self::document('{"title":"Bare record","slug":"bare","_id":"rec-minimal-a541a4","uuid":"http:\/\/rig.test\/view\/rec-minimal-a541a4","siteid":null,"owner":""}');
        $now = strtotime('2026-11-15 03:31:46 UTC');

        // The defaults for the subtype, the publish status and the creation time come last.
        $this->assertSame(self::rows([
            ['title', 'Bare record'],
            ['slug', 'bare'],
            ['_id', 'rec-minimal-a541a4'],
            ['uuid', 'http://rig.test/view/rec-minimal-a541a4'],
            ['siteid', '0'],
            ['owner', '0'],
            ['entity_subtype', 'Idno\\Common\\Entity'],
            ['publish_status', 'published'],
            ['created', '2026-11-15 03:31:46'],
        ]), RecordIndex::metadataRows($document, $now));
        $this->assertSame([
            'uuid' => 'http://rig.test/view/rec-minimal-a541a4',
            '_id' => 'rec-minimal-a541a4',
            'siteid' => null,
            'entity_subtype' => 'Idno\\Common\\Entity',
            'owner' => '',
            'publish_status' => 'published',
            'created' => '2026-11-15 03:31:46',
        ], RecordIndex::columns($document, $now));
        $this->assertSame('bare record ', RecordIndex::searchText($document, self::identity(...)));
    }

    // ------------------------------------------------------------------ single rules

    public function testTheRowForAGivenCreatedIsItsFormattedDatetimeAndIsReplacedInPlace(): void
    {
        $rows = RecordIndex::metadataRows(['created' => 1686700000, 'a' => 'b']);

        $this->assertSame(self::rows([['created', '2023-06-13 23:46:40'], ['a', 'b'], ['entity_subtype', 'Idno\\Common\\Entity'], ['publish_status', 'published']]), $rows);
    }

    public function testCreatedMayBeANumericStringOrAFloat(): void
    {
        $this->assertSame('2023-06-13 23:46:40', RecordIndex::columns(['created' => '1686700000'])['created']);
        $this->assertSame('2023-06-13 23:46:40', RecordIndex::columns(['created' => 1686700000.7])['created']);
    }

    public function testCreatedThatIsNotATimestampIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordIndex::columns(['created' => 'yesterday']);
    }

    public function testCreatedThatIsAFloatOutsideTheIntegerRangeIsRejected(): void
    {
        foreach ([1e20, -1e20, NAN, INF, '1e20'] as $created) {
            try {
                RecordIndex::columns(['created' => $created]);
                $this->fail('Expected an InvalidArgumentException for ' . json_encode($created));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('1970-01-01 00:16:40', RecordIndex::columns(['created' => '1e3'])['created']);
    }

    public function testWithoutCreatedTheGivenTimeIsUsed(): void
    {
        $this->assertSame('1970-01-01 00:01:40', RecordIndex::columns([], 100)['created']);
        $this->assertSame('1970-01-01 00:01:40', RecordIndex::columns(['created' => 0], 100)['created']);
        $this->assertSame('1970-01-01 00:01:40', RecordIndex::columns(['created' => ''], 100)['created']);
        $this->assertSame('1970-01-01 00:01:40', RecordIndex::columns(['created' => '0'], 100)['created']);
    }

    public function testEmptyValuesAreZeroAndAnEmptyListHasNoRow(): void
    {
        $rows = RecordIndex::metadataRows(['a' => '', 'b' => null, 'c' => false, 'd' => 0, 'e' => '0', 'f' => 0.0, 'g' => [], 'h' => [[]], 'i' => '0.0'], 1);

        $this->assertSame(self::rows([
            ['a', '0'], ['b', '0'], ['c', '0'], ['d', '0'], ['e', '0'], ['f', '0'],
            ['h', '[]'], // an empty array that is an element is not empty: it is the JSON '[]'
            ['i', '0.0'], // a non-empty string, even if it looks like zero
            ['entity_subtype', 'Idno\\Common\\Entity'], ['publish_status', 'published'], ['created', '1970-01-01 00:00:01'],
        ]), $rows);
    }

    public function testScalarsAreIndexedAsPhpStringsAndAnElementThatCannotBeEncodedIsAnEmptyList(): void
    {
        $rows = RecordIndex::metadataRows(['t' => true, 'i' => -3, 'f' => 0.1 + 0.2, 'bad' => [[NAN]], 'str' => "a\"b\\c"], 1);

        $this->assertSame(['1', '-3', '0.3', '[]', "a\"b\\c"], array_column(array_slice($rows, 0, 5), 'value'));
    }

    public function testIntegerKeysBecomeNames(): void
    {
        $this->assertSame(['0', '1'], array_column(array_slice(RecordIndex::metadataRows(['x', 'y'], 1), 0, 2), 'name'));
    }

    public function testAValueIsCutAt255BytesOnACharacterBoundary(): void
    {
        $at = static fn (string $value): string => RecordIndex::metadataRows(['v' => $value], 1)[0]['value'];

        $this->assertSame(str_repeat('a', 255), $at(str_repeat('a', 300)));
        $this->assertSame(str_repeat('a', 255), $at(str_repeat('a', 255)));
        // 127 two-byte characters and one byte are exactly 255 bytes.
        $this->assertSame(str_repeat('é', 127) . 'a', $at(str_repeat('é', 127) . 'abcd'));
        // A cut that would fall inside the 128th character stops before it: 254 bytes (P7).
        $this->assertSame(str_repeat('é', 127), $at(str_repeat('é', 200)));
        $this->assertSame(254, strlen($at(str_repeat('é', 200))));
        // Four-byte characters: 63 of them are 252 bytes, the 64th would end at 256.
        $this->assertSame(str_repeat("\u{1F600}", 63), $at(str_repeat("\u{1F600}", 100)));
    }

    public function testSearchTextCoversTheIndexedFieldsOnly(): void
    {
        $text = RecordIndex::searchText(['summary' => 'S', 'content' => 'C', 'slug' => 'L', 'title' => 'T', 'tags' => 'G', 'description' => 'D', 'body' => 'B', 'handle' => 'H', 'profile' => ['p' => 'P', 'q' => ['Q']]], self::identity(...));

        $this->assertSame('t g d bh p ', $text);
    }

    public function testSearchTextOfEmptyFieldsIsLeftOut(): void
    {
        $this->assertSame('', RecordIndex::searchText(['title' => '', 'tags' => '0', 'description' => null, 'body' => 0, 'handle' => false, 'profile' => []], self::identity(...)));
        $this->assertSame('', RecordIndex::searchText([], self::identity(...)));
    }

    public function testAnArrayInATextFieldIsTheWordArrayAndAnArrayBodyIsNothing(): void
    {
        foreach (['title', 'tags', 'description', 'handle'] as $field) {
            $this->assertSame('array ', RecordIndex::searchText([$field => ['x', 'y']], self::identity(...)), $field);
        }
        $this->assertSame('', RecordIndex::searchText(['body' => ['x', 'y']], self::identity(...)));
        $this->assertSame('t ', RecordIndex::searchText(['title' => 't', 'body' => ['x']], self::identity(...)));
    }

    public function testNumbersAreIndexedInTheSearchText(): void
    {
        $this->assertSame('42 1.5 1 ', RecordIndex::searchText(['title' => 42, 'tags' => 1.5, 'description' => true], self::identity(...)));
        $this->assertSame('1 2  ', RecordIndex::searchText(['profile' => [1, 2, null]], self::identity(...)));
    }

    public function testSearchTextLowercasesAsciiOnlyAndTouchesTheWhitespaceAndHashes(): void
    {
        $text = RecordIndex::searchText(['body' => "Hello\r\nWORLD #Tag\nÉcole"], self::identity(...));

        // strtolower() leaves É alone (PHP 8 is locale-insensitive, ASCII only).
        $this->assertSame("hello \n world  #tag \n École", $text);
    }

    public function testTheBodyHasItsMarkupStrippedAndTheOtherFieldsDoNot(): void
    {
        $text = RecordIndex::searchText(['title' => '<b>t</b>', 'body' => '<p class="x">b</p>', 'profile' => ['n' => '<i>p</i>']], self::identity(...));

        $this->assertSame('<b>t</b> bp ', $text);
    }

    public function testTheQuoteNormaliserIsAppliedToTheWholeLoweredText(): void
    {
        $seen = [];
        $text = RecordIndex::searchText(['title' => 'AbC'], static function (string $text) use (&$seen): string {
            $seen[] = $text;

            return strtoupper($text);
        });

        $this->assertSame(['abc '], $seen);
        $this->assertSame('ABC ', $text);
    }

    public function testTheLegacyQuoteNormaliserDecodesEntitiesAndStraightensQuotes(): void
    {
        $this->assertSame("it's \"fine\" & \"x\" ", RecordIndex::searchText(['title' => "It\u{2019}s \u{201C}fine\u{201D} &amp; &quot;x&quot;"], self::uncurl(...)));
        // The '#' of a numeric entity is padded with a space before the entities are decoded, so
        // they are not (an oddity of the legacy order of operations, reproduced).
        $this->assertSame('it& #039;s ', RecordIndex::searchText(['title' => 'it&#039;s'], self::uncurl(...)));
    }

    public function testColumnsStringifyWhatIsGivenAndDefaultWhatIsMissing(): void
    {
        $columns = RecordIndex::columns(['_id' => 12, 'uuid' => 'u', 'siteid' => 7, 'owner' => true, 'entity_subtype' => 'S', 'publish_status' => 'draft'], 5);

        $this->assertSame(['uuid' => 'u', '_id' => '12', 'siteid' => '7', 'entity_subtype' => 'S', 'owner' => '1', 'publish_status' => 'draft', 'created' => '1970-01-01 00:00:05'], $columns);
        $this->assertSame('', RecordIndex::columns([], 5)['owner']);
        $this->assertSame('', RecordIndex::columns([], 5)['uuid']);
    }
}
