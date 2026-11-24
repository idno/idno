<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Idno\Core\QueryRedactor;
use PHPUnit\Framework\TestCase;

final class QueryRedactorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function queries(): array
    {
        return [
            'the only parameter' => ['access_token=abc', 'access_token=[redacted]'],
            'among others' => ['a=1&access_token=abc&b=2', 'a=1&access_token=[redacted]&b=2'],
            'repeated' => ['access_token=abc&x=1&access_token=def', 'access_token=[redacted]&x=1&access_token=[redacted]'],
            'a URL-encoded name' => ['access%5Ftoken=abc&a=1', 'access%5Ftoken=[redacted]&a=1'],
            'the array form' => ['access_token[]=abc&access_token%5B0%5D=def', 'access_token[]=[redacted]&access_token%5B0%5D=[redacted]'],
            'a dot in the name' => ['access.token=abc&a=1', 'access.token=[redacted]&a=1'],
            'a plus in the name' => ['access+token=abc', 'access+token=[redacted]'],
            'an encoded space in the name' => ['access%20token=abc', 'access%20token=[redacted]'],
            'an encoded dot in the name' => ['access%2Etoken=abc', 'access%2Etoken=[redacted]'],
            'leading spaces in the name' => ['%20%20access_token=abc&+access_token=def', '%20%20access_token=[redacted]&+access_token=[redacted]'],
            'a dot and the array form' => ['access.token[]=abc', 'access.token[]=[redacted]'],
            'a malformed encoding' => ['access%zz=abc&%=1&=2', 'access%zz=abc&%=1&=2'],
            'the case as sent' => ['Access_Token=abc', 'Access_Token=[redacted]'],
            'no parameter' => ['a=1&b=2&notaccess_token=3&access_tokens=4', 'a=1&b=2&notaccess_token=3&access_tokens=4'],
            'an empty value' => ['access_token=', 'access_token=[redacted]'],
            'a flag with no value' => ['access_token&a=1', 'access_token&a=1'],
            'empty' => ['', ''],
            'an opening bracket and a name after it' => ['access[token=abc', 'access[token=[redacted]'],
            'a NUL byte ends the name' => ['access_token%00x=abc', 'access_token%00x=[redacted]'],
            'an unclosed bracket makes another name' => ['access_token[=abc', 'access_token[=abc'],
            'brackets and more name' => ['access[]token=abc', 'access[]token=abc'],
            'an index and more name' => ['access_token[a]x=abc', 'access_token[a]x=[redacted]'],
        ];
    }

    /**
     * What PHP itself reads as the `access_token` parameter decides, never this class's own idea of it: every
     * query in the table above is run through parse_str(), and the value is redacted exactly when PHP found a
     * parameter named access_token (in any letter case) in it.
     *
     * @dataProvider queries
     */
    public function testARedactionAgreesWithWhatPhpReadsAsAccessToken(string $query, string $expected): void
    {
        $this->addToAssertionCount(1);
        $redacted = explode('&', QueryRedactor::query($query));
        foreach (explode('&', $query) as $i => $pair) {
            if (!str_contains($pair, '=')) {
                continue; // no value to leak
            }
            parse_str($pair, $parsed);
            $phpReadsIt = false;
            foreach (array_keys($parsed) as $name) {
                $phpReadsIt = $phpReadsIt || strtolower((string) $name) === 'access_token';
            }
            $this->assertSame($phpReadsIt, str_ends_with($redacted[$i], '=' . QueryRedactor::REPLACEMENT), $pair);
        }
    }

    /**
     * @dataProvider queries
     */
    public function testAQueryStringIsRedacted(string $query, string $expected): void
    {
        $this->assertSame($expected, QueryRedactor::query($query));
    }

    public function testAnAddressIsRedactedInItsQueryOnly(): void
    {
        $this->assertSame('/a/access_token=x?b=1&access_token=[redacted]#access_token=y', QueryRedactor::uri('/a/access_token=x?b=1&access_token=abc#access_token=y'));
        $this->assertSame('/path/no-query', QueryRedactor::uri('/path/no-query'));
        $this->assertSame('/p?a=1', QueryRedactor::uri('/p?a=1'));
    }
}
