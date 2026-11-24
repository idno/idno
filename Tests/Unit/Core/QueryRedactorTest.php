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
            'the case as sent' => ['Access_Token=abc', 'Access_Token=[redacted]'],
            'no parameter' => ['a=1&b=2&notaccess_token=3&access_tokens=4', 'a=1&b=2&notaccess_token=3&access_tokens=4'],
            'an empty value' => ['access_token=', 'access_token=[redacted]'],
            'a flag with no value' => ['access_token&a=1', 'access_token&a=1'],
            'empty' => ['', ''],
        ];
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
