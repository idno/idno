<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\File;

use Idno\Http\Controller\File\ByteRange;
use PHPUnit\Framework\TestCase;

/**
 * Which bytes of a file a `Range` request header asks for (RFC 9110, section 14): a single range of bytes is read, a header that is not one is ignored, and
 * a range with no byte of the file in it is refused.
 */
final class ByteRangeTest extends TestCase
{
    /** The size of the file, in bytes */
    private const SIZE = 1000;

    /**
     * Ranges that can be satisfied: the header, then the first and last byte to expect.
     *
     * @return array<string, array{string, int, int}>
     */
    public static function satisfiableRanges(): array
    {
        $last = self::SIZE - 1;

        return [
            'the last byte, as first-last' => ["bytes=$last-$last", $last, $last],
            'the last byte, as first-' => ["bytes=$last-", $last, $last],
            'the first byte' => ['bytes=0-0', 0, 0],
            'a range in the middle' => ['bytes=400-599', 400, 599],
            'from a byte to the end' => ['bytes=250-', 250, $last],
            'the whole file' => ['bytes=0-', 0, $last],
            'the last 100 bytes' => ['bytes=-100', self::SIZE - 100, $last],
            'an end past the end of the file' => ['bytes=900-5000', 900, $last],
            'more last bytes than the file has' => ['bytes=-5000', 0, $last],
            'spaces around the parts' => [' bytes = 3 - 6 ', 3, 6],
            'the unit in capitals' => ['BYTES=0-9', 0, 9],
        ];
    }

    /**
     * Headers that are not a single range of bytes, and are ignored.
     *
     * @return array<string, array{string}>
     */
    public static function ignoredRanges(): array
    {
        return [
            'more than one range' => ['bytes=0-1,5-6'],
            'another unit' => ['items=0-5'],
            'no positions' => ['bytes=-'],
            'not a range' => ['bytes=abc'],
            'no unit' => ['0-5'],
            'nothing' => [''],
        ];
    }

    /**
     * Ranges that can't be satisfied.
     *
     * @return array<string, array{string}>
     */
    public static function unsatisfiableRanges(): array
    {
        return [
            'starting after the last byte' => ['bytes=' . self::SIZE . '-'],
            'starting far after the last byte' => ['bytes=5000-6000'],
            'the last zero bytes' => ['bytes=-0'],
            'ending before it starts' => ['bytes=600-400'],
        ];
    }

    /**
     * @dataProvider satisfiableRanges
     */
    public function testARangeIsReadAsItsFirstAndLastByte(string $range, int $first, int $last): void
    {
        $this->assertSame([$first, $last], ByteRange::parse($range, self::SIZE));
    }

    /**
     * @dataProvider ignoredRanges
     */
    public function testWhatIsNotOneRangeOfBytesIsIgnored(string $range): void
    {
        $this->assertNull(ByteRange::parse($range, self::SIZE));
    }

    /**
     * @dataProvider unsatisfiableRanges
     */
    public function testWhatCannotBeSatisfiedIsRefused(string $range): void
    {
        $this->assertFalse(ByteRange::parse($range, self::SIZE));
    }

    public function testNoRangeOfAnEmptyFileCanBeSatisfied(): void
    {
        $this->assertFalse(ByteRange::parse('bytes=0-', 0));
        $this->assertFalse(ByteRange::parse('bytes=-10', 0));
    }
}
