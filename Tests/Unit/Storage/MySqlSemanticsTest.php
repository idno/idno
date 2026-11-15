<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\MySqlSemantics;
use PHPUnit\Framework\TestCase;

/**
 * The shared rules directly. The stores that use them (InMemoryRecordStore, DbalRecordStore on
 * SQLite) are held to the storage contract, which covers them in depth.
 */
final class MySqlSemanticsTest extends TestCase
{
    public function testDatetimeLiterals(): void
    {
        $this->assertSame('2023-06-14 00:00:00', MySqlSemantics::datetime('2023-06-14'));
        $this->assertSame('2023-06-04 01:02:03', MySqlSemantics::datetime('2023-6-4T1:2:3'));
        $this->assertSame('2023-06-14 12:30:45.4', MySqlSemantics::datetime('2023-06-14 12:30:45.400000'));
        $this->assertSame('2023-06-14 00:00:00', MySqlSemantics::datetime('2023-06-14 00:00:00.000'));
        foreach (['', '1686700100', '2023-02-30', '2023-06-14 24:00:00', '2023-06-14 00:00:00.1234567', '2023-06-14 00:00'] as $rejected) {
            $this->assertNull(MySqlSemantics::datetime($rejected), $rejected);
        }
    }

    public function testTextIsComparedFoldedToUpperCase(): void
    {
        $this->assertSame(0, MySqlSemantics::compare('Red', 'rED'));
        $this->assertGreaterThan(0, MySqlSemantics::compare('a_', 'aZ'));
        $this->assertLessThan(0, MySqlSemantics::compare('1000', '999'));
        $this->assertSame('ÉTÉ', MySqlSemantics::fold('été'));
    }

    public function testSearch(): void
    {
        $text = 'The quick brown fox';

        $this->assertTrue(MySqlSemantics::searchMatches('k b', $text));
        $this->assertTrue(MySqlSemantics::searchMatches('T_E', $text));
        $this->assertFalse(MySqlSemantics::searchMatches('t\\_e', $text));
        $this->assertTrue(MySqlSemantics::searchMatches('zzzzz UICK', $text));
        $this->assertFalse(MySqlSemantics::searchMatches('quick_', $text), 'a long term\'s words have no wildcards');
        $this->assertFalse(MySqlSemantics::searchMatches('      ', $text));
        $this->assertSame(6, strlen('ééé'));
        $this->assertTrue(MySqlSemantics::searchMatches('ééé', 'xxÉÉÉÉxx'), 'six bytes: a word, matched as a substring');
    }
}
