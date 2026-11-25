<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\HttpDate;
use PHPUnit\Framework\TestCase;

final class HttpDateTest extends TestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function timestamps(): array
    {
        return ['the epoch' => [0], 'a date in 2023' => [1700000000], 'a date far ahead' => [PHP_INT_MAX >> 33]];
    }

    /**
     * @dataProvider timestamps
     */
    public function testFormatIsWhatTimeGave(int $timestamp): void
    {
        $this->assertSame(gmdate('D, d M Y H:i:s T', $timestamp), HttpDate::format($timestamp));
    }

    public function testTheThreeFormsParse(): void
    {
        // the three forms of RFC 9110 section 5.6.7, of one instant
        $this->assertSame(784111777, HttpDate::parse('Sun, 06 Nov 1994 08:49:37 GMT'));
        $this->assertSame(784111777, HttpDate::parse('Sunday, 06-Nov-94 08:49:37 GMT'));
        $this->assertSame(784111777, HttpDate::parse('Sun Nov  6 08:49:37 1994'));
        // and a two-digit day in the third form
        $this->assertSame(1700000000, HttpDate::parse('Tue Nov 14 22:13:20 2023'));
    }

    public function testWhatFormatMakesParses(): void
    {
        foreach ([0, 1, 784111777, 1700000000, 4102444800] as $timestamp) {
            $this->assertSame($timestamp, HttpDate::parse(HttpDate::format($timestamp)));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notDates(): array
    {
        return [
            'a word strtotime() reads' => ['tomorrow'],
            'a timestamp' => ['1700000000'],
            'nothing' => [''],
            'trailing text' => ['Sun, 06 Nov 1994 08:49:37 GMT and more'],
            'a day that is not' => ['Sun, 31 Nov 1994 08:49:37 GMT'],
            'a wrong day name' => ['Mon, 06 Nov 1994 08:49:37 GMT'],
            'another zone' => ['Sun, 06 Nov 1994 08:49:37 +0100'],
            'an ISO date' => ['1994-11-06T08:49:37Z'],
            'no zone' => ['Sun, 06 Nov 1994 08:49:37'],
            'lead white space' => [' Sun, 06 Nov 1994 08:49:37 GMT'],
        ];
    }

    /**
     * @dataProvider notDates
     */
    public function testAnythingElseIsNull(string $value): void
    {
        $this->assertNull(HttpDate::parse($value));
    }
}
