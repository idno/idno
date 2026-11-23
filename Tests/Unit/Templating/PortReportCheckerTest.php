<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;
use Tests\Templating\PortReportChecker;

/**
 * The three assertions of the equivalence test, on reports written by hand: each can fail.
 */
final class PortReportCheckerTest extends TestCase
{
    private const FILE = 'templates/default/x.html.twig';

    public function testAComparisonThatMatchesPasses(): void
    {
        $result = PortReportChecker::check([$this->same('a')], [], [self::FILE], []);

        $this->assertSame([], $result['failures']);
    }

    public function testAnUnlistedDifferenceFailsAndNamesTheHashToAdd(): void
    {
        $result = PortReportChecker::check([$this->differs('old', 'new')], [], [self::FILE], []);

        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString(self::FILE, $result['failures'][0]);
        $this->assertStringContainsString(hash('sha256', "old\0new"), $result['failures'][0], 'the hash to list');
        $this->assertStringContainsString('"old"', $result['failures'][0]);
        $this->assertStringContainsString('"new"', $result['failures'][0]);
    }

    public function testAListedHashPassesAndAnotherDifferenceInTheSameFileFails(): void
    {
        $exceptions = [self::FILE => $this->exception([hash('sha256', "old\0new")])];

        $this->assertSame([], PortReportChecker::check([$this->differs('old', 'new')], $exceptions, [self::FILE], [])['failures']);

        $failures = PortReportChecker::check([$this->differs('old', 'new'), $this->differs('old', 'other')], $exceptions, [self::FILE], [])['failures'];
        $this->assertCount(1, $failures, 'an exception covers the difference it lists, not every difference of the file');
        $this->assertStringContainsString(hash('sha256', "old\0other"), $failures[0]);
    }

    public function testAListedHashNoComparisonProducedIsStale(): void
    {
        $exceptions = [self::FILE => $this->exception([hash('sha256', "old\0new"), hash('sha256', 'never')])];

        $failures = PortReportChecker::check([$this->differs('old', 'new')], $exceptions, [self::FILE], [])['failures'];

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('stale', $failures[0]);
        $this->assertStringContainsString(hash('sha256', 'never'), $failures[0]);
    }

    public function testAnExceptionWithNoHashOrNoComparisonOrAnUnknownTestFails(): void
    {
        $this->assertNotSame([], PortReportChecker::check([$this->same('a')], [self::FILE => $this->exception([])], [self::FILE], [])['failures'], 'no hash');
        $this->assertNotSame([], PortReportChecker::check([], [self::FILE => $this->exception(['abc'])], [self::FILE], [])['failures'], 'never compared');
        $exceptions = [self::FILE => ['reason' => 'why', 'test' => 'Tests\\Unit\\Templating\\PortReportCheckerTest::testNothingLikeThis', 'differences' => [hash('sha256', "old\0new")]]];
        $failures = PortReportChecker::check([$this->differs('old', 'new')], $exceptions, [self::FILE], [])['failures'];
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('does not exist', $failures[0]);
        $exceptions[self::FILE]['reason'] = ' ';
        $exceptions[self::FILE]['test'] = self::class . '::testAComparisonThatMatchesPasses';
        $this->assertNotSame([], PortReportChecker::check([$this->differs('old', 'new')], $exceptions, [self::FILE], [])['failures'], 'no reason');
    }

    public function testAPortedFileNothingComparedFails(): void
    {
        $failures = PortReportChecker::check([$this->same('a')], [], [self::FILE, 'templates/default/y.html.twig'], [])['failures'];

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('templates/default/y.html.twig', $failures[0]);
    }

    public function testAFileThatMustVaryAndNeverDidFailsAndOthersAreOnlyNoticed(): void
    {
        $same = [$this->same('a'), $this->same('a')];

        $result = PortReportChecker::check($same, [], [self::FILE], [self::FILE]);
        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString('never varied', $result['failures'][0]);

        $result = PortReportChecker::check($same, [], [self::FILE], []);
        $this->assertSame([], $result['failures']);
        $this->assertCount(1, $result['notices']);
        $this->assertStringContainsString(self::FILE, $result['notices'][0]);

        $this->assertSame([], PortReportChecker::check([$this->same('a'), $this->same('b')], [], [self::FILE], [self::FILE])['failures']);
        $this->assertSame([], PortReportChecker::check([], [], [], [self::FILE])['failures'], 'a file nothing ported is not required to vary');
    }

    /**
     * @return array<string, mixed>
     */
    private function same(string $output): array
    {
        return ['file' => self::FILE, 'same' => true, 'original_hash' => hash('sha256', $output)];
    }

    /**
     * @return array<string, mixed>
     */
    private function differs(string $original, string $port): array
    {
        return ['file' => self::FILE, 'same' => false, 'original_hash' => hash('sha256', $original), 'hash' => hash('sha256', $original . "\0" . $port), 'original' => $original, 'port' => $port];
    }

    /**
     * @param list<string> $hashes
     *
     * @return array{reason: string, test: string, differences: list<string>}
     */
    private function exception(array $hashes): array
    {
        return ['reason' => 'why', 'test' => self::class . '::testAComparisonThatMatchesPasses', 'differences' => $hashes];
    }
}
