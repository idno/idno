<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Replaces curly and angle quotation marks (and the Windows-1252 ones, as C1 controls) with plain ones,
 * after decoding HTML entities. The stores pass every record's search text through it, so what it returns
 * for an input is stored data: do not change a mapping.
 */
final class QuoteNormaliser
{
    private const MAP = [
        // Windows codepage 1252
        "\xC2\x82" => "'", // U+0082 => U+201A single low-9 quotation mark
        "\xC2\x84" => '"', // U+0084 => U+201E double low-9 quotation mark
        "\xC2\x8B" => "'", // U+008B => U+2039 single left-pointing angle quotation mark
        "\xC2\x91" => "'", // U+0091 => U+2018 left single quotation mark
        "\xC2\x92" => "'", // U+0092 => U+2019 right single quotation mark
        "\xC2\x93" => '"', // U+0093 => U+201C left double quotation mark
        "\xC2\x94" => '"', // U+0094 => U+201D right double quotation mark
        "\xC2\x9B" => "'", // U+009B => U+203A single right-pointing angle quotation mark
        // Regular Unicode
        "\xC2\xAB" => '"', // U+00AB left-pointing double angle quotation mark
        "\xC2\xBB" => '"', // U+00BB right-pointing double angle quotation mark
        "\xE2\x80\x98" => "'", // U+2018 left single quotation mark
        "\xE2\x80\x99" => "'", // U+2019 right single quotation mark
        "\xE2\x80\x9A" => "'", // U+201A single low-9 quotation mark
        "\xE2\x80\x9B" => "'", // U+201B single high-reversed-9 quotation mark
        "\xE2\x80\x9C" => '"', // U+201C left double quotation mark
        "\xE2\x80\x9D" => '"', // U+201D right double quotation mark
        "\xE2\x80\x9E" => '"', // U+201E double low-9 quotation mark
        "\xE2\x80\x9F" => '"', // U+201F double high-reversed-9 quotation mark
        "\xE2\x80\xB9" => "'", // U+2039 single left-pointing angle quotation mark
        "\xE2\x80\xBA" => "'", // U+203A single right-pointing angle quotation mark
    ];

    public static function normalise(string $text): string
    {
        return str_replace(array_keys(self::MAP), array_values(self::MAP), html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }
}
