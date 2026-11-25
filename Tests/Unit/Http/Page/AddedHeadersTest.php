<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\AddedHeaders;
use PHPUnit\Framework\TestCase;

final class AddedHeadersTest extends TestCase
{
    public function testNothingAddedIsNothing(): void
    {
        $same = ['X-Powered-By: https://idno.co', 'Expires: Thu, 19 Nov 1981 08:52:00 GMT'];

        $this->assertSame(['added' => [], 'remove' => [], 'keep' => []], AddedHeaders::between($same, $same));
        $this->assertSame(['added' => [], 'remove' => [], 'keep' => []], AddedHeaders::between([], []));
    }

    public function testANewHeaderIsAddedAndRemoved(): void
    {
        $result = AddedHeaders::between(['Expires: x'], ['Expires: x', 'X-Fixture: one']);

        $this->assertSame([['X-Fixture', 'one', true]], $result['added']);
        $this->assertSame(['X-Fixture'], $result['remove']);
        $this->assertSame([], $result['keep']);
    }

    public function testAHeaderThatReplacedOneIsAReplacingOne(): void
    {
        $result = AddedHeaders::between(['Content-Type: text/html', 'Expires: x'], ['Content-Type: application/x-fixture', 'Expires: x']);

        $this->assertSame([['Content-Type', 'application/x-fixture', true]], $result['added']);
        $this->assertSame(['Content-Type'], $result['remove']);
        $this->assertSame([], $result['keep'], 'what it replaced is gone');
    }

    public function testAHeaderAddedBesideOneIsAnAppendedOneAndTheOldOneIsKept(): void
    {
        $result = AddedHeaders::between(['Vary: Accept'], ['Vary: Accept', 'Vary: Cookie']);

        $this->assertSame([['Vary', 'Cookie', false]], $result['added']);
        $this->assertSame(['Vary'], $result['remove']);
        $this->assertSame(['Vary: Accept'], $result['keep']);
    }

    public function testSeveralNewLinesOfOneNameAreTheFirstOneReplacingAndTheRestAppended(): void
    {
        $result = AddedHeaders::between([], ['X-Fixture: a', 'X-Fixture: b']);

        $this->assertSame([['X-Fixture', 'a', true], ['X-Fixture', 'b', false]], $result['added']);
    }

    public function testLinkIsAddedNotReplacingWhenNothingWasThere(): void
    {
        $result = AddedHeaders::between([], ['Link: <https://example.com/>; rel="x"']);

        $this->assertSame([['Link', '<https://example.com/>; rel="x"', false]], $result['added']);
    }

    public function testNamesAreComparedWithoutRegardToCase(): void
    {
        $result = AddedHeaders::between(['content-type: text/html'], ['Content-Type: application/json']);

        $this->assertSame([['Content-Type', 'application/json', true]], $result['added']);
        $this->assertSame(['Content-Type'], $result['remove']);
    }

    public function testACookieIsNeverTaken(): void
    {
        $result = AddedHeaders::between(['Expires: x'], ['Expires: x', 'Set-Cookie: sid=abc; path=/', 'set-cookie: other=1', 'X-Fixture: one']);

        $this->assertSame([['X-Fixture', 'one', true]], $result['added']);
        $this->assertSame(['X-Fixture'], $result['remove']);
    }

    public function testALineWithNoColonIsIgnored(): void
    {
        $this->assertSame(['added' => [], 'remove' => [], 'keep' => []], AddedHeaders::between([], ['no colon here']));
    }

    public function testAHeaderReplacedByItselfIsNothingNew(): void
    {
        $same = ['X-Fixture: one', 'Expires: x'];

        $this->assertSame(['added' => [], 'remove' => [], 'keep' => []], AddedHeaders::between($same, $same));
    }

    public function testAHeaderTheDrawRemovedIsNotAddedAndPhpsListIsLeftAsItIs(): void
    {
        // PHP's own list already lost it: nothing to add, nothing to remove (the type's own headers are sent into PHP's list before the draw, so
        // a removal of one of them is a removal from the list)
        $this->assertSame(['added' => [], 'remove' => [], 'keep' => []], AddedHeaders::between(['Access-Control-Allow-Origin: *', 'Expires: x'], ['Expires: x']));
    }

    public function testTheTypesOwnHeadersAreTheNewLinesThePluginsAppendedToAreInOrder(): void
    {
        $result = AddedHeaders::between(['Expires: x'], ['Expires: x', 'Content-type: text/html', 'Access-Control-Allow-Origin: *', 'Access-Control-Allow-Origin: https://fixture.example']);

        $this->assertSame([['Content-type', 'text/html', true], ['Access-Control-Allow-Origin', '*', true], ['Access-Control-Allow-Origin', 'https://fixture.example', false]], $result['added']);
    }
}
